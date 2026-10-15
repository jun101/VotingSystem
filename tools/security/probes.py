#!/usr/bin/env python3
"""Targeted security probes against a running stack (online edition): tenant isolation, authentication,
CSRF, cookie flags, numeric ids, mass assignment and throttling. Standard library only.

   probes.py --base https://vote.test:8443      (VOTE_LOAD_INSECURE=1 and VOTE_LOAD_RESOLVE=name=addr as in vote_load.py)

Registers two throw-away institutions; prints PASS/FAIL per probe and exits 1 if one fails.
"""
import argparse, http.client, json, os, socket, ssl, string, random, sys, urllib.parse

_RESOLVE = dict(i.split('=', 1) for i in os.environ.get('VOTE_LOAD_RESOLVE', '').split(',') if '=' in i)
if _RESOLVE:
    _real = socket.getaddrinfo
    socket.getaddrinfo = lambda h, *a, **k: _real(_RESOLVE.get(h, h), *a, **k)


class Client:
    def __init__(self, base):
        u = urllib.parse.urlparse(base)
        self.host, self.port, self.tls = u.hostname, u.port or (443 if u.scheme == 'https' else 80), u.scheme == 'https'
        self.cookies, self.set_cookie_lines = {}, []

    def request(self, method, path, body=None, csrf=True, headers=None, raw=None):
        ctx = ssl._create_unverified_context() if os.environ.get('VOTE_LOAD_INSECURE') == '1' else None
        c = http.client.HTTPSConnection(self.host, self.port, timeout=30, context=ctx) if self.tls else http.client.HTTPConnection(self.host, self.port, timeout=30)
        h = {'Accept': 'application/json'}
        if self.cookies:
            h['Cookie'] = '; '.join(f'{k}={v}' for k, v in self.cookies.items())
        if csrf and 'XSRF-TOKEN' in self.cookies:
            h['X-XSRF-TOKEN'] = urllib.parse.unquote(self.cookies['XSRF-TOKEN'])
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            h['Content-Type'] = 'application/json'
        if raw is not None:
            data = raw
        h.update(headers or {})
        c.request(method, path, body=data, headers=h)
        r = c.getresponse()
        text = r.read().decode('utf-8', 'replace')
        for k, v in r.getheaders():
            if k.lower() == 'set-cookie':
                self.set_cookie_lines.append(v)
                name, _, rest = v.partition('=')
                self.cookies[name] = rest.split(';', 1)[0]
        try:
            js = json.loads(text) if text else None
        except ValueError:
            js = None
        c.close()
        return r.status, js, text, dict(r.getheaders())


results = []


