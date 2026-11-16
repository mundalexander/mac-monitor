#!/usr/bin/env python3
"""Test-Agent: läuft einfach weiter, bis er beendet wird.  fake_agent.py TAG"""
import sys, time
print("fake agent", sys.argv[1:], flush=True)
while True:
    time.sleep(1)
