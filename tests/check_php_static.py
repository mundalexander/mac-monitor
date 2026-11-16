#!/usr/bin/env python3
"""PHP-Prüfung ohne PHP-Laufzeit: Syntax (tree-sitter) und Auflösung aller
Projekt-Funktionen/-Konstanten. Optional: pip install tree-sitter tree-sitter-php
In CI läuft zusätzlich `php -l` und der echte Ende-zu-Ende-Test (tests/php/run.sh)."""
import glob, os, re, sys
try:
    import tree_sitter_php as tsp
    from tree_sitter import Language, Parser
except ImportError:
    print("tree-sitter-php nicht installiert – übersprungen."); sys.exit(0)
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "server")
parser = Parser(Language(tsp.language_php()))
files = sorted(glob.glob(ROOT + "/*.php") + glob.glob(ROOT + "/tools/*.php"))
defs, calls, consts, uses, bad = set(), [], set(), [], 0
for f in files:
    src = open(f, "rb").read(); tree = parser.parse(src); errs = []
    def walk(n):
        if n.type == "ERROR" or n.is_missing: errs.append(n.start_point[0] + 1)
        if n.type == "function_definition":
            nm = n.child_by_field_name("name"); defs.add(src[nm.start_byte:nm.end_byte].decode())
        if n.type == "function_call_expression":
            fn = n.child_by_field_name("function")
            if fn is not None and fn.type == "name":
                calls.append((src[fn.start_byte:fn.end_byte].decode(), f, n.start_point[0] + 1))
        if n.type == "const_element":
            consts.add(src[n.children[0].start_byte:n.children[0].end_byte].decode())
        for c in n.children: walk(c)
    walk(tree.root_node)
    consts.update(m.decode() for m in re.findall(rb"define\('([A-Z_]+)'", src))
    uses += [(m.decode(), f) for m in re.findall(rb"\b(MM_[A-Z_]+|DB_PATH|SERVERS|RETENTION_HOURS)\b", src)]
    name = os.path.relpath(f, ROOT)
    if errs: bad += 1; print(f"ERR  {name}: Syntax um Zeile {errs[:3]}")
    else: print(f"OK   {name}")
prefixes = ("mm_", "json_response", "db", "slots_db", "ingest_", "board_", "autonomy_", "resolve_server_id")
for nm, f, line in calls:
    if nm.startswith(prefixes) and nm not in defs:
        bad += 1; print(f"ERR  {os.path.relpath(f, ROOT)}:{line}: Funktion {nm}() nicht definiert")
for c, f in uses:
    if c not in consts:
        bad += 1; print(f"ERR  {os.path.relpath(f, ROOT)}: Konstante {c} nicht definiert")
print(f"\n{len(files)} Dateien, {len(defs)} Funktionen, {bad} Fehler")
sys.exit(1 if bad else 0)
