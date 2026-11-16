#!/usr/bin/env python3
"""Syntaxprüfung aller JavaScript-Teile: assets/*.js und Inline-<script> in PHP-Seiten.
PHP-Ausdrücke innerhalb von Skripten werden durch Platzhalter ersetzt. Benötigt Node.js."""
import re, subprocess, sys, tempfile, glob, os
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
bad = 0
files = sorted(glob.glob(os.path.join(ROOT, 'server/assets/*.js')))
for f in files:
    r = subprocess.run(['node', '--check', f], capture_output=True, text=True)
    print(('OK  ' if r.returncode == 0 else 'ERR ') + os.path.relpath(f, ROOT))
    if r.returncode: bad += 1; print(r.stderr[-400:])
for f in sorted(glob.glob(os.path.join(ROOT, 'server/*.php'))):
    src = open(f, encoding='utf-8').read()
    blocks = re.findall(r'<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>', src, flags=re.S)
    for i, js in enumerate(blocks):
        js = re.sub(r'<\?=.*?\?>', '0', js, flags=re.S)
        js = re.sub(r'<\?php\s+(echo|print)\b.*?\?>', '0', js, flags=re.S)
        js = re.sub(r'<\?php.*?\?>', '', js, flags=re.S)
        with tempfile.NamedTemporaryFile('w', suffix='.js', delete=False) as t:
            t.write(js)
        r = subprocess.run(['node', '--check', t.name], capture_output=True, text=True)
        os.unlink(t.name)
        name = f'{os.path.relpath(f, ROOT)} <script #{i + 1}>'
        print(('OK  ' if r.returncode == 0 else 'ERR ') + name)
        if r.returncode: bad += 1; print(r.stderr[-600:])
print(f'\n{bad} Fehler')
sys.exit(1 if bad else 0)
