#!/usr/bin/env python3
"""The vote flow through the API of an installed school edition, from inside the machine (standard library only).

   vm-flow.py CREDENTIALS_FILE [BASE_URL]

Signs in as the owner with the one-time password, changes it, creates an election with a ballot, two candidates and a
voter, schedules it, waits for the scheduler to open it, votes, replays the submit, closes, reads the results. Prints
PASS or FAIL for each step and exits 1 on any FAIL.
"""
import http.cookiejar, json, re, sys, time, urllib.error, urllib.parse, urllib.request
from datetime import datetime, timedelta, timezone

cred_file = sys.argv[1]
base = (sys.argv[2] if len(sys.argv) > 2 else 'http://127.0.0.1').rstrip('/') + '/api/v1'
failed = 0


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def token(self):
        for c in self.jar:
            if c.name == 'XSRF-TOKEN':
                return urllib.parse.unquote(c.value)
        return ''

    def call(self, method, path, body=None, ok=(200, 201)):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(base + path, data=data, method=method)
        req.add_header('Accept', 'application/json')
        req.add_header('X-XSRF-TOKEN', self.token())
        if data is not None:
            req.add_header('Content-Type', 'application/json')
        try:
            with self.opener.open(req, timeout=30) as r:
                status, text = r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            status, text = e.code, e.read().decode()
        if status not in ok:
            raise RuntimeError(f'{method} {path} -> {status} {text[:200]}')
        return status, (json.loads(text) if text else {})


def step(name, fn):
    global failed
    try:
        value = fn()
        print('PASS ', name, flush=True)
        return value
    except Exception as e:  # noqa: BLE001 - a test report
        failed += 1
        print('FAIL ', name, ':', e, flush=True)
        return None


text = open(cred_file, encoding='utf-8').read()
m = re.search(r'password\s*:\s*(\S+)', text, re.I)
password = m.group(1)
new_password = 'un mot de passe bien long 2026'
owner = Client()
voter = Client()

step('csrf cookie', lambda: owner.opener.open(base + '/auth/csrf', timeout=30).read())


def login():
    try:
        owner.call('POST', '/auth/login', {'email': 'directeur@ecole.local', 'password': password})
        owner.call('POST', '/auth/change-password', {'current_password': password, 'password': new_password})
    except RuntimeError:
        owner.call('POST', '/auth/login', {'email': 'directeur@ecole.local', 'password': new_password})


step('sign in and change the one-time password', login)
step('elections list', lambda: owner.call('GET', '/elections'))

now = datetime.now(timezone.utc)
starts = (now + timedelta(seconds=150)).strftime('%Y-%m-%dT%H:%M:%SZ')
ends = (now + timedelta(days=2)).strftime('%Y-%m-%dT%H:%M:%SZ')
election = step('create an election', lambda: owner.call('POST', '/elections', {'title': 'Conseil des eleves', 'starts_at': starts, 'ends_at': ends})[1]['data'])
ballot = step('create a ballot', lambda: owner.call('POST', f"/elections/{election['id']}/ballots", {'title': 'President', 'seats': 1, 'allow_blank': True})[1]['data'])
step('candidate 1', lambda: owner.call('POST', f"/ballots/{ballot['id']}/candidates", {'first_name': 'Nadege', 'last_name': 'Pierre-Louis', 'sex': 'female'}))
step('candidate 2', lambda: owner.call('POST', f"/ballots/{ballot['id']}/candidates", {'first_name': 'Jean-Marc', 'last_name': 'Desir', 'sex': 'male'}))
person = step('create a voter', lambda: owner.call('POST', f"/elections/{election['id']}/voters", {'full_name': 'Wideline Joseph'})[1]['data'])
code = step('issue the code', lambda: owner.call('POST', f"/voters/{person['id']}/credential", {})[1]['data']['code'])
step('schedule', lambda: owner.call('POST', f"/elections/{election['id']}/schedule", {'accept_warnings': True}))


def wait_open():
    for _ in range(100):
        phase = voter.call('GET', f"/vote/{election['id']}")[1]['data']['phase']
        if phase == 'open':
            return
        time.sleep(3)
    raise RuntimeError(f'still {phase}')


step('the scheduler opens the election', wait_open)


def session():
    voter.opener.open(base + '/auth/csrf', timeout=30).read()
    voter.call('POST', f"/vote/{election['id']}/session", {'code': code})


step('voter session with the code', session)
ballots = step('ballots of the voter', lambda: voter.call('GET', f"/vote/{election['id']}/ballots")[1]['data']['ballots'])
body = {'ballots': [{'ballot': ballots[0]['id'], 'candidates': [ballots[0]['candidates'][0]['id']]}]} if ballots else None
first = step('submit the vote', lambda: voter.call('POST', f"/vote/{election['id']}/submit", body, ok=(201,)))
step('a replayed submit is not a second vote', lambda: voter.call('POST', f"/vote/{election['id']}/submit", body, ok=(200, 201)))
step('close the election', lambda: owner.call('POST', f"/elections/{election['id']}/close", {'confirmation': 'Conseil des eleves'}))


def results():
    r = owner.call('GET', f"/elections/{election['id']}/results")[1]['data']['ballots'][0]
    if not (r['cast'] == 1 and r['eligible'] == 1 and r['check']['ok']):
        raise RuntimeError(f'unexpected results: {r}')


step('results: one vote, check ok', results)
print('FLOW', 'FAILED' if failed else 'OK', flush=True)
sys.exit(1 if failed else 0)