def check(name, ok, detail=''):
    results.append(ok)
    print(('PASS  ' if ok else 'FAIL  ') + name + (f'   [{detail}]' if detail and not ok else ''))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True)
    a = ap.parse_args()
    api = '/api/v1'
    suffix = ''.join(random.choices(string.ascii_lowercase, k=6))
    pw = 'Un mot de passe long et sûr 42!'

    def signup(tag):
        c = Client(a.base)
        c.request('GET', f'{api}/auth/csrf')
        s, js, t, _ = c.request('POST', f'{api}/auth/register', {
            'institution_name': f'Probe {tag} {suffix}', 'name': f'Probe {tag}', 'email': f'probe.{tag}.{suffix}@vote.test',
            'password': pw, 'language': 'fr'})
        if s != 201:
            sys.exit(f'register failed {s} {t[:200]}')
        return c, js['data']

    A, ua = signup('a')
    B, ub = signup('b')

    # --- cookies
    jar = ' | '.join(A.set_cookie_lines).lower()
    sess = [l for l in A.set_cookie_lines if l.startswith('votesystem_session=')]
    check('session cookie is HttpOnly, Secure, SameSite=Lax', bool(sess) and all(x in sess[-1].lower() for x in ('httponly', 'secure', 'samesite=lax')), sess[-1] if sess else '')
    check('XSRF cookie is Secure and SameSite', any(l.startswith('XSRF-TOKEN=') and 'secure' in l.lower() and 'samesite' in l.lower() for l in A.set_cookie_lines))

    # --- A creates an election
    s, js, t, _ = A.request('POST', f'{api}/elections', {'title': 'Election A', 'language': 'fr', 'timezone': 'America/Port-au-Prince',
                                                      'starts_at': '2030-01-01T08:00:00', 'ends_at': '2030-01-02T08:00:00'})
    if s not in (200, 201):
        # the field names are the endpoint file's; show what the API says
        print('create election ->', s, t[:300])
    eid = (js or {}).get('data', {}).get('id') if isinstance(js, dict) else None
    check('owner A can create an election', eid is not None, f'{s} {t[:150]}')

    if eid:
        for method, path in [('GET', f'/elections/{eid}'), ('PATCH', f'/elections/{eid}'), ('DELETE', f'/elections/{eid}'),
                             ('GET', f'/elections/{eid}/results'), ('GET', f'/elections/{eid}/voters'), ('GET', f'/elections/{eid}/turnout'),
                             ('GET', f'/elections/{eid}/ballots'), ('GET', f'/elections/{eid}/report'), ('GET', f'/elections/{eid}/generated-files'),
                             ('POST', f'/elections/{eid}/close'), ('POST', f'/elections/{eid}/publish'), ('GET', f'/elections/{eid}/credentials')]:
            s, _, t, _ = B.request(method, api + path, {} if method in ('POST', 'PATCH') else None)
            check(f'tenant B: {method} {path.split(eid)[0]}{{election}}{path.split(eid)[1]} answers 404', s == 404, f'{s}')
        s, js, _, _ = B.request('GET', f'{api}/elections')
        check('tenant B list does not show A election', s == 200 and eid not in json.dumps(js))
        s, _, _, _ = A.request('GET', f'{api}/elections/{eid}')
        check('owner A still reads their election', s == 200)

    # --- other tenant's users / institution
    s, js, _, _ = B.request('GET', f'{api}/users')
    check('B users list holds only B', s == 200 and ua['email'] not in json.dumps(js))
    s, js, _, _ = B.request('DELETE', f'{api}/users/{ua["id"]}')
    check('B cannot delete A user (404)', s == 404, str(s))

    # --- numeric ids and malformed
    for m, p in (('GET', '/elections/1'), ('GET', '/elections/0'), ('GET', '/elections/-1'), ('GET', '/elections/1%20OR%201=1'),
                 ('GET', "/elections/'"), ('DELETE', '/users/1'), ('PATCH', '/voters/1'), ('PATCH', '/ballots/1'),
                 ('PATCH', '/candidates/1'), ('PATCH', '/parties/1'), ('PATCH', '/groups/1'), ('GET', '/generated-files/1'),
                 ('GET', '/voter-imports/1'), ('GET', '/public/elections/1/results'), ('GET', '/public/institutions/1')):
        s, _, _, _ = A.request(m, api + p, {} if m == 'PATCH' else None)
        check(f'numeric/malformed id {m} {p} answers 404', s == 404, str(s))

    # --- authentication
    anon = Client(a.base)
    anon.request('GET', f'{api}/auth/csrf')
    for p in ('/elections', '/institution', '/users', '/audit', '/dashboard', '/auth/me'):
        s, _, _, _ = anon.request('GET', api + p)
        check(f'anonymous GET {p} answers 401', s == 401, str(s))
    s, _, _, _ = anon.request('GET', f'{api}/platform/institutions')
    check('anonymous GET /platform/institutions answers 401 or 403', s in (401, 403, 404), str(s))
    s, _, _, _ = A.request('GET', f'{api}/platform/institutions')
    check('an owner cannot use /platform (403 or 404)', s in (403, 404), str(s))

    # --- CSRF
    s, _, _, _ = A.request('PATCH', f'{api}/auth/me', {'language': 'en'}, csrf=False)
    check('state change without CSRF header answers 419', s == 419, str(s))
    s, _, _, _ = A.request('PATCH', f'{api}/auth/me', {'language': 'en'}, csrf=False, headers={'Origin': 'https://evil.example', 'Sec-Fetch-Site': 'cross-site'})
    check('cross-site change without token answers 419', s == 419, str(s))
    s, _, _, _ = A.request('PATCH', f'{api}/auth/me', {'language': 'en'}, headers={'X-XSRF-TOKEN': 'wrong'})
    check('wrong CSRF token answers 419', s == 419, str(s))

    # --- mass assignment: role and institution cannot be set through the profile
    s, js, _, _ = A.request('PATCH', f'{api}/auth/me', {'language': 'fr', 'role': 'platform_admin', 'email': 'x@evil.test', 'institution_id': 1, 'must_change_password': False})
    s2, me, _, _ = A.request('GET', f'{api}/auth/me')
    check('profile update ignores role, email and institution', s2 == 200 and me['data']['role'] == 'owner' and me['data']['email'] == ua['email'], json.dumps(me)[:160])

    # --- headers
    s, _, _, h = A.request('GET', f'{api}/auth/me')
    hl = {k.lower(): v for k, v in h.items()}
    check('API answer is no-store', 'no-store' in hl.get('cache-control', ''))
    check('API sends nosniff and no-referrer', hl.get('x-content-type-options') == 'nosniff' and hl.get('referrer-policy') == 'no-referrer')
    check('no server or powered-by banner', 'server' not in hl and 'x-powered-by' not in hl, str({k: v for k, v in hl.items() if k in ('server', 'x-powered-by')}))
    s, _, t, _ = anon.request('GET', f'{api}/elections', headers={'Accept': 'text/html'})
    check('error body is JSON, no stack trace', 'Stack trace' not in t and 'vendor/' not in t and '.php' not in t)
    s, _, t, _ = anon.request('GET', f'{api}/nothing-here')
    check('unknown route answers 404 without a trace', s == 404 and '.php' not in t, str(s))
    for m in ('TRACE', 'PUT'):
        s, _, _, _ = anon.request(m, f'{api}/health')
        check(f'{m} on /health answers 405', s == 405, str(s))

    # --- login throttle (last: it uses the address counter)
    t429 = False
    for i in range(14):
        s, _, _, _ = anon.request('POST', f'{api}/auth/login', {'email': f'nobody{i}@vote.test', 'password': 'x' * 14})
        if s == 429:
            t429 = True
            break
    check('repeated failed sign-ins end in 429', t429)

    bad = results.count(False)
    print(f'\n{len(results) - bad} passed, {bad} failed')
    sys.exit(1 if bad else 0)


main()
