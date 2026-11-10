"""Minimal Voyage AI client (embeddings + rerank) for the build side.

Key: ~/.config/library-connector/voyage.key ; base URL: ~/.config/library-connector/voyage.base
(https://api.voyageai.com/v1 for dash.voyageai.com keys, https://ai.mongodb.com/v1 for Atlas keys).
"""
from __future__ import annotations

import base64
import threading
import time
from collections import deque
from pathlib import Path

import numpy as np
import requests

CONF = Path.home() / '.config/library-connector'
DEFAULT_BASE = 'https://api.voyageai.com/v1'


class VoyageError(RuntimeError):
    pass


def load_credentials() -> tuple[str, str]:
    key_file = CONF / 'voyage.key'
    if not key_file.exists():
        raise VoyageError(f'{key_file} is missing: run setup-access.sh first')
    key = key_file.read_text(encoding='utf-8').strip()
    base_file = CONF / 'voyage.base'
    base = base_file.read_text(encoding='utf-8').strip() if base_file.exists() else DEFAULT_BASE
    return key, base.rstrip('/')


class RateGate:
    """Keeps requests under tokens-per-minute and requests-per-minute limits."""

    def __init__(self, tpm: int, rpm: int):
        self.tpm, self.rpm = tpm, rpm
        self.events: deque = deque()     # (time, tokens)
        self.lock = threading.Lock()

    def wait(self, tokens: int):
        while True:
            with self.lock:
                now = time.time()
                while self.events and now - self.events[0][0] > 60:
                    self.events.popleft()
                used = sum(t for _, t in self.events)
                if len(self.events) < self.rpm and used + tokens <= self.tpm:
                    self.events.append((now, tokens))
                    return
                wait = 60 - (now - self.events[0][0]) + 0.05 if self.events else 1
            time.sleep(min(max(wait, 0.2), 5))


class Voyage:
    def __init__(self, key: str | None = None, base: str | None = None, tpm: int = 15_000_000,
                 rpm: int = 1_800, timeout: int = 120):
        if key is None or base is None:
            k, b = load_credentials()
            key, base = key or k, base or b
        self.base = base.rstrip('/')
        self.session = requests.Session()
        self.session.headers.update({'Authorization': f'Bearer {key}', 'Content-Type': 'application/json'})
        self.gate = RateGate(tpm, rpm)
        self.timeout = timeout
        self.tokens_used = 0
        self.failed_tokens = 0      # estimated tokens of attempts sent without a usable answer
        self.stop: threading.Event | None = None   # set: no new attempt is sent
        self._lock = threading.Lock()

    def _post(self, path: str, payload: dict, est_tokens: int) -> dict:
        delay = 2.0
        for attempt in range(8):
            if self.stop is not None and self.stop.is_set():
                raise VoyageError('stopped before sending')
            self.gate.wait(est_tokens)
            try:
                r = self.session.post(f'{self.base}{path}', json=payload, timeout=self.timeout)
            except requests.RequestException as exc:
                err = f'network error: {exc.__class__.__name__}'
            else:
                if r.status_code == 200:
                    try:
                        data = r.json()
                    except ValueError:
                        data = None
                    if isinstance(data, dict):
                        with self._lock:
                            self.tokens_used += int(data.get('usage', {}).get('total_tokens', 0))
                        return data
                    err = 'HTTP 200 with a body that is not a JSON object'
                else:
                    err = f'HTTP {r.status_code}: {r.text[:300]!r}'
                    if r.status_code not in (429, 500, 502, 503, 504):
                        raise VoyageError(err)
                    ra = r.headers.get('Retry-After', '')
                    if ra.isdecimal():
                        delay = max(delay, min(float(ra), 120.0))
            # The attempt may have been billed (a timeout after Voyage did the work): count it.
            with self._lock:
                self.failed_tokens += est_tokens
            time.sleep(delay)
            delay = min(delay * 2, 60)
        raise VoyageError(f'giving up after retries: {err}')

    def embed(self, texts: list[str], model: str, input_type: str, dims: int) -> tuple[np.ndarray, int]:
        """Float32 embeddings (n x dims), unit length, plus tokens billed."""
        est = sum(len(t) for t in texts) // 3 + 10
        data = self._post('/embeddings', {
            'input': texts, 'model': model, 'input_type': input_type,
            'output_dimension': dims, 'output_dtype': 'float', 'encoding_format': 'base64',
            'truncation': True,
        }, est)
        rows = sorted(data['data'], key=lambda d: d['index'])
        out = np.stack([np.frombuffer(base64.b64decode(d['embedding']), dtype='<f4') for d in rows])
        if out.shape != (len(texts), dims):
            raise VoyageError(f'unexpected embedding shape {out.shape}')
        return out, int(data.get('usage', {}).get('total_tokens', 0))

    def rerank(self, query: str, documents: list[str], model: str, top_k: int | None = None):
        est = (len(query) // 3 + 5) * len(documents) + sum(len(d) for d in documents) // 3
        data = self._post('/rerank', {'query': query, 'documents': documents, 'model': model,
                                      'top_k': top_k, 'truncation': True}, est)
        return [(d['index'], d['relevance_score']) for d in data['data']]
