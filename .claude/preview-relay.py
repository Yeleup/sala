"""TCP relay for Claude preview sessions.

The Laravel app always runs inside Docker (`make up`) and itself occupies
APP_PORT (8800), so the preview server cannot bind that port directly.
This relay listens on the preview-assigned PORT and forwards every
connection to the running app.
"""

import os
import socket
import socketserver
import threading

LISTEN_PORT = int(os.environ.get("PORT", "8899"))
TARGET = ("127.0.0.1", int(os.environ.get("APP_PORT", "8800")))


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
