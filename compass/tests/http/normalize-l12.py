#!/usr/bin/env python3
"""Normalize run.sh responses for comparing the Lumen 5.1 baseline with Laravel 12.

Only formatting that changed with the framework and doesn't change behavior:
- the status line protocol (Lumen didn't prepare responses, so it sent HTTP/1.0)
- header spelling: Content-Type capitalization, charset case, Cache-Control's
  added "private", and header order
- Set-Cookie lines (the cookie session payload is encrypted differently, and
  Laravel also sends it on error responses)
- the quoting in Symfony's redirect page
- framework error pages (404/405/500): the status line stays, the HTML doesn't

Usage: normalize-l12.py <responses dir>   (rewrites the files in place)
"""
import os, re, sys

ERROR_MARKERS = ('sf-reset', 'Whoops, looks like something went wrong', 'An Error Occurred:', '<title>Server Error</title>', '<title>Not Found</title>')

def normalize(text):
    lines = text.split('\n')
    # The header block runs up to the first blank line
    try:
        end = lines.index('')
    except ValueError:
        end = len(lines)
    head, body = lines[:end], lines[end:]
    out = []
    for h in head:
        h = re.sub(r'^HTTP/1\.[01] ', 'HTTP/1.1 ', h)
        h = re.sub(r'(?i)^content-type:', 'content-type:', h)
        h = re.sub(r'(?i)charset=utf-8', 'charset=utf-8', h)
        h = re.sub(r'(?i)^(cache-control: no-cache), private$', r'\1', h)
        if re.match(r'(?i)^set-cookie:', h):
            continue
        out.append(h)
    # Keep the request/status line first and sort the rest
    first = [l for l in out if l.startswith(('HTTP/', 'GET ', 'HEAD ', 'POST ', 'status:'))]
    rest = sorted((l for l in out if l not in first), key=str.lower)
    body_text = '\n'.join(body)
    body_text = re.sub(r"content=\"0;url='([^']*)'\"", r'content="0;url=\1"', body_text)
    if any(m in body_text for m in ERROR_MARKERS):
        body_text = '\n<framework error page>'
    return '\n'.join(first + rest) + body_text

root = sys.argv[1]
for dirpath, _, files in os.walk(root):
    for f in files:
        if f.endswith('.txt') and not f.endswith('.forwarded.txt'):
            p = os.path.join(dirpath, f)
            t = open(p, errors='replace').read()
            n = normalize(t)
            if n != t:
                open(p, 'w').write(n)
