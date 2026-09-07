#!/usr/bin/env bash
# Scenario B, end-to-end proof: with every external host this app used to
# depend on resolving to a dead IP, a customer can still load the menu, load
# EVERY asset the page needs, and place a cash order.
#
# The block is per-request (curl --resolve), so it needs no admin rights and
# leaves nothing behind on the machine - no hosts file or firewall change.
set -uo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DEAD=203.0.113.1   # RFC 5737 TEST-NET-3, guaranteed unroutable
BLOCK=(--resolve cdn.jsdelivr.net:443:$DEAD
       --resolve fonts.googleapis.com:443:$DEAD
       --resolve fonts.gstatic.com:443:$DEAD)
q(){ "$MY" -uroot pomida_db -N -B -e "$1"; }
tok(){ grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

echo "### confirming the block actually blocks"
for h in cdn.jsdelivr.net fonts.googleapis.com; do
  printf '  %-24s ' "$h"
  curl -s -o /dev/null -m 4 "${BLOCK[@]}" -w 'HTTP %{http_code}\n' "https://$h/" || echo "unreachable (expected)"
done

O0=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
J=$(mktemp)

echo
echo "### customer loads the menu with the internet 'down'"
H=$(curl -s -c $J -b $J "${BLOCK[@]}" "$BASE/customer/menu")
T=$(tok "$H")
echo "  page: $(wc -c <<<"$H") bytes, CSRF token: $([ -n "$T" ] && echo present || echo MISSING)"
echo "  external asset references on the page: $(grep -cE '(src|href)="https://(cdn\.jsdelivr|fonts\.g)' <<<"$H")  (0 = nothing to fail)"

echo
echo "### every asset the page asks for, fetched with the internet down"
BAD=0; N=0
for a in $(grep -oE '(src|href)="/[^"]+\.(css|js)' <<<"$H" | sed 's/.*="//' | sort -u); do
  C=$(curl -s -o /dev/null -m 5 "${BLOCK[@]}" -w '%{http_code}' "$BASE$a")
  N=$((N+1)); [ "$C" != "200" ] && { echo "  FAIL $a -> $C"; BAD=1; }
done
# and the font files the local css pulls in
for a in $(curl -s "$BASE/vendor/gfonts.css" | grep -oE '/vendor/gfonts/[^)]+' | sort -u); do
  C=$(curl -s -o /dev/null -m 5 "${BLOCK[@]}" -w '%{http_code}' "$BASE$a")
  N=$((N+1)); [ "$C" != "200" ] && { echo "  FAIL $a -> $C"; BAD=1; }
done
echo "  $N assets fetched, all HTTP 200: $([ $BAD -eq 0 ] && echo YES || echo NO)"

echo
echo "### and the order still goes through"
curl -s -o /dev/null -c $J -b $J "${BLOCK[@]}" -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=1"
curl -s -o /dev/null -c $J -b $J "${BLOCK[@]}" -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=33" -d "quantity=1"
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c $J -b $J "${BLOCK[@]}" -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" -d "branch_id=1" \
  -d "items[0][menu_item_id]=33" -d "items[0][quantity]=1")
echo "  place-order -> $R"
echo "  orders created: $(q "SELECT COUNT(*) FROM orders WHERE id > $O0")"
q "SELECT id,order_number,status,total FROM orders WHERE id > $O0"
rm -f $J
