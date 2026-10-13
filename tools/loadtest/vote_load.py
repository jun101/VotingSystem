#!/usr/bin/env python3
"""Load test of the voting flow (NFR-PERF-02: 500 voters vote within a minute, no vote lost).

   vote_load.py [--base URL] [--voters 500] [--seconds 60] [--threads 100] [--burst] [--report FILE]

It registers a throw-away institution through the API (online edition) or signs in with --email/--password (school
edition, after the one-time password was changed), creates an election with one ballot of three candidates, N voters and
their codes, schedules it, waits for the scheduler to open it, then makes every voter go through the real flow (session,
ballots, submit) with their arrival spread over --seconds (or all at once with --burst). Afterwards it closes the election
and checks the results against what it sent: every vote counted, the right tallies, the bulletin check ok. Standard library
only (and `pdftotext` for the codes). Run it against a stack whose AUTH_RATE_LIMIT_FACTOR is high enough for one address (the dev stack's is), or from
several addresses: the per-address limits are part of what it must not be stopped by.
"""
import argparse, http.client, json, random, re, statistics, string, subprocess, sys, threading, time, urllib.parse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone


class Session:
    """One HTTP client: a connection, its cookies, the CSRF header."""

    def __init__(self, base):
        u = urllib.parse.urlparse(base)
        self.host, self.port, self.tls = u.hostname, u.port or (443 if u.scheme == 'https' else 80), u.scheme == 'https'
        self.cookies = {}
        self.conn = None

    def _connect(self):
        cls = http.client.HTTPSConnection if self.tls else http.client.HTTPConnection
        self.conn = cls(self.host, self.port, timeout=60)

    def request(self, method, path, body=None):
        headers = {'Accept': 'application/json'}
        if self.cookies:
            headers['Cookie'] = '; '.join(f'{k}={v}' for k, v in self.cookies.items())
        token = self.cookies.get('XSRF-TOKEN')
        if token:
            headers['X-XSRF-TOKEN'] = urllib.parse.unquote(token)
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers['Content-Type'] = 'application/json'
        for attempt in (1, 2):
            try:
                if self.conn is None:
                    self._connect()
                t0 = time.perf_counter()
                self.conn.request(method, path, body=data, headers=headers)
                r = self.conn.getresponse()
                raw = r.read()
                dt = time.perf_counter() - t0
                break
            except (http.client.HTTPException, OSError):
                self.conn = None
                if attempt == 2:
                    raise
        for h in r.headers.get_all('Set-Cookie') or []:
            name, _, rest = h.partition('=')
            value = rest.split(';', 1)[0]
            if 'expires=Thu, 01 Jan 1970' in h or 'Max-Age=0' in h:
                self.cookies.pop(name, None)
            else:
                self.cookies[name] = value
        try:
            payload = json.loads(raw) if raw else {}
        except ValueError:
            payload = {}
        return r.status, payload, dt


