// Node.js 22+. Run on your server, never in a browser.
const key = process.env.CHATLOOP_API_KEY;
const to = process.env.CHATLOOP_TO;
const text = process.env.CHATLOOP_TEXT || 'Your order is ready for collection.';
if (!key || !to || !/^[1-9]\d{6,14}$/.test(to)) {
  console.error('Set CHATLOOP_API_KEY and CHATLOOP_TO (country code + digits, without +).');
  process.exit(1);
}
try {
  const response = await fetch('https://api.chatloophq.com/api/send/text', {
    method: 'POST',
    headers: { 'X-API-Key': key, 'Content-Type': 'application/json' },
    body: JSON.stringify({ to, text }),
    signal: AbortSignal.timeout(30_000),
  });
  const body = await response.text();
  if (!response.ok) {
    console.error(`HTTP ${response.status}: ${body}`);
    process.exitCode = 1;
  } else {
    console.log(response.status === 202 ? 'Queued; do not resend.' : 'Request succeeded; not a delivery receipt.');
    console.log(body);
  }
} catch {
  console.error('Network error or timeout. Delivery is uncertain; check message history before retrying.');
  process.exitCode = 1;
}
