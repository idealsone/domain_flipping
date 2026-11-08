"""A tiny SMTP server for tests: accepts AUTH, records every message, refuses addresses that contain "bounce" (550)
and logins whose password is "wrong" (535). No real mail leaves the machine."""
import base64, socket, socketserver, threading


class _Handler(socketserver.StreamRequestHandler):
    def handle(self):
        srv = self.server; w = lambda s: self.wfile.write((s + "\r\n").encode())
        w("220 fake.smtp ESMTP"); mail = {"from": "", "to": [], "user": "", "data": ""}
        while True:
            line = self.rfile.readline()
            if not line: return
            cmd = line.decode("utf8", "ignore").strip(); up = cmd.upper()
            if up.startswith(("EHLO", "HELO")): w("250-fake.smtp"); w("250-AUTH PLAIN LOGIN"); w("250 8BITMIME")
            elif up.startswith("AUTH PLAIN"):
                arg = cmd.split(" ", 2)[2] if cmd.count(" ") >= 2 else None
                if arg is None: w("334 "); arg = self.rfile.readline().decode().strip()
                parts = base64.b64decode(arg).split(b"\0"); mail["user"] = parts[1].decode(); pw = parts[2].decode()
                w("535 5.7.8 Authentication failed" if pw == "wrong" else "235 2.7.0 OK")
            elif up.startswith("AUTH LOGIN"):
                w("334 VXNlcm5hbWU6"); mail["user"] = base64.b64decode(self.rfile.readline().strip()).decode()
                w("334 UGFzc3dvcmQ6"); pw = base64.b64decode(self.rfile.readline().strip()).decode()
                w("535 5.7.8 Authentication failed" if pw == "wrong" else "235 2.7.0 OK")
            elif up.startswith("MAIL FROM"): mail.update({"from": cmd[10:].strip(" <>"), "to": [], "data": ""}); w("250 OK")
            elif up.startswith("RCPT TO"):
                to = cmd[8:].strip(" <>")
                if "bounce" in to: w("550 5.1.1 <%s>: Recipient address rejected: User unknown" % to)
                else: mail["to"].append(to); w("250 OK")
            elif up == "DATA":
                w("354 End data with <CR><LF>.<CR><LF>"); buf = []
                while True:
                    l = self.rfile.readline()
                    if l in (b".\r\n", b".\n", b""): break
                    buf.append(l.decode("utf8", "ignore"))
                mail["data"] = "".join(buf)
                with srv.lock: srv.messages.append(dict(mail))
                w("250 OK queued")
            elif up == "RSET": w("250 OK")
            elif up == "NOOP": w("250 OK")
            elif up == "QUIT": w("221 Bye"); return
            else: w("502 Not implemented")


class FakeSmtp(socketserver.ThreadingMixIn, socketserver.TCPServer):
    daemon_threads = True; allow_reuse_address = True

    def __init__(self):
        super().__init__(("127.0.0.1", 0), _Handler); self.messages = []; self.lock = threading.Lock()
        self.port = self.server_address[1]; threading.Thread(target=self.serve_forever, daemon=True).start()

    def stop(self): self.shutdown(); self.server_close()
