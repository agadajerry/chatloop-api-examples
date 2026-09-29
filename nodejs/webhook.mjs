// Single-host teaching example: receiver and worker share a private inbox directory.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const inbox = path.resolve(process.env.CHATLOOP_INBOX || './chatloop-inbox');
const channel = process.env.CHATLOOP_CHANNEL_ID;
const token = process.env.CHATLOOP_WEBHOOK_TOKEN;
if (!channel || !/^[a-f0-9]{64}$/.test(token || '')) throw new Error('Set channel ID and a random 64-hex webhook token.');
fs.mkdirSync(inbox, { recursive: true, mode: 0o700 });

function store(message) {
  const id = crypto.createHash('sha256').update(channel + ':' + message.id).digest('hex');
  const destination = path.join(inbox, id + '.json');
  const temporary = path.join(inbox, crypto.randomUUID() + '.tmp');
  fs.writeFileSync(temporary, JSON.stringify(message), { mode: 0o600 });
  try { fs.linkSync(temporary, destination); } // Atomic, never overwrites a duplicate.
  catch (error) { if (error.code !== 'EEXIST') throw error; }
  finally { fs.unlinkSync(temporary); }
}

if (process.argv.includes('--worker')) {
  if (!process.env.CHATLOOP_API_KEY) throw new Error('Set the API key for CHATLOOP_CHANNEL_ID.');
  for (const file of fs.readdirSync(inbox).filter(name => name.endsWith('.json'))) {
    const full = path.join(inbox, file);
    try { fs.closeSync(fs.openSync(full + '.claimed', 'wx', 0o600)); }
    catch (error) { if (error.code === 'EEXIST') continue; throw error; }
    // A retained claim prevents automatic resends after a crash or uncertain result.
    const message = JSON.parse(fs.readFileSync(full, 'utf8'));
    const match = /^([1-9][0-9]{6,14})@s\.whatsapp\.net$/.exec(message.chat_id || '');
    if (message.from_me !== false || message.type !== 'text' || !match ||
        typeof message.body?.text !== 'string' || message.body.text.trim().toUpperCase() !== 'HELP') {
      fs.writeFileSync(full + '.result', 'ignored'); continue;
    }
    const result = spawnSync(process.execPath, [fileURLToPath(new URL('./send-message.mjs', import.meta.url))], {
      env: { ...process.env, CHATLOOP_TO: match[1], CHATLOOP_TEXT: 'Thanks for your reply. A support agent can help you here.' },
      stdio: 'ignore', timeout: 35000,
    });
    fs.writeFileSync(full + '.result', result.status === 0 ? 'accepted' : 'needs-review');
  }
} else {
  http.createServer(async (req, res) => {
    const respond = status => { res.writeHead(status); res.end(); };
    if (req.url !== '/webhooks/chatloop/' + token) return respond(404);
    if (req.method !== 'POST') return respond(405);
    if (!req.headers['content-type']?.startsWith('application/json')) return respond(415);
    let bytes = 0; const chunks = [];
    try {
      for await (const chunk of req) {
        bytes += chunk.length;
        if (bytes > 1048576) return respond(413);
        chunks.push(chunk);
      }
      let payload;
      try { payload = JSON.parse(Buffer.concat(chunks).toString('utf8')); }
      catch { return respond(400); }
      if (!payload || typeof payload !== 'object') return respond(400);
      if (payload.channel_id !== channel) return respond(403);
      if (payload.event?.type !== 'messages' || payload.event?.event !== 'post') return respond(204);
      if (!Array.isArray(payload.messages) || payload.messages.some(m => !m || typeof m.id !== 'string' || !m.id)) return respond(400);
      for (const message of payload.messages) store(message);
      respond(204); // Saving completed before acknowledging; no outbound call here.
    } catch { respond(503); } // Storage/transient failure: let the relay retry.
  }).listen(Number(process.env.PORT || 8080), '127.0.0.1', () => console.log('Receiver on localhost; expose through an HTTPS reverse proxy.'));
}
