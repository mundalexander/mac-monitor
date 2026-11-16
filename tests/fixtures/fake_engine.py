#!/usr/bin/env python3
"""Test-Engine: OpenAI-kompatibler Mini-Server (Health, Models, Chat).
   fake_engine.py PORT [--load-seconds N] [--alloc-mb M] [--fail] [--name NAME]"""
import json, sys, time
from http.server import BaseHTTPRequestHandler, HTTPServer

args = sys.argv[1:]
port = int(args[0])
load = float(args[args.index("--load-seconds") + 1]) if "--load-seconds" in args else 0
alloc = int(args[args.index("--alloc-mb") + 1]) if "--alloc-mb" in args else 0
name = args[args.index("--name") + 1] if "--name" in args else "fake"
if "--fail" in args:
    print("fake engine: simulierter Absturz", flush=True)
    sys.exit(3)
ballast = bytearray(alloc * 1024 * 1024)
for i in range(0, len(ballast), 4096):
    ballast[i] = 1
t0 = time.time()

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def _j(self, code, body):
        raw = json.dumps(body).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw))); self.end_headers(); self.wfile.write(raw)
    def do_GET(self):
        if self.path == "/health":
            return self._j(200 if time.time() - t0 >= load else 503, {"status": "ok"})
        if self.path == "/v1/models":
            return self._j(200, {"data": [{"id": name}]})
        self._j(404, {})
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0); self.rfile.read(n)
        time.sleep(0.2)
        self._j(200, {"choices": [{"message": {"content": "1 2 3"}}], "usage": {"completion_tokens": 20},
                      "timings": {"predicted_per_second": 42.5}})

print(f"fake engine {name} on {port}", flush=True)
HTTPServer(("127.0.0.1", port), H).serve_forever()
