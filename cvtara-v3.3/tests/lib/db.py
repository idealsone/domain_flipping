import sqlite3


class Db:
    """Read/write access to the throw-away test database."""
    def __init__(self, path): self.path = path

    def q(self, sql, *a):
        con = sqlite3.connect(self.path)
        try:
            cur = con.execute(sql, a); rows = cur.fetchall(); con.commit(); return rows
        finally: con.close()
