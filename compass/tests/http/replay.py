#!/usr/bin/env python3
"""Record read-only HTTP responses from a running app, and compare two recordings.

Point it at the old stack and the new stack (e.g. two nginx vhosts, or
`php -S` servers on different ports), both using the same copy of the
production database, then compare the recordings.

  replay.py record --base http://127.0.0.1:8072 --requests reqs.txt --out baseline/ \
      [--header 'Authorization: Bearer TOKEN']...
  replay.py compare baseline/ upgraded/

Requests file, one per line (blank lines and lines starting with # are skipped):

  /microsub/1?action=channels
  GET /microsub/1?action=timeline&channel=abc
  GET /api/thing | Authorization: Bearer other-token | Accept: application/json

Only GET and HEAD are allowed, so a recording can never modify data.
compare masks per-request values before comparing: CSRF tokens by default,
plus any --ignore REGEX you pass (e.g. timestamps, nonces).
With --all-headers every response header is recorded (sorted, names
lowercased) except Date and any --drop-header; use it when the port touches
the router, emitter or session handling, since those change headers.
JSON bodies are re-indented (key order preserved) so diffs are readable;
everything else is saved byte for byte.
"""
import argparse, json, os, re, statistics, sys, time, urllib.error, urllib.request


def parse_line(line):
    parts = [p.strip() for p in line.split(' | ')]
    first = parts[0].split(None, 1)
    if len(first) == 2 and first[0].isupper():
        method, path = first
    else:
        method, path = 'GET', parts[0]
    if method not in ('GET', 'HEAD'):
        sys.exit(f'refusing non-read-only request: {line}')
    headers = {}
    for h in parts[1:]:
        k, _, v = h.partition(':')
        headers[k.strip()] = v.strip()
    return method, path, headers


def slug(i, method, path):
    s = re.sub(r'[^A-Za-z0-9]+', '-', path).strip('-')[:80]
    return f'{i:04d}-{method}-{s}'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def record(args):
    os.makedirs(args.out, exist_ok=True)
    global_headers = {}
    for h in args.header or []:
        k, _, v = h.partition(':')
        global_headers[k.strip()] = v.strip()
    opener = urllib.request.build_opener(NoRedirect)
    timings = []
    with open(args.requests) as f:
        lines = [l.rstrip('\n') for l in f if l.strip() and not l.lstrip().startswith('#')]
    for i, line in enumerate(lines):
        method, path, headers = parse_line(line)
        req = urllib.request.Request(args.base.rstrip('/') + path, method=method,
                                     headers={**global_headers, **headers})
        start = time.monotonic()
        try:
            resp = opener.open(req, timeout=args.timeout)
            status, body, hdrs = resp.status, resp.read(), resp.headers
        except urllib.error.HTTPError as e:
            status, body, hdrs = e.code, e.read(), e.headers
        ctype, location = hdrs.get('Content-Type', ''), hdrs.get('Location', '')
        ms = round((time.monotonic() - start) * 1000, 1)
        timings.append({'request': line, 'status': status, 'ms': ms})
        try:
            text = json.dumps(json.loads(body), indent=2, ensure_ascii=False) + '\n'
            body = text.encode()
        except ValueError:
            pass
        head = f'{method} {path}\nstatus: {status}\n'
        if args.all_headers:
            drop = {'date'} | {h.lower() for h in args.drop_header or []}
            for k, v in sorted((k.lower(), v) for k, v in hdrs.items()):
                if k not in drop:
                    head += f'{k}: {v}\n'
        else:
            head += f'content-type: {ctype}\n'
            if location:
                head += f'location: {location}\n'
        with open(os.path.join(args.out, slug(i, method, path) + '.txt'), 'wb') as out:
            out.write(head.encode() + b'\n' + body)
    with open(os.path.join(args.out, '_timings.json'), 'w') as f:
        json.dump(timings, f, indent=2)
    summarize(args.out, timings)


def summarize(label, timings):
    ms = sorted(t['ms'] for t in timings)
    if not ms:
        print(f'{label}: no requests')
        return
    non2xx = sum(1 for t in timings if not 200 <= t['status'] < 300)
    p95 = ms[min(len(ms) - 1, int(len(ms) * 0.95))]
    print(f'{label}: requests={len(ms)} non2xx={non2xx} p50={statistics.median(ms):.1f}ms '
          f'p95={p95:.1f}ms max={ms[-1]:.1f}ms total={sum(ms):.0f}ms')


# Values that legitimately change on every request
DEFAULT_IGNORE = [
    rb'(<meta name="csrf-token" content=")[^"]*',
    rb'(name="_token" value=")[^"]*',
    rb'("_token":\s*")[^"]*',
]


def masked(data, patterns):
    for p in patterns:
        data = p.sub(lambda m: (m.group(1) if m.groups() else b'') + b'<masked>', data)
    return data


def compare(args):
    patterns = [re.compile(p) for p in DEFAULT_IGNORE] + [re.compile(p.encode()) for p in args.ignore or []]
    a_files = {f for f in os.listdir(args.a) if f.endswith('.txt')}
    b_files = {f for f in os.listdir(args.b) if f.endswith('.txt')}
    only_a, only_b = sorted(a_files - b_files), sorted(b_files - a_files)
    differ = []
    for f in sorted(a_files & b_files):
        with open(os.path.join(args.a, f), 'rb') as fa, open(os.path.join(args.b, f), 'rb') as fb:
            if masked(fa.read(), patterns) != masked(fb.read(), patterns):
                differ.append(f)
    total = len(a_files & b_files)
    print(f'compared {total} responses: {total - len(differ)} identical, {len(differ)} different')
    for f in only_a:
        print(f'  only in {args.a}: {f}')
    for f in only_b:
        print(f'  only in {args.b}: {f}')
    for f in differ[:args.show]:
        print(f'  DIFF {f}   (diff -u {os.path.join(args.a, f)} {os.path.join(args.b, f)})')
    if len(differ) > args.show:
        print(f'  ... and {len(differ) - args.show} more')
    for d in (args.a, args.b):
        p = os.path.join(d, '_timings.json')
        if os.path.exists(p):
            with open(p) as f:
                summarize(d, json.load(f))
    sys.exit(1 if differ or only_a or only_b else 0)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    r = sub.add_parser('record')
    r.add_argument('--base', required=True)
    r.add_argument('--requests', required=True)
    r.add_argument('--out', required=True)
    r.add_argument('--header', action='append')
    r.add_argument('--timeout', type=float, default=60)
    r.add_argument('--all-headers', action='store_true', help='record every response header except Date')
    r.add_argument('--drop-header', action='append', help='header to leave out with --all-headers (repeatable)')
    c = sub.add_parser('compare')
    c.add_argument('a')
    c.add_argument('b')
    c.add_argument('--show', type=int, default=20)
    c.add_argument('--ignore', action='append', help='regex for volatile values; a capture group 1 is kept as a prefix')
    args = ap.parse_args()
    record(args) if args.cmd == 'record' else compare(args)


if __name__ == '__main__':
    main()
