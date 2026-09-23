"""TCP relay for Claude preview sessions.

The Laravel app always runs inside Docker (`make up`) and itself occupies
APP_PORT from this checkout's .env (8800 in the main checkout), so the
preview server cannot bind that port directly.
This relay listens on the preview-assigned PORT and forwards every
connection to the running app.
"""

import os
import socket
import socketserver
import threading


def app_port() -> int:
    """APP_PORT of this checkout: a git worktree runs its own stack on its own port."""
    if os.environ.get("APP_PORT"):
        return int(os.environ["APP_PORT"])
    env_file = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), ".env")
    try:
        with open(env_file, encoding="utf-8") as env:
            for line in env:
                if line.startswith("APP_PORT="):
                    return int(line.split("=", 1)[1].strip().strip("\"'"))
    except (OSError, ValueError):
        pass
    return 8800


LISTEN_PORT = int(os.environ.get("PORT", "8899"))
TARGET = ("127.0.0.1", app_port())


class Relay(socketserver.BaseRequestHandler):
    def handle(self):
        try:
            upstream = socket.create_connection(TARGET)
        except OSError:
            return

        def pump(src, dst):
            try:
                while chunk := src.recv(65536):
                    dst.sendall(chunk)
            except OSError:
                pass
            finally:
                for s in (src, dst):
                    try:
                        s.shutdown(socket.SHUT_RDWR)
                    except OSError:
                        pass

        t = threading.Thread(target=pump, args=(upstream, self.request), daemon=True)
        t.start()
        pump(self.request, upstream)
        t.join()


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    print(f"relay listening on {LISTEN_PORT} -> {TARGET[0]}:{TARGET[1]}", flush=True)
    Server(("127.0.0.1", LISTEN_PORT), Relay).serve_forever()
