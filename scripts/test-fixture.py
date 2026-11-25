#!/usr/bin/env python3
import http.server
import socket
import socketserver
import ssl
import threading


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        body = b'proxyma-fixture-v1'
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


class Proxy(socketserver.StreamRequestHandler):
    def handle(self):
        line = self.rfile.readline(8192).decode('ascii', 'replace').strip()
        while self.rfile.readline(8192).strip():
            pass
        if line != 'CONNECT fixture:8443 HTTP/1.1':
            self.wfile.write(b'HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n')
            return
        with socket.create_connection(('fixture', 8443), timeout=3) as upstream:
            self.wfile.write(b'HTTP/1.1 200 Connection Established\r\n\r\n')
            import select
            while True:
                ready, _, _ = select.select([self.connection, upstream], [], [], 5)
                if not ready:
                    break
                for source in ready:
                    data = source.recv(65536)
                    if not data:
                        return
                    (upstream if source is self.connection else self.connection).sendall(data)


http_server = http.server.ThreadingHTTPServer(('0.0.0.0', 8080), Handler)
https = http.server.ThreadingHTTPServer(('0.0.0.0', 8443), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/work/scripts/test-fixture-cert.pem', '/work/scripts/test-fixture-key.pem')
https.socket = context.wrap_socket(https.socket, server_side=True)
proxy = socketserver.ThreadingTCPServer(('0.0.0.0', 8888), Proxy)
for server in (http_server, https):
    threading.Thread(target=server.serve_forever, daemon=True).start()
proxy.serve_forever()
