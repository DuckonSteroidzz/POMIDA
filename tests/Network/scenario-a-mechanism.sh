#!/usr/bin/env bash
# Which guard actually refuses the resubmit? Two candidates:
#   (1) the cart was emptied on commit, so the retry has nothing to price
#   (2) the "one order at a time" guard (CustomerOrderAccess::mayOrderAlongside)
# Re-filling the cart separates them: if the retry is STILL refused with a full
# cart, the active-order guard is what is holding the line.
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}
ITEM=${ITEM:-33}; BRANCH=${BRANCH:-1}
JAR=$(mktemp)
q() { "$MY" -uroot "$DB" -N -B -e "$1"; }
tok() { grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

ORD0=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
echo "orders MAX(id) before: $ORD0"

HTML=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/menu"); T=$(tok "$HTML")
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=$BRANCH"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=$ITEM" -d "quantity=1"

echo "--- first placement (completed normally) ---"
R1=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" -d "branch_id=$BRANCH" \
  -d "items[0][menu_item_id]=$ITEM" -d "items[0][quantity]=1")
echo "  -> $R1"
echo "  orders created: $(q "SELECT COUNT(*) FROM orders WHERE id > $ORD0")"

echo "--- REFILL the cart, then retry (isolates the guard) ---"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=$ITEM" -d "quantity=1"
CART=$(curl -s -c "$JAR" -b "$JAR" "$BASE/customer/cart")
echo "  cart now holds item: $(grep -c 'mainOrderForm' <<<"$CART") form(s)"
R2=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$JAR" -b "$JAR" -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" -d "branch_id=$BRANCH" \
  -d "items[0][menu_item_id]=$ITEM" -d "items[0][quantity]=1")
echo "  -> $R2"
N=$(q "SELECT COUNT(*) FROM orders WHERE id > $ORD0")
echo "  orders above baseline: $N"

echo "--- the message the customer is shown ---"
curl -s -c "$JAR" -b "$JAR" -L "$BASE/customer/orders" \
  | grep -o 'You already have an ongoing order[^<]*' | head -1 || echo "  (guard message not found on orders page)"

echo
if [ "$N" -eq 1 ]; then
  echo "VERDICT: refused even with a full cart -> the ACTIVE-ORDER guard is the protection."
else
  echo "VERDICT: a second order WAS created once the cart was refilled ($N total)."
fi
q "SELECT id, order_number, status, created_at FROM orders WHERE id > $ORD0"
rm -f "$JAR"
