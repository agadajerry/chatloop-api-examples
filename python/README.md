# Send WhatsApp Messages with Python Using ChatLoop HQ

Send a WhatsApp appointment reminder with Python and ChatLoop HQ using the standard library. Learn authentication, JSON requests, incoming reply webhooks, and media or poll workflows.

[Read this guide on ChatLoop HQ](https://chatloophq.com/guides/send-whatsapp-message-python) · [API reference](https://chatloophq.com/docs)

## 1. Prepare your channel

Python 3.10 or newer. urllib is included in Python; no pip dependencies or virtual environment are required for this example.

```sh
python3 --version
```

Create an account at https://chatloophq.com/auth/register. In your dashboard, create or select a channel, scan its QR code from WhatsApp's Linked Devices screen, and wait for the channel to connect. Create or copy the channel API key from its settings. This key selects the channel; no separate session ID is needed in the request. Check that the channel has an active plan or trial allowance.

Use a recipient you control or someone who agreed to receive the test. ChatLoop HQ uses a linked WhatsApp session; these are not Meta Cloud API endpoints or credentials.

## 2. Set environment variables

In Bash or Zsh, replace both placeholders. The recipient must contain country code and digits only, with no plus sign or spaces.

```sh
export CHATLOOP_API_KEY='replace-with-your-channel-api-key'
export CHATLOOP_TO='replace-with-your-recipient-digits'
export CHATLOOP_TEXT='Reminder: your appointment is tomorrow at 10:00.'
```

In PowerShell, use `$env:CHATLOOP_API_KEY = 'your-key'` and the same syntax for the remaining variables. These scripts read process environment variables; they do not load .env files automatically. Keep keys on your server, never in browser code, screenshots, or commits. The recipient placeholder intentionally fails local validation.

## 3. Send a message

Save the following as `send_message.py`, or use the file in this directory. Each run submits one real message when correctly configured.

```python
"""Python 3.10+. Uses only the standard library; run on your server."""
import json
import os
import re
import sys
import urllib.error
import urllib.request


def main():
    key = os.environ.get("CHATLOOP_API_KEY")
    to = os.environ.get("CHATLOOP_TO", "")
    text = os.environ.get("CHATLOOP_TEXT", "Reminder: your appointment is tomorrow at 10:00.")
    if not key or not re.fullmatch(r"[1-9]\d{6,14}", to, flags=re.ASCII):
        print("Set CHATLOOP_API_KEY and CHATLOOP_TO (country code + digits, without +).", file=sys.stderr)
        return 1
    request = urllib.request.Request(
        "https://api.chatloophq.com/api/send/text",
        data=json.dumps({"to": to, "text": text}).encode("utf-8"),
        headers={"X-API-Key": key, "Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            print("Queued; do not resend." if response.status == 202 else "Request succeeded; not a delivery receipt.")
            print(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        print(f"HTTP {error.code}: {error.read().decode('utf-8', errors='replace')}", file=sys.stderr)
        return 1
    except (urllib.error.URLError, TimeoutError, OSError):
        print("Network error or timeout. Delivery is uncertain; check message history before retrying.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

Run it:

```sh
python3 send_message.py
```

### How it works

json.dumps serializes the payload, and encode converts it to the bytes urllib expects. The request has an explicit POST method and a 30-second socket timeout. HTTPError is handled before URLError because HTTPError is its subclass. The context manager closes the response after reading it.

The request posts JSON containing `to` and `text` to `https://api.chatloophq.com/api/send/text` using the `X-API-Key` header. The local number check only validates format, not whether the number has WhatsApp.

## 4. Understand the response

- **201 Created:** the immediate send path completed. The JSON contains `success: true` and a `data` object with the send result. This is not a recipient delivery or read receipt.
- **202 Accepted:** the message was queued, for example because pacing is active. The JSON contains `success: true` and `queued: true`, plus queue details. Do not resend a queued message.

The script prints the actual response. Check the recipient's WhatsApp and the channel's message history. See [webhook events](https://chatloophq.com/docs#webhooks-events) for receiving incoming messages.

## 5. Troubleshooting

| Problem | What to check |
| --- | --- |
| Local configuration error | Set both required variables in the current terminal; replace the recipient placeholder with digits. |
| 400 or validation error | Inspect the response, recipient formatting, nonempty text, and channel connection. |
| 401 | Check the channel API key, whitespace, and whether the key was revoked. |
| 403 or plan restriction | Read the response; check subscription and allowance. |
| 429 | Check rate or usage limits and your plan. Do not retry in a tight loop. |
| Timeout, network error, or 5xx | Delivery may be uncertain. Check history before resubmitting to avoid duplicates. |

The examples do not retry automatically. Track notification state and reconcile uncertain results before adding production retries. Do not log keys or retain recipient data unnecessarily.

## 6. Turn this into an appointment reminder

Run the sending logic from a scheduled backend job that selects appointments due for a reminder. Store the appointment timezone, compute the correct reminder time, and record whether the notification was accepted. A scheduled job should skip reminders it has already submitted.

Change `CHATLOOP_TEXT` for a quick experiment, then move the request into a backend function or queue worker with validation and appropriate logging.

## 7. Receive a reply through a webhook

Sending a message and receiving a reply are separate operations. Your app sends an API request to ChatLoop HQ; the connected WhatsApp session sends it to the recipient. When that person replies, ChatLoop HQ normalizes the incoming message, enqueues a webhook job, and POSTs JSON to your backend. Your backend saves the event, acknowledges it with a 2xx response, and a worker decides whether to send another API request.

The webhook response body is not sent to WhatsApp. Returning {text: 'Hello'} only answers the HTTP webhook request. To reply to the person, call POST /api/send/text (or a media/poll endpoint) with the channel API key and the resolved recipient.

The current incoming-message envelope is {messages: [...], event: {type: 'messages', event: 'post'}, channel_id: '...'}. Iterate messages[] rather than assuming a single message or looking for data.messages. A separate generic event helper uses {event, sessionId, timestamp, data}; do not confuse it with this incoming-message shape.

The relay adds X-Idempotency-Key, normally the message ID. Our examples deduplicate each messages[] item using channel_id plus message.id, which also handles batches. Treat incoming fields as untrusted data: validate structure, restrict the channel, route by type, and never execute message text or inject it into SQL or HTML.

```text
Your API call → ChatLoop HQ → WhatsApp recipient
Recipient reply → ChatLoop HQ queue → POST your webhook
Your receiver → validate + save → HTTP 204
Your worker → classify → POST /api/send/text → recipient
```

```json
{
  "messages": [{
    "id": "demo-message-001",
    "messageId": "demo-message-001",
    "from_me": false,
    "chat_id": "14155552671@s.whatsapp.net",
    "timestamp": 1790640000,
    "channel_id": "replace-with-your-channel-id",
    "from": "14155552671",
    "from_name": "Demo sender",
    "type": "text",
    "body": { "text": "HELP" }
  }],
  "event": { "type": "messages", "event": "post" },
  "channel_id": "replace-with-your-channel-id"
}
```

## 8. Send video, audio, polls, and other message types

The same API key and JSON request pattern applies to the following POST endpoints. Replace the path and request body in the sending example. Media URLs must point to actual publicly accessible files, not a player or sharing page. Use content and MIME types WhatsApp supports. The example URLs below are placeholders, not downloadable assets.

Video uses /api/send/video with to and either url or base64; caption, ptv (round video note), and gifPlayback are optional. Audio uses /api/send/audio with to and url or base64, plus optional mimetype and ptt. /api/send/voice takes media (URL or Base64) instead of url. Do not assume that changing a filename converts a file into a compatible voice-note format.

Polls use /api/send/poll with to, question, options (2–12 strings), and allowMultipleAnswers. Despite the name, allowMultipleAnswers is a NUMBER representing the maximum selectable choices, not a boolean; use 1 for a single choice and do not exceed the option count. Keep the returned poll message ID for later vote correlation.

Other routes include /api/send/media for general attachments, /api/send/file for multipart file upload, /api/send/contact for a contact card, /api/send/location for coordinates, and /api/send/reaction for a reaction to an existing message. Check the API reference for each route's own fields; do not send the text payload unchanged to every route.

The video, audio, voice, and poll controllers wrap results in {success: true, data: ...}. Unlike /api/send/text, those controllers do not explicitly switch to HTTP 202 for queued results. Inspect data.queued as well as the HTTP status. A success status alone never proves recipient delivery.

```text
POST /api/send/video
{"to":"14155552671","url":"https://your-cdn.example/demo.mp4","caption":"Your product walkthrough"}

POST /api/send/audio
{"to":"14155552671","url":"https://your-cdn.example/update.m4a","mimetype":"audio/mp4","ptt":false}

POST /api/send/voice
{"to":"14155552671","media":"https://your-cdn.example/note.ogg"}

POST /api/send/poll
{"to":"14155552671","question":"When should we deliver?","options":["Morning","Afternoon"],"allowMultipleAnswers":1}
```

## 9. Interpret incoming media and poll votes

Dispatch on each message.type. text exposes body.text; image and video carry metadata such as caption and mimetype; audio includes mimetype, seconds, and voice_note; document includes file_name and mimetype. There is no automatic speech transcription, video understanding, or file URL in these mapped bodies. Media metadata is not the media bytes.

To retrieve supported stored media, call GET /api/download/media/{messageId} with the same channel API key. For normal incoming media, use message.messageId when present, otherwise message.id. The current controller returns {success: true, data: {buffer, mimetype, filename}}; the Node Buffer serializes as {type: 'Buffer', data: [byte values]}, not a public URL or a Base64 string. Reconstruct bytes on your server, enforce size/type limits, and store files privately. Availability depends on stored message content and WhatsApp media retrieval; stored media references have a 30-day expiry. Persistence is asynchronous, so an immediate lookup can race the initial save.

A poll creation arrives as type poll with body.question, body.options, and body.selectable_count. The decrypted poll_vote path instead provides body.pollMessageId, body.chatId, body.votes [{option, voters, count}], and body.selectedOptions. selectedOptions is an aggregate of options with votes, not necessarily the latest responder's individual choice. Compare the voter identities in votes or maintain per-voter state before assigning a choice to a person. Vote updates depend on finding the original poll and successful decryption; a generic raw poll mapping can contain poll_message_id and selected_options instead, which should not be assumed to be readable option labels.

A useful workflow is text → command router or support queue; audio → private download → optional transcription → reviewed text reply; video/document → private download → inspection or human review; poll_vote → correlate poll ID and voter → update the order or appointment. The sample workers intentionally ignore these nontext types until you add explicit business rules.

You can reply with text, send a relevant video or audio file, or ask a follow-up poll using a new outbound API request. For a quoted text reply, add quotedMessageId: message.id to the text payload, using a message from the same conversation. Keep replies bounded to expected actions and ignore your own outbound messages to avoid feedback loops.

```text
// Example application routing (pseudocode):
switch (message.type) {
  case 'text':       handleCommand(message.body.text); break;
  case 'audio':      enqueuePrivateMediaReview(message.id); break;
  case 'video':
  case 'document':   enqueuePrivateMediaReview(message.id); break;
  case 'poll_vote':  reconcilePollAndVoter(message.body); break;
  default:          recordUnsupportedType(message.type);
}
// A reply is a NEW API call, not the webhook HTTP response.
POST /api/send/text
{"to":"14155552671","text":"Thanks, we can help.","quotedMessageId":"incoming-message-id"}
```


## Next steps

- [Text messaging reference](https://chatloophq.com/docs#send-text)
- [Plans and allowances](https://chatloophq.com/pricing)
- [Node.js tutorial](../nodejs/README.md)
- [PHP tutorial](../php/README.md)
