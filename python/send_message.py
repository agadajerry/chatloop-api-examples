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
