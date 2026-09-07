#!/usr/bin/env bash
# Scenario A(a): the customer's connection drops AFTER the browser has sent
# "place order" but BEFORE the response comes back. Then the customer, seeing
# no confirmation, presses the button again.
#
# The abort is a real TCP abort: curl --max-time kills the connection while PHP
# is still executing the request it already received in full.
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}
ITEM=${ITEM:-33}
BRANCH=${BRANCH:-1}
JAR=$(mktemp)
q() { "$MY" -uroot "$DB" -N -B -e "$1"; }
tok() { grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

echo "### Scenario A(a): aborted place-order, then resubmit"
ORD_BEFORE=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
echo "orders MAX(id) before: $ORD_BEFORE"

# --- build a real guest session with a real cart -------------------------
HTML=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/menu")
T=$(tok "$HTML")
[ -n "$T" ] || { echo "no CSRF token"; exit 1; }
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/customer/select-branch" \
  -d "_token=$T" -d "branch_id=$BRANCH"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/customer/cart/add" \
  -d "_token=$T" -d "item_id=$ITEM" -d "quantity=1"
CART=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/cart")
T=$(tok "$CART" || true); T=${T:-$T}
echo "cart ready: $(grep -c mainOrderForm <<<"$CART") form(s)"

# --- the aborted request -------------------------------------------------
# Ramp the cut-off until the server has demonstrably processed the request
# (an order row appears) even though curl never read a response.
ABORT_RC=""; ABORT_T=""
for MS in 0.15 0.25 0.35 0.5 0.75 1.0; do
  set +e
  curl -s -o /dev/null -c "$JAR" -b "$JAR" --max-time "$MS" \
    -X POST "$BASE/customer/place-order" \
    -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" \
    -d "branch_id=$BRANCH" \
    -d "items[0][menu_item_id]=$ITEM" -d "items[0][quantity]=1"
  RC=$?
  set -e
  AFTER=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
  echo "  cut at ${MS}s -> curl exit $RC (28=timeout/abort), orders MAX(id)=$AFTER"
  ABORT_RC=$RC; ABORT_T=$MS
  [ "$AFTER" != "$ORD_BEFORE" ] && break
done

ORD_AFTER_ABORT=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
CREATED=$(q "SELECT COUNT(*) FROM orders WHERE id > $ORD_BEFORE")
echo
echo "RESULT after abort: curl exit=$ABORT_RC at ${ABORT_T}s; orders created=$CREATED"
q "SELECT id, order_number, status, payment_status, total, created_at FROM orders WHERE id > $ORD_BEFORE"

# --- the customer resubmits ---------------------------------------------
echo
echo "### customer presses 'place order' again (same session)"
RESUB=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" \
  -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" \
  -d "branch_id=$BRANCH" \
  -d "items[0][menu_item_id]=$ITEM" -d "items[0][quantity]=1")
echo "resubmit response: $RESUB"
TOTAL=$(q "SELECT COUNT(*) FROM orders WHERE id > $ORD_BEFORE")
echo "orders above baseline after resubmit: $TOTAL"
q "SELECT id, order_number, status, created_at FROM orders WHERE id > $ORD_BEFORE"

echo
if [ "$TOTAL" -le 1 ]; then
  echo "VERDICT: no duplicate order. Double-submit was refused."
else
  echo "VERDICT: DUPLICATE CREATED ($TOTAL orders) - real bug."
fi
rm -f "$JAR"
