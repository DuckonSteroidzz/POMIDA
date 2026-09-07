#!/usr/bin/env bash
# Scenario C: an interruption part-way through placeOrder().
#
# A real exception is forced at the END of the DB::transaction() closure - after
# the order row, its order_items, its order_item_options and the voucher
# counters have all been written. If the transaction guarantee holds, none of
# them may survive.
#
# Requires the temporary hook in OrderController::placeOrder and
# POMIDA_FORCE_ROLLBACK_TEST=1 in .env. Both are removed after this test.
set -uo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
q(){ "$MY" -uroot pomida_db -N -B -e "$1"; }
tok(){ grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

snap(){ q "SELECT CONCAT(
  (SELECT COUNT(*) FROM orders),'|',
  (SELECT COUNT(*) FROM order_items),'|',
  (SELECT COUNT(*) FROM order_item_options),'|',
  (SELECT COUNT(*) FROM notifications),'|',
  (SELECT COALESCE(SUM(used_count),0) FROM vouchers),'|',
  (SELECT COUNT(*) FROM stock_movements))"; }

echo "flag in .env: $(grep -c '^POMIDA_FORCE_ROLLBACK_TEST=1' .env) (1 = rollback hook armed)"
BEFORE=$(snap)
O0=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
echo "before: orders|items|options|notifs|voucher_uses|stock_moves = $BEFORE"

J=$(mktemp)
H=$(curl -s -c $J -b $J "$BASE/customer/menu"); T=$(tok "$H")
curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=1"
curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=33" -d "quantity=1"

echo
echo "### placing an order that will fail mid-transaction"
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c $J -b $J -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=cash" -d "branch_id=1" \
  -d "items[0][menu_item_id]=33" -d "items[0][quantity]=1")
echo "  response: $R"

AFTER=$(snap)
echo
echo "after : orders|items|options|notifs|voucher_uses|stock_moves = $AFTER"
echo "  orders above baseline      : $(q "SELECT COUNT(*) FROM orders WHERE id > $O0")   (expect 0)"
echo "  order_items with no parent : $(q "SELECT COUNT(*) FROM order_items oi LEFT JOIN orders o ON o.id=oi.order_id WHERE o.id IS NULL")   (expect 0)"
echo "  options with no parent line: $(q "SELECT COUNT(*) FROM order_item_options x LEFT JOIN order_items oi ON oi.id=x.order_item_id WHERE oi.id IS NULL")   (expect 0)"
echo
if [ "$BEFORE" = "$AFTER" ]; then
  echo "VERDICT: every counter identical - the transaction rolled back completely."
else
  echo "VERDICT: STATE CHANGED despite the failure - rollback is NOT complete."
fi
echo
echo "### what the customer is shown"
curl -s -c $J -b $J -L "$BASE/customer/cart" | grep -oE 'Something went wrong[^<]*|Unable to place[^<]*|error[^<]{0,60}' | head -3
rm -f $J
