"""A tiny IMAP server for tests: one INBOX, LOGIN (password "wrong" is refused), EXAMINE, UID SEARCH (ALL, SINCE, UID n:*),
UID FETCH BODY.PEEK[]<0.n> and LOGOUT. It records every command so tests can check that nothing was stored or deleted."""
import socketserver, threading


class _Handler(socketserver.StreamRequestHandler):
    def handle(self):
        srv = self.server; w = lambda s: self.wfile.write(s if isinstance(s, bytes) else (s + "\r\n").encode())
        w("* OK fake IMAP ready")
        while True:
            line = self.rfile.readline()
            if not line: return
            cmd = line.decode("utf8", "ignore").rstrip("\r\n"); tag, _, rest = cmd.partition(" "); up = rest.upper()
            with srv.lock: srv.commands.append(rest.split(" ")[0].upper() + (" " + rest.split(" ")[1].upper() if up.startswith("UID ") else ""))
            if up.startswith("LOGIN"):
                if '"wrong"' in rest: w(f"{tag} NO [AUTHENTICATIONFAILED] Invalid credentials")
                else: srv.logins.append(rest); w(f"{tag} OK LOGIN completed")
            elif up.startswith("EXAMINE") or up.startswith("SELECT"):
                with srv.lock: n = max(srv.mail) + 1 if srv.mail else 1
                w(f"* {len(srv.mail)} EXISTS"); w(f"* OK [UIDVALIDITY 7] ok"); w(f"* OK [UIDNEXT {n}] ok"); w(f"{tag} OK [READ-ONLY] EXAMINE completed")
            elif up.startswith("UID SEARCH"):
                crit = rest[11:].strip().upper()
                with srv.lock: uids = sorted(srv.mail)
                if crit.startswith("UID "):
                    lo = int(crit[4:].split(":")[0]); uids = [u for u in uids if u >= lo] or ([max(srv.mail)] if srv.mail else [])  # like real servers, n:* returns the last one
                w("* SEARCH " + " ".join(map(str, uids))); w(f"{tag} OK SEARCH completed")
            elif up.startswith("UID FETCH"):
                uid = int(rest.split(" ")[2]); raw = srv.mail.get(uid, "").replace("\n", "\r\n").encode()
                w(f"* 1 FETCH (UID {uid} BODY[]<0> {{{len(raw)}}}".encode() + b"\r\n" + raw + b")\r\n"); w(f"{tag} OK FETCH completed")
            elif up.startswith("LOGOUT"): w("* BYE"); w(f"{tag} OK LOGOUT completed"); return
            else: w(f"{tag} BAD not supported here")


class FakeImap(socketserver.ThreadingMixIn, socketserver.TCPServer):
    daemon_threads = True; allow_reuse_address = True

    def __init__(self):
        super().__init__(("127.0.0.1", 0), _Handler); self.mail = {}; self.commands = []; self.logins = []; self.lock = threading.Lock()
        self.port = self.server_address[1]; threading.Thread(target=self.serve_forever, daemon=True).start()

    def add(self, raw):
        with self.lock: uid = max(self.mail) + 1 if self.mail else 1; self.mail[uid] = raw
        return uid

    def stop(self): self.shutdown(); self.server_close()
