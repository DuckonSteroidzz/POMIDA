#!/usr/bin/env bash
# ITEM 3 - admin/staff login brute-force limit.
# Proves the 4th wrong attempt inside one minute is refused, and that the
# refusal is the existing friendly 429, not a hard error page.
set -uo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
EMAIL=${EMAIL:-nonexistent-attacker@invalid.local}
JAR=$(mktemp)
tok(){ grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

H=$(curl -s -c $JAR -b $JAR "$BASE/admin/login"); T=$(tok "$H")
echo "attacking $BASE/admin/login as $EMAIL with wrong passwords"
echo
for i in 1 2 3 4 5; do
  OUT=$(curl -s -c $JAR -b $JAR -o /tmp/resp.html -w '%{http_code}|%{redirect_url}' \
    -X POST "$BASE/admin/login" \
    -d "_token=$T" -d "email=$EMAIL" -d "password=wrong-password-$i")
  CODE=${OUT%%|*}
  RL=$(grep -o 'x-ratelimit-rejected' /tmp/resp.html 2>/dev/null | head -1)
  printf 'attempt %d -> HTTP %-3s  %s\n' "$i" "$CODE" "$OUT"
done
rm -f $JAR