def pct(values, p):
    if not values:
        return 0.0
    s = sorted(values)
    return s[min(len(s) - 1, int(round(p / 100 * (len(s) - 1))))]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://localhost:8080')
    ap.add_argument('--voters', type=int, default=500)
    ap.add_argument('--seconds', type=float, default=60)
    ap.add_argument('--threads', type=int, default=100)
    ap.add_argument('--burst', action='store_true', help='all voters at once (the peak)')
    ap.add_argument('--email')
    ap.add_argument('--password')
    ap.add_argument('--report')
    a = ap.parse_args()
    api = '/api/v1'
    owner = Session(a.base)
    owner.request('GET', f'{api}/auth/csrf')

    def need(res, ok, what):
        status, payload, _ = res
        if status not in ok:
            sys.exit(f'setup failed: {what} -> {status} {json.dumps(payload)[:300]}')
        return payload

    if a.email:
        need(owner.request('POST', f'{api}/auth/login', {'email': a.email, 'password': a.password}), (200,), 'login')
    else:
        tag = ''.join(random.choices(string.ascii_lowercase + string.digits, k=8))
        need(owner.request('POST', f'{api}/auth/register', {
            'institution_name': f'Charge {tag}', 'name': 'Load Test', 'email': f'load.{tag}@example.test',
            'password': 'un mot de passe long et sûr'}), (201,), 'register')

    # ---- setup: election, ballot, candidates, voters, codes
    t_setup = time.perf_counter()
    now = datetime.now(timezone.utc)
    starts = (now + timedelta(seconds=120 + a.voters / 8)).strftime('%Y-%m-%dT%H:%M:%SZ')
    ends = (now + timedelta(days=2)).strftime('%Y-%m-%dT%H:%M:%SZ')
    election = need(owner.request('POST', f'{api}/elections', {'title': f'Charge {a.voters}', 'starts_at': starts, 'ends_at': ends}), (201,), 'election')['data']
    eid = election['id']
    ballot = need(owner.request('POST', f'{api}/elections/{eid}/ballots', {'title': 'Poste', 'seats': 1, 'allow_blank': True}), (201,), 'ballot')['data']
    candidates = []
    for first, last in (('Ana', 'Alpha'), ('Ben', 'Bravo'), ('Cléa', 'Charlie')):
        c = need(owner.request('POST', f'{api}/ballots/{ballot["id"]}/candidates', {'first_name': first, 'last_name': last, 'sex': 'female'}), (201,), 'candidate')['data']
        candidates.append(c['id'])

    # The voters come the way an organiser brings them: one CSV file, checked, then confirmed.
    csv_lines = ['nom_complet,groupe,identifiant,courriel,telephone'] + [f'Electeur {i:04d},Classe {i % 10},,,' for i in range(a.voters)]
    boundary = '----vsload' + ''.join(random.choices(string.hexdigits, k=16))
    payload = ('--%s\r\nContent-Disposition: form-data; name="file"; filename="electeurs.csv"\r\nContent-Type: text/csv\r\n\r\n%s\r\n--%s--\r\n'
               % (boundary, '\n'.join(csv_lines), boundary)).encode()
    conn = http.client.HTTPConnection(owner.host, owner.port, timeout=120)
    token = urllib.parse.unquote(owner.cookies.get('XSRF-TOKEN', ''))
    conn.request('POST', f'{api}/elections/{eid}/voter-imports', body=payload, headers={
        'Cookie': '; '.join(f'{k}={v}' for k, v in owner.cookies.items()), 'X-XSRF-TOKEN': token, 'Accept': 'application/json',
        'Content-Type': f'multipart/form-data; boundary={boundary}'})
    r = conn.getresponse()
    body = json.loads(r.read() or b'{}')
    if r.status != 202:
        sys.exit(f'setup failed: import upload -> {r.status} {json.dumps(body)[:300]}')
    imp = body['data']['id']
    for want, label in (('checked', 'check'), ('done', 'import')):
        if want == 'done':
            need(owner.request('POST', f'{api}/voter-imports/{imp}/confirm', {}), (202,), 'import confirm')
        for _ in range(300):
            info = need(owner.request('GET', f'{api}/voter-imports/{imp}'), (200,), 'import status')['data']
            if info['status'] == want:
                break
            if info['status'] == 'failed':
                sys.exit(f'setup failed: import {label} failed: {info.get("failure")}')
            time.sleep(1)
        else:
            sys.exit(f'setup failed: import {label} timed out')
    if info['imported_rows'] != a.voters:
        sys.exit(f'setup failed: imported {info["imported_rows"]} voters of {a.voters}')

    # The codes come the way an organiser gets them: one run, the slips as PDF, read back with pdftotext.
    run = need(owner.request('POST', f'{api}/elections/{eid}/credential-runs', {'scope': 'all'}), (202,), 'credential run')['data']
    codes = {}
    code_re = re.compile(r'\b[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}\b')
    for f in run['files']:
        for _ in range(180):
            info = need(owner.request('GET', f'{api}/generated-files/{f["id"]}'), (200,), 'file status')['data']
            if info['status'] == 'ready':
                break
            if info['status'] == 'failed':
                sys.exit('setup failed: slips file failed')
            time.sleep(1)
        conn = http.client.HTTPConnection(owner.host, owner.port, timeout=120) if not owner.tls else http.client.HTTPSConnection(owner.host, owner.port, timeout=120)
        conn.request('GET', f'{api}/generated-files/{f["id"]}/download', headers={'Cookie': '; '.join(f'{k}={v}' for k, v in owner.cookies.items()), 'Accept': 'application/pdf'})
        r = conn.getresponse()
        pdf = r.read()
        if r.status != 200:
            sys.exit(f'setup failed: slips download -> {r.status}')
        text = subprocess.run(['pdftotext', '-raw', '-', '-'], input=pdf, capture_output=True, check=True).stdout.decode('utf-8', 'replace')
        name = None
        for line in text.splitlines():
            m = re.search(r'Electeur (\d{4})', line)
            if m:
                name = int(m.group(1))
                continue
            c = code_re.search(line)
            if c and name is not None:
                codes[name] = c.group(0)
                name = None
    if len(codes) != a.voters:
        sys.exit(f'setup failed: read {len(codes)} codes from the slips, expected {a.voters}')
    need(owner.request('POST', f'{api}/elections/{eid}/schedule', {'accept_warnings': True}), (200,), 'schedule')
    print(f'setup: {a.voters} voters with codes in {time.perf_counter() - t_setup:.1f} s; waiting for the election to open', flush=True)
    probe = Session(a.base)
    while True:
        st, body, _ = probe.request('GET', f'{api}/vote/{eid}')
        if st == 200 and body['data']['phase'] == 'open':
            break
        time.sleep(2)

    # ---- the vote
    lock = threading.Lock()
    lat = {'session': [], 'ballots': [], 'submit': []}
    errors = []
    voted = {}
    started = time.perf_counter()

    def voter(i):
        delay = 0 if a.burst else random.uniform(0, a.seconds)
        time.sleep(max(0, delay - (time.perf_counter() - started)))
        s = Session(a.base)
        s.request('GET', f'{api}/auth/csrf')
        t0 = time.perf_counter()
        st, body, dt = s.request('POST', f'{api}/vote/{eid}/session', {'code': codes[i]})
        with lock:
            lat['session'].append(dt)
        if st != 201:
            with lock:
                errors.append(('session', i, st, json.dumps(body)[:120]))
            return
        st, body, dt = s.request('GET', f'{api}/vote/{eid}/ballots')
        with lock:
            lat['ballots'].append(dt)
        if st != 200:
            with lock:
                errors.append(('ballots', i, st, json.dumps(body)[:120]))
            return
        b = body['data']['ballots'][0]
        pick = b['candidates'][i % len(b['candidates'])]['id']
        st, body, dt = s.request('POST', f'{api}/vote/{eid}/submit', {'ballots': [{'ballot': b['id'], 'candidates': [pick]}]})
        with lock:
            lat['submit'].append(dt)
            if st == 201:
                voted[i] = pick
            else:
                errors.append(('submit', i, st, json.dumps(body)[:120]))

    with ThreadPoolExecutor(a.threads) as pool:
        list(pool.map(voter, range(a.voters)))
    elapsed = time.perf_counter() - started

    # ---- close and check
    need(owner.request('POST', f'{api}/elections/{eid}/close', {'confirmation': election['title']}), (200,), 'close')
    res = need(owner.request('GET', f'{api}/elections/{eid}/results'), (200,), 'results')['data']['ballots'][0]
    expected = {}
    for pick in voted.values():
        expected[pick] = expected.get(pick, 0) + 1
    counted = {c['id']: c['votes'] for c in res['candidates']}
    wrong = {k: (expected.get(k, 0), counted.get(k, 0)) for k in set(expected) | set(counted) if expected.get(k, 0) != counted.get(k, 0)}
    ok = (not errors and len(voted) == a.voters and res['cast'] == a.voters and res['check']['ok'] and not wrong)

    def stat(name):
        v = lat[name]
        return {'n': len(v), 'p50_ms': round(pct(v, 50) * 1000), 'p95_ms': round(pct(v, 95) * 1000), 'p99_ms': round(pct(v, 99) * 1000), 'max_ms': round(max(v) * 1000) if v else 0}

    report = {
        'voters': a.voters, 'mode': 'burst' if a.burst else f'spread over {a.seconds:.0f} s', 'threads': a.threads,
        'wall_seconds': round(elapsed, 1), 'votes_accepted': len(voted), 'errors': len(errors), 'first_errors': errors[:5],
        'results': {'cast': res['cast'], 'eligible': res['eligible'], 'blank': res['blank'], 'check_ok': res['check']['ok'], 'tally_mismatch': wrong},
        'latency': {k: stat(k) for k in lat}, 'verdict': 'PASS' if ok else 'FAIL',
    }
    print(json.dumps(report, indent=2, ensure_ascii=False))
    if a.report:
        open(a.report, 'w').write(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    sys.exit(0 if ok else 1)


main()
