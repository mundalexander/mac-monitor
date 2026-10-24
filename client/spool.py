"""Lokale Warteschlange: kein Datenverlust bei Server- oder Netzausfall."""
from __future__ import annotations

import json
import os
import tempfile
import threading
from pathlib import Path


class Spool:
    def __init__(self, path: str, max_items: int = 2000) -> None:
        self.path = Path(os.path.expanduser(path))
        self.max_items = max_items
        self._lock = threading.Lock()
        self.path.parent.mkdir(parents=True, exist_ok=True)

    def _read(self) -> list[dict]:
        if not self.path.is_file():
            return []
        items: list[dict] = []
        try:
            with self.path.open("r", encoding="utf-8", errors="ignore") as fh:
                for line in fh:
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        items.append(json.loads(line))
                    except json.JSONDecodeError:
                        continue
        except OSError:
            return []
        return items

    def _write(self, items: list[dict]) -> None:
        items = items[-self.max_items:]
        try:
            fd, tmp = tempfile.mkstemp(dir=str(self.path.parent), suffix=".tmp")
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                for it in items:
                    fh.write(json.dumps(it, ensure_ascii=False) + "\n")
            os.replace(tmp, self.path)
        except OSError:
            pass

    def add(self, item: dict) -> None:
        with self._lock:
            items = self._read()
            items.append(item)
            self._write(items)

    def peek_all(self) -> list[dict]:
        with self._lock:
            return self._read()

    def remove_first(self, count: int) -> None:
        with self._lock:
            items = self._read()
            self._write(items[count:])

    def clear(self) -> None:
        with self._lock:
            self._write([])

    def __len__(self) -> int:
        return len(self.peek_all())
