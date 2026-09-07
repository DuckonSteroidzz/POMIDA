#!/usr/bin/env bash
# ITEMS 4a + 4b end to end over real HTTP against the running app.
# Reproduces what the reviewer did by hand: request a reset code, read it off
# the dev banner, then try to set a weak password on a real admin account.
set -uo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
EMAIL=${EMAIL:-secreview-temp@invalid.local}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
JAR=$(mktemp)
tok(){ grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }
flat(){ sed 's/<[^>]*>/ /g' | tr '\n' ' ' | tr -s ' '; }
hash_of(){ "$MY" -uroot pomida_db -N -B -e "SELECT LEFT(password,25) FROM users WHERE email='$EMAIL'"; }

BEFORE_HASH=$(hash_of)
echo "password hash before the flow: $BEFORE_HASH"

echo
echo "=== step 1: request a reset code for $EMAIL ==="
H=$(curl -s -c $JAR -b $JAR "$BASE/admin/forgot-password"); T=$(tok "$H")
curl -s -c $JAR -b $JAR -o /dev/null -X POST "$BASE/admin/forgot-password" -d "_token=$T" -d "email=$EMAIL"
V=$(curl -s -c $JAR -b $JAR "$BASE/admin/verification")
CODE=$(flat <<<"$V" | grep -o 'so your code is [0-9]\{6\}' | grep -o '[0-9]\{6\}')
echo "  dev banner rendered: $(grep -c 'Development mode' <<<"$V")  (1 = shown; local only)"
echo "  code read straight off the page: ${CODE:-NONE}"

echo
echo "=== step 2: verify the code (form posts six otp[] digits) ==="
T=$(tok "$V")
ARGS=(-d "_token=$T")
for (( i=0; i<${#CODE}; i++ )); do ARGS+=(-d "otp[]=${CODE:$i:1}"); done
curl -s -c $JAR -b $JAR -o /dev/null -w '  verify -> HTTP %{http_code} %{redirect_url}\n' \
  -X POST "$BASE/admin/verification" "${ARGS[@]}"

echo
echo "=== step 3: the passwords the reviewer got accepted BEFORE the fix ==="
for W in 123456 simon123 12345678 abcdefgh; do
  NP=$(curl -s -c $JAR -b $JAR "$BASE/admin/new-password"); T=$(tok "$NP")
  R=$(curl -s -c $JAR -b $JAR -L -X POST "$BASE/admin/new-password" \
      -d "_token=$T" -d "password=$W" -d "password_confirmation=$W")
  MSG=$(flat <<<"$R" | grep -o 'The password field must[^.]*\.' | head -3 | tr '\n' ' ')
  SAME=$([ "$(hash_of)" = "$BEFORE_HASH" ] && echo "password UNCHANGED" || echo "PASSWORD WAS CHANGED")
  printf "  %-12s %s\n" "'$W'" "$SAME"
  [ -n "$MSG" ] && echo "               $MSG"
done

echo
echo "=== step 4: a compliant password (Reset123!) ==="
NP=$(curl -s -c $JAR -b $JAR "$BASE/admin/new-password"); T=$(tok "$NP")
OUT=$(curl -s -c $JAR -b $JAR -o /dev/null -w '%{http_code}|%{redirect_url}' -X POST "$BASE/admin/new-password" \
    -d "_token=$T" -d "password=Reset123!" -d "password_confirmation=Reset123!")
echo "  -> $OUT"
echo "  hash changed: $([ "$(hash_of)" != "$BEFORE_HASH" ] && echo YES || echo NO)"
rm -f $JAR
