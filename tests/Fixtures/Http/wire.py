"""Isolated loopback wire evidence. Never connects outside this fixture's listeners."""
import base64
import hashlib
import json
import os
import select
import socket
import socketserver
import ssl
import struct
import sys
import threading
import time
import urllib.parse

root, certificates = sys.argv[1:]
lock = threading.Lock()
ports = {}


def evidence(kind, **fields):
    with lock:
        with open(root + '/events.jsonl', 'a') as stream:
            stream.write(json.dumps(dict(kind=kind, **fields)) + '\n')
            stream.flush()
            os.fsync(stream.fileno())


def exact(sock, count):
    result = b''
    while len(result) < count:
        chunk = sock.recv(count - len(result))
        if not chunk:
            raise EOFError()
        result += chunk
    return result


def head(sock):
    result = b''
    while not result.endswith(b'\r\n\r\n'):
        result += exact(sock, 1)
        if len(result) > 65536:
            raise ValueError('header limit')
    lines = result.decode('iso-8859-1').split('\r\n')
    headers = dict((k.lower(), v.strip()) for k, v in (line.split(':', 1) for line in lines[1:] if ':' in line))
    return result, lines[0], headers


def destination(host, port):
    if host not in ('127.0.0.1', '::1') or port not in [ports[key] for key in ('http', 'tls', 'wrong', 'expired', 'untrusted')]:
        raise ValueError('fixture denies remote DNS or non-fixture destination')
    return socket.create_connection((host, port), 2)


def relay(left, right):
    with right:
        while True:
            pending = [left] if isinstance(left, ssl.SSLSocket) and left.pending() else select.select([left, right], [], [], 3)[0]
            if not pending:
                return
            for source in pending:
                chunk = source.recv(65536)
                if not chunk:
                    return
                (right if source is left else left).sendall(chunk)


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def handle_error(self, request, address):
        pass


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        sock = self.request
        sock.settimeout(4)
        evidence('connection', listener=self.server.mode)
        try:
            if self.server.context:
                sock = self.server.context.wrap_socket(sock, server_side=True)
            with sock:
                self.dispatch(sock)
        except (OSError, EOFError, ValueError, ssl.SSLError):
            pass

    def dispatch(self, sock):
        mode = self.server.mode
        if mode == 'socks':
            version, count = exact(sock, 2)
            methods = exact(sock, count)
            sock.sendall(b'\x05\x00')
            version, command, reserved, atyp = exact(sock, 4)
            # Domain requests are recorded and refused rather than resolved.
            host = socket.inet_ntop(socket.AF_INET, exact(sock, 4)) if atyp == 1 else socket.inet_ntop(socket.AF_INET6, exact(sock, 16)) if atyp == 4 else exact(sock, exact(sock, 1)[0]).decode()
            port = struct.unpack('!H', exact(sock, 2))[0]
            evidence('socks', host=host, port=port, atyp=atyp)
            if atyp not in (1, 4):
                return
            upstream = destination(host, port)
            sock.sendall(b'\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00')
            relay(sock, upstream)
            return
        raw, request, headers = head(sock)
        method, target, version = request.split(' ')
        if mode in ('proxy', 'proxy_tls', 'auth'):
            evidence('proxy', request=request, bytes=len(raw), auth_ok=headers.get('proxy-authorization') == 'Basic ' + base64.b64encode(b'user@/%:p:a@ss/%?').decode())
            if mode == 'auth' and headers.get('proxy-authorization') != 'Basic ' + base64.b64encode(b'user@/%:p:a@ss/%?').decode():
                sock.sendall(b'HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm="fixture"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
                return
            if method == 'CONNECT':
                parsed = urllib.parse.urlsplit('http://' + target)
                upstream = destination(parsed.hostname, parsed.port)
                evidence('connect', target=target)
                sock.sendall(b'HTTP/1.1 200 Connection Established\r\n\r\n')
                relay(sock, upstream)
                return
            parsed = urllib.parse.urlsplit(target)
            upstream = destination(parsed.hostname, parsed.port or 80)
            path = parsed.path or '/'
            if parsed.query:
                path += '?' + parsed.query
            forwarded = f'{method} {path} {version}\r\n'.encode()
            forwarded += b''.join(f'{name}: {value}\r\n'.encode() for name, value in headers.items() if name != 'proxy-authorization') + b'\r\n'
            upstream.sendall(forwarded)
            relay(sock, upstream)
            return
        body = exact(sock, int(headers.get('content-length', 0)))
        evidence('origin', request=request, headers=headers, body_hash=hashlib.sha256(body).hexdigest(), body_length=len(body), bytes=len(raw) + len(body))
        path = urllib.parse.urlsplit(target).path
        if path == '/drop':
            return
        if path == '/stall':
            time.sleep(2)
        if path == '/truncated':
            sock.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: 200\r\n\r\nshort')
            return
        if path == '/redirect':
            sock.sendall(b'HTTP/1.1 302 Found\r\nLocation: /ok\r\nContent-Length: 0\r\n\r\n')
            return
        if path == '/late':
            while not os.path.isfile(root + '/release'):
                time.sleep(0.01)
        payload = b'x' * 2048 if path == '/large' else b'fixture-response'
        code = path.strip('/') if path in ('/401', '/403', '/404', '/429', '/503') else '200'
        sock.sendall(f'HTTP/1.1 {code} Fixture\r\nContent-Length: {len(payload)}\r\nContent-Type: application/octet-stream\r\nSet-Cookie: secret=fixture\r\nConnection: close\r\n\r\n'.encode() + payload)


def tls_context(name, kind):
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(certificates + '/' + name + '.pem', certificates + '/fixture.key')
    ctx.set_servername_callback(lambda sock, name, ctx: evidence('sni', name=name, listener=kind))
    return ctx


servers = []
for name, mode, certificate in [('http', 'origin', None), ('tls', 'origin', 'origin'), ('wrong', 'origin', 'wrong'), ('expired', 'origin', 'expired'), ('untrusted', 'origin', 'untrusted'), ('proxy', 'proxy', None), ('proxy_tls', 'proxy_tls', 'proxy'), ('proxy_wrong', 'proxy_tls', 'wrong'), ('proxy_expired', 'proxy_tls', 'expired'), ('auth', 'auth', None), ('socks', 'socks', None)]:
    server = Server(('127.0.0.1', 0), Handler)
    server.mode = mode
    server.context = tls_context(certificate, name) if certificate else None
    ports[name] = server.server_address[1]
    servers.append(server)
for server in servers:
    threading.Thread(target=server.serve_forever, daemon=True).start()
with socket.socket() as refused:
    refused.bind(('127.0.0.1', 0))
    ports['refused'] = refused.getsockname()[1]
with open(root + '/ports.json', 'w') as stream:
    json.dump(ports, stream)
print('ready', flush=True)
threading.Event().wait()
