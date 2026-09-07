#!/usr/bin/env bash
# ITEM 3 (second half) - the limit must not break legitimate login.
# Waits out the real 1-minute window (no cache tampering) and then signs in
# with correct credentials.
set -uo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
EMAIL=${EMAIL:-secreview-temp@invalid.local}
PASS=${PASS:-Staff123!}
tok(){ grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

echo "locked out at $(date +%T); waiting 65s for the throttle window to expire..."
sleep 65
echo "window should be open at $(date +%T)"

JAR=$(mktemp)
H=$(curl -s -c $JAR -b $JAR "$BASE/admin/login"); T=$(tok "$H")
OUT=$(curl -s -c $JAR -b $JAR -o /dev/null -w '%{http_code}|%{redirect_url}' -X POST "$BASE/admin/login" \
  -d "_token=$T" -d "email=$EMAIL" -d "password=$PASS")
echo "correct login -> $OUT"
DASH=$(curl -s -c $JAR -b $JAR -o /dev/null -w '%{http_code}' -L "$BASE/admin/home")
echo "authenticated /admin/home -> HTTP $DASH"
case "$OUT" in
  302*admin*) echo "VERDICT: legitimate login still works after the window resets." ;;
  *) echo "VERDICT: PROBLEM - correct login did not succeed ($OUT)" ;;
esac
rm -f $JAR
