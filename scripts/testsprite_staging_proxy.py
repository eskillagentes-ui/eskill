#!/usr/bin/env python3
"""Proxy concorrente :8877 → :8080 com Host staging, sem seguir redirects.

urllib.urlopen segue 302 e perde Set-Cookie do login — por isso usamos http.client.
"""

from __future__ import annotations

import http.client
import http.server
import os
import socketserver
import sys
from urllib.parse import urlsplit

PORT = int(os.environ.get("STAGING_PROXY_PORT", "8877"))
UPSTREAM_HOST = os.environ.get("STAGING_PROXY_UPSTREAM_HOST", "127.0.0.1")
UPSTREAM_PORT = int(os.environ.get("STAGING_PROXY_UPSTREAM_PORT", "8080"))
HOST_HEADER = os.environ.get("STAGING_PROXY_HOST", "staging.eskill.com.br")
PID_FILE = os.environ.get("STAGING_PROXY_PID", "/tmp/testsprite-staging-proxy.pid")
HOP_BY_HOP = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailers",
    "transfer-encoding",
    "upgrade",
    "content-length",
    "host",
    "accept-encoding",
}


class StagingProxyHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _proxy(self) -> None:
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else None

        headers: list[tuple[str, str]] = [("Host", HOST_HEADER)]
        for key, value in self.headers.items():
            kl = key.lower()
            if kl in HOP_BY_HOP or kl in {"referer", "origin"}:
                continue
            headers.append((key, value))

        origin = f"http://{HOST_HEADER}"
        headers.append(("Origin", origin))
        referer = self.headers.get("Referer") or f"{origin}/"
        referer = (
            referer.replace(f"http://127.0.0.1:{PORT}", origin)
            .replace(f"http://localhost:{PORT}", origin)
        )
        headers.append(("Referer", referer))
        if body is not None:
            headers.append(("Content-Length", str(len(body))))

        conn = http.client.HTTPConnection(UPSTREAM_HOST, UPSTREAM_PORT, timeout=180)
        try:
            conn.request(self.command, self.path, body=body, headers=dict(headers))
            resp = conn.getresponse()
            resp_body = resp.read()
            self.send_response(resp.status, resp.reason)
            for key, value in resp.getheaders():
                kl = key.lower()
                if kl in HOP_BY_HOP:
                    continue
                if kl == "location":
                    value = self._rewrite_location(value)
                if kl == "set-cookie":
                    # Drop Secure so browsers/tunnel on HTTP keep the session cookie.
                    value = value.replace("; Secure", "").replace("; secure", "")
                self.send_header(key, value)
            self.send_header("Content-Length", str(len(resp_body)))
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(resp_body)
        except Exception as exc:  # noqa: BLE001
            msg = str(exc).encode()
            self.send_response(502)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.send_header("Content-Length", str(len(msg)))
            self.end_headers()
            self.wfile.write(msg)
        finally:
            conn.close()

    def _rewrite_location(self, location: str) -> str:
        parts = urlsplit(location)
        if parts.netloc in {HOST_HEADER, f"{HOST_HEADER}:80", f"{HOST_HEADER}:443"}:
            return f"http://127.0.0.1:{PORT}{parts.path}" + (
                f"?{parts.query}" if parts.query else ""
            ) + (f"#{parts.fragment}" if parts.fragment else "")
        if location.startswith("/"):
            return location
        return location

    def do_GET(self) -> None:  # noqa: N802
        self._proxy()

    def do_POST(self) -> None:  # noqa: N802
        self._proxy()

    def do_PUT(self) -> None:  # noqa: N802
        self._proxy()

    def do_PATCH(self) -> None:  # noqa: N802
        self._proxy()

    def do_DELETE(self) -> None:  # noqa: N802
        self._proxy()

    def do_OPTIONS(self) -> None:  # noqa: N802
        self._proxy()

    def do_HEAD(self) -> None:  # noqa: N802
        self._proxy()

    def log_message(self, fmt: str, *args: object) -> None:
        sys.stderr.write(f"[staging-proxy] {fmt % args}\n")


def main() -> int:
    socketserver.ThreadingTCPServer.allow_reuse_address = True
    httpd = socketserver.ThreadingTCPServer(("0.0.0.0", PORT), StagingProxyHandler)
    with open(PID_FILE, "w", encoding="utf-8") as handle:
        handle.write(str(os.getpid()))
    print(
        f"staging-proxy listening on {PORT} → {UPSTREAM_HOST}:{UPSTREAM_PORT} "
        f"Host={HOST_HEADER} (no redirect follow)",
        flush=True,
    )
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("staging-proxy stopped", flush=True)
    finally:
        try:
            os.remove(PID_FILE)
        except OSError:
            pass
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
