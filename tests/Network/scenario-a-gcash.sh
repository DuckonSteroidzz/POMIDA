#!/usr/bin/env bash
# Scenario A(b): GCash. There is NO customer-side file upload in this flow (see
# report §Step 0) - the customer scans a QR served from Settings and presses
# "I have paid", which only flips payment_status to awaiting_verification.
# So the question that matters is recoverability: if the connection dies at
# each step, can the order get permanently stuck?
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}
ITEM=${ITEM:-33}; BRANCH=${BRANCH:-1}
JAR=$(mktemp)
q() { "$MY" -uroot "$DB" -N -B -e "$1"; }
tok() { grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }

ORD0=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
H=$(curl -s -c $JAR -b $JAR "$BASE/customer/menu"); T=$(tok "$H")
curl -s -o /dev/null -c $JAR -b $JAR -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=$BRANCH"
curl -s -o /dev/null -c $JAR -b $JAR -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=$ITEM" -d "quantity=1"

echo "### place a GCASH order"
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c $JAR -b $JAR -X POST "$BASE/customer/place-order" \
  -d "_token=$T" -d "order_type=pick_up" -d "payment_method=gcash" -d "branch_id=$BRANCH" \
  -d "items[0][menu_item_id]=$ITEM" -d "items[0][quantity]=1")
echo "  -> $R"
OID=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
q "SELECT id,order_number,status,payment_method,payment_status FROM orders WHERE id=$OID"

echo "### connection drops WHILE loading the GCash payment page"
set +e; curl -s -o /dev/null -c $JAR -b $JAR --max-time 0.1 "$BASE/customer/gcash-payment/$OID"; echo "  curl exit=$?"; set -e
echo "  state after drop:"; q "SELECT payment_status FROM orders WHERE id=$OID"

echo "### connection drops DURING 'I have paid' (mark-as-paid POST)"
GP=$(curl -s -c $JAR -b $JAR "$BASE/customer/gcash-payment/$OID"); T2=$(tok "$GP"); T2=${T2:-$T}
set +e
curl -s -o /dev/null -c $JAR -b $JAR --max-time 0.12 -X POST "$BASE/customer/gcash-payment/$OID/paid" -d "_token=$T2"
echo "  curl exit=$? (28 = cut)"
set -e
echo "  payment_status after the cut:"; q "SELECT payment_status FROM orders WHERE id=$OID"

echo "### does the order remain visible + actionable (not stranded)?"
echo "  on the staff Active Orders board (status IN pending/preparing/serving): $(q "SELECT COUNT(*) FROM orders WHERE id=$OID AND status IN ('pending','preparing','serving')")"
echo "  in the customer's own order history: $(curl -s -c $JAR -b $JAR "$BASE/customer/orders" | grep -c "$(q "SELECT order_number FROM orders WHERE id=$OID")")"
echo "  reachable payment page after reconnect: HTTP $(curl -s -o /dev/null -w '%{http_code}' -c $JAR -b $JAR "$BASE/customer/gcash-payment/$OID")"

echo
echo "orders created by this test:"; q "SELECT id,order_number,status,payment_status FROM orders WHERE id > $ORD0"
rm -f $JAR
