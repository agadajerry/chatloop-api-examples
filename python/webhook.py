"""Single-host teaching example; standard library only. Keep the inbox private."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from http.server import BaseHTTPRequestHandler, HTTPServer

INBOX = Path(os.environ.get('CHATLOOP_INBOX', './chatloop-inbox')).resolve()
CHANNEL = os.environ.get('CHATLOOP_CHANNEL_ID')
TOKEN = os.environ.get('CHATLOOP_WEBHOOK_TOKEN', '')
if not CHANNEL or not re.fullmatch(r'[a-f0-9]{64}', TOKEN):
    raise SystemExit('Set channel ID and a random 64-hex webhook token.')
INBOX.mkdir(mode=0o700, parents=True, exist_ok=True)


def store(message):
    identifier = hashlib.sha256((CHANNEL + ':' + message['id']).encode()).hexdigest()
    fd, temporary = tempfile.mkstemp(dir=INBOX)
    try:
        with os.fdopen(fd, 'w') as output:
            json.dump(message, output)
        try:
            os.link(temporary, INBOX / (identifier + '.json'))
        except FileExistsError:
            pass
    finally:
        os.unlink(temporary)


class Receiver(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass  # Do not log the secret URL or message contents.

    def respond(self, status):
        self.send_response(status)
        self.send_header('Content-Length', '0')
        self.send_header('Connection', 'close')
        self.end_headers()

    def do_POST(self):
        if self.path != '/webhooks/chatloop/' + TOKEN:
            return self.respond(404)
        if not self.headers.get('Content-Type', '').startswith('application/json'):
            return self.respond(415)
        try:
            size = int(self.headers.get('Content-Length', '-1'))
            if size < 0 or size > 1048576:
                return self.respond(413)
            payload = json.loads(self.rfile.read(size))
        except (ValueError, UnicodeDecodeError):
            return self.respond(400)
        if not isinstance(payload, dict):
            return self.respond(400)
        if payload.get('channel_id') != CHANNEL:
            return self.respond(403)
        if payload.get('event') != {'type': 'messages', 'event': 'post'}:
            return self.respond(204)
        messages = payload.get('messages')
        if not isinstance(messages, list) or any(not isinstance(m, dict) or not isinstance(m.get('id'), str) or not m['id'] for m in messages):
            return self.respond(400)
        try:
            for message in messages:
                store(message)
        except OSError:
            return self.respond(503)
        self.respond(204)


def worker():
    if not os.environ.get('CHATLOOP_API_KEY'):
        raise SystemExit('Set the API key for CHATLOOP_CHANNEL_ID.')
    for file in INBOX.glob('*.json'):
        try:
            with open(str(file) + '.claimed', 'x'):
                pass
        except FileExistsError:
            continue
        # Claims remain on failure: review before any manual resend.
        message = json.loads(file.read_text())
        match = re.fullmatch(r'([1-9][0-9]{6,14})@s\.whatsapp\.net', str(message.get('chat_id', '')))
        body = message.get('body')
        text = body.get('text') if isinstance(body, dict) else None
        outcome = 'ignored'
        if message.get('from_me') is False and message.get('type') == 'text' and match and isinstance(text, str) and text.strip().upper() == 'HELP':
            env = dict(os.environ, CHATLOOP_TO=match[1], CHATLOOP_TEXT='Thanks for your reply. A support agent can help you here.')
            try:
                result = subprocess.run([sys.executable, str(Path(__file__).with_name('send_message.py'))], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=35)
                outcome = 'accepted' if result.returncode == 0 else 'needs-review'
            except (subprocess.TimeoutExpired, OSError):
                outcome = 'needs-review'
        Path(str(file) + '.result').write_text(outcome)


if __name__ == '__main__':
    if '--worker' in sys.argv:
        worker()
    else:
        print('Receiver on localhost; expose through an HTTPS reverse proxy.')
        HTTPServer(('127.0.0.1', int(os.environ.get('PORT', '8080'))), Receiver).serve_forever()
