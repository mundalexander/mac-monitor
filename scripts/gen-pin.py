#!/usr/bin/env python3
"""PIN-Hash für server/secrets.php erzeugen – ohne PHP (Windows, macOS, Linux).

    python3 scripts/gen-pin.py            # PIN wird verdeckt abgefragt

Format identisch zu server/tools/gen-pin.php (PBKDF2-SHA256, 150 000 Runden).
"""
import getpass
import hashlib
import os
import re
import sys

pin = sys.argv[1] if len(sys.argv) > 1 else getpass.getpass("Neue PIN (6-8 Ziffern): ")
if not re.fullmatch(r"\d{6,8}", pin):
    sys.exit("PIN muss aus 6-8 Ziffern bestehen.")
if len(sys.argv) == 1 and getpass.getpass("PIN wiederholen: ") != pin:
    sys.exit("PINs stimmen nicht überein.")
salt = os.urandom(16)
iterations = 150000
digest = hashlib.pbkdf2_hmac("sha256", pin.encode(), salt, iterations, 32)
print("In server/secrets.php eintragen:\n")
print("<?php")
print(f"define('LOGIN_PIN_HASH', 'pbkdf2$sha256${iterations}${salt.hex()}${digest.hex()}');")
