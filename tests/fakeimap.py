"""Minimal IMAP server for tests: accepts any login, one empty INBOX."""
import re
import socketserver
import sys


class Handler(socketserver.StreamRequestHandler):
    def w(self, line):
        self.wfile.write((line + "\r\n").encode())

    def handle(self):
        self.w("* OK [CAPABILITY IMAP4rev1 LITERAL+ NAMESPACE] test server ready")
        while True:
            line = self.rfile.readline()
            if not line:
                return
            line = line.decode(errors="replace").rstrip("\r\n")
            literal = re.search(r"\{(\d+)(\+?)\}$", line)
            while literal:  # consume literals, e.g. passwords
                if not literal.group(2):
                    self.w("+ go ahead")
                self.rfile.read(int(literal.group(1)))
                rest = self.rfile.readline().decode(errors="replace").rstrip("\r\n")
                line = line[:literal.start()] + '"x"' + rest
                literal = re.search(r"\{(\d+)(\+?)\}$", line)
            parts = line.split(" ", 2)
            if len(parts) < 2:
                continue
            tag, cmd = parts[0], parts[1].upper()
            if cmd == "CAPABILITY":
                self.w("* CAPABILITY IMAP4rev1 LITERAL+ NAMESPACE")
            elif cmd == "NAMESPACE":
                self.w('* NAMESPACE (("" "/")) NIL NIL')
            elif cmd in ("LIST", "LSUB"):
                self.w('* %s (\\HasNoChildren) "/" INBOX' % cmd)
            elif cmd in ("SELECT", "EXAMINE"):
                self.w("* 0 EXISTS")
                self.w("* OK [UIDVALIDITY 1] ok")
            elif cmd == "LOGOUT":
                self.w("* BYE")
                self.w(tag + " OK")
                return
            self.w(tag + " OK done")


socketserver.ThreadingTCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()
