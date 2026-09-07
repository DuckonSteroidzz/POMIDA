#!/usr/bin/env bash
# Scenario A(c): after the connection drops mid-placement and comes back, does
# the SAME browser session still work — and can the customer see the order the
# server actually created while they were offline?
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}
ITEM=${ITEM:-33}; BRANCH=${BRANCH:-1}
JAR=$(mktemp)
q() { "$MY" -uroot "$DB" -N -B -e "$1"; }
tok() { grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

ORD0=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
HTML=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/menu"); T=$(tok "$HTML")
SESS_BEFORE=$(grep -i 'pomida\|session' "$JAR" | awk '{print $7}' | head -1)
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=$BRANCH"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=$ITEM" -d "quantity=1"

echo "### cart BEFORE the drop"
q "SELECT COUNT(*) FROM sessions" >/dev/null
CART_BEFORE=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/cart")
echo "  cart page HTTP ok, contains order form: $(grep -c mainOrderForm <<<"$CART_BEFORE")"

echo "### connection drops mid place-order (abort at 0.15s)"
set +e
curl -s -o /dev/null -c "$JAR" -b "$JAR" --max-time 0.15 -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" -d "branch_id=$BRANCH" \
  -d "items[0][menu_item_id]=$ITEM" -d "items[0][quantity]=1"
echo "  curl exit $? (28 = connection cut)"
set -e
NEW_ID=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
echo "  server created order id: $NEW_ID"

echo "### ---- customer reconnects, same cookies ----"
SESS_AFTER=$(grep -i 'pomida\|session' "$JAR" | awk '{print $7}' | head -1)
echo "  session cookie unchanged: $([ "$SESS_BEFORE" = "$SESS_AFTER" ] && echo YES || echo NO)"

MENU=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE/customer/menu")
echo "  /customer/menu after reconnect: HTTP $MENU"

CART_AFTER=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/cart")
echo "  cart still holds an order form: $(grep -c mainOrderForm <<<"$CART_AFTER") (0 = cart was cleared by the commit)"

ORDERS=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/orders")
NUM=$(q "SELECT order_number FROM orders WHERE id=$NEW_ID")
echo "  order history shows the order placed while offline ($NUM): $(grep -c "$NUM" <<<"$ORDERS")"

echo
echo "orders created by this test:"; q "SELECT id, order_number, status FROM orders WHERE id > $ORD0"
rm -f "$JAR"
