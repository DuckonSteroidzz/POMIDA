#!/usr/bin/env bash
# Scenario B: the cafe's internet drops, LAN + MySQL stay up.
#
# Two things have to be shown:
#   1. the SERVER opens no external sockets while serving the order flow, so
#      losing the internet cannot affect order placement; and
#   2. the external hosts the pages reference are browser-side assets only.
set -uo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
q(){ "$MY" -uroot pomida_db -N -B -e "$1"; }
tok(){ grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

ext_sockets() {
  # non-loopback, non-LAN TCP endpoints held by php.exe
  netstat -ano 2>/dev/null | tr -d '\r' | awk '/TCP/ {print $3, $4, $5}' \
    | grep -vE '127\.0\.0\.1|0\.0\.0\.0|\[::|192\.168\.|10\.\.|169\.254\.' | grep -c ESTABLISHED
}

echo "### external (non-loopback, non-LAN) established TCP endpoints"
echo "  before order traffic: $(ext_sockets)"

O0=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
echo
echo "### placing 3 real cash orders while watching for external sockets"
for i in 1 2 3; do
  J=$(mktemp)
  H=$(curl -s -c $J -b $J "$BASE/customer/menu"); T=$(tok "$H")
  curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=1"
  curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=33" -d "quantity=1"
  R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c $J -b $J -X POST "$BASE/customer/place-order" \
    -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" -d "branch_id=1" \
    -d "items[0][menu_item_id]=33" -d "items[0][quantity]=1")
  echo "  order $i -> $R ; external sockets now: $(ext_sockets)"
  rm -f $J
done
echo "  orders created: $(q "SELECT COUNT(*) FROM orders WHERE id > $O0")"

echo
echo "### are the external hosts reachable from THIS machine at all?"
for h in cdn.jsdelivr.net fonts.googleapis.com; do
  printf '  %-24s ' "$h"
  curl -s -o /dev/null -m 6 -w 'HTTP %{http_code} in %{time_total}s\n' "https://$h/" || echo "unreachable"
done

echo
echo "### simulating the CDN being unreachable (per-request DNS override to a dead IP)"
echo "    - this is the exact condition 'cafe internet is down' creates for a browser"
for u in "cdn.jsdelivr.net:443:203.0.113.1|https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" \
         "fonts.googleapis.com:443:203.0.113.1|https://fonts.googleapis.com/css2?family=Inter"; do
  RES=${u%%|*}; URL=${u##*|}
  printf '  %-34s ' "${RES%%:*}"
  curl -s -o /dev/null -m 5 --resolve "$RES" -w 'HTTP %{http_code}\n' "$URL" 2>/dev/null || echo "FAILED to load (as expected when offline)"
done

echo
echo "### with those hosts dead, does the app itself still serve and take orders?"
J=$(mktemp)
H=$(curl -s -c $J -b $J --resolve cdn.jsdelivr.net:443:203.0.113.1 --resolve fonts.googleapis.com:443:203.0.113.1 "$BASE/customer/menu")
T=$(tok "$H")
echo "  /customer/menu served: $(wc -c <<<"$H") bytes, CSRF token present: $([ -n "$T" ] && echo YES || echo NO)"
curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=1"
curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=33" -d "quantity=1"
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c $J -b $J \
  --resolve cdn.jsdelivr.net:443:203.0.113.1 --resolve fonts.googleapis.com:443:203.0.113.1 \
  -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" -d "branch_id=1" \
  -d "items[0][menu_item_id]=33" -d "items[0][quantity]=1")
echo "  place-order with external hosts dead -> $R"
echo "  total orders created by this script: $(q "SELECT COUNT(*) FROM orders WHERE id > $O0")"
q "SELECT id,order_number,status,total FROM orders WHERE id > $O0"
rm -f $J
