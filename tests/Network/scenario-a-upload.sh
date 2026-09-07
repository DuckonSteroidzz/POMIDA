set -uo pipefail
BASE=http://127.0.0.1:8000; MY=/c/xampp/mysql/bin/mysql.exe
DIR=storage/app/public/discount_ids
IMG=tests/Network/fixtures/valid_id.png
q(){ "$MY" -uroot pomida_db -N -B -e "$1"; }
tok(){ grep -o '_token" value="[^"]*"' <<<"$1" | head -1 | sed 's/.*value="//; s/"$//'; }
mk_session(){ J=$1; H=$(curl -s -c $J -b $J "$BASE/customer/menu"); T=$(tok "$H")
  curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/select-branch" -d "_token=$T" -d "branch_id=1"
  curl -s -o /dev/null -c $J -b $J -X POST "$BASE/customer/cart/add" -d "_token=$T" -d "item_id=33" -d "quantity=1"
  echo "$T"; }

F0=$(ls -1 $DIR | wc -l); O0=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
echo "baseline: files=$F0 ordersMax=$O0  (image: $(wc -c <$IMG) bytes, real PNG)"

echo; echo "=== A: upload CUT mid-transfer ==="
J1=$(mktemp); T1=$(mk_session $J1)
curl -s -o /dev/null -c $J1 -b $J1 --limit-rate 100k --max-time 1.2 \
  -X POST "$BASE/customer/place-order" \
  -F "_token=$T1" -F "order_type=pick_up" -F "payment_method=cash" -F "branch_id=1" \
  -F "items[0][menu_item_id]=33" -F "items[0][quantity]=1" \
  -F "discount_type=pwd" -F "discount_beneficiary_name=Test Person" \
  -F "discount_beneficiary_id=PWD-0001" -F "discount_beneficiary_expiration=2030-01-01" \
  -F "discount_beneficiary_image=@$IMG;type=image/png"
echo "curl exit=$?"
sleep 1
echo "orders created: $(q "SELECT COUNT(*) FROM orders WHERE id > $O0")   (expect 0)"
echo "new files:      $(( $(ls -1 $DIR | wc -l) - F0 ))   (expect 0)"

echo; echo "=== B: CONTROL, identical request allowed to finish ==="
J2=$(mktemp); T2=$(mk_session $J2)
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c $J2 -b $J2 \
  -X POST "$BASE/customer/place-order" \
  -F "_token=$T2" -F "order_type=pick_up" -F "payment_method=cash" -F "branch_id=1" \
  -F "items[0][menu_item_id]=33" -F "items[0][quantity]=1" \
  -F "discount_type=pwd" -F "discount_beneficiary_name=Test Person" \
  -F "discount_beneficiary_id=PWD-0001" -F "discount_beneficiary_expiration=2030-01-01" \
  -F "discount_beneficiary_image=@$IMG;type=image/png")
echo "curl exit=$? response=$R"
echo "orders created: $(q "SELECT COUNT(*) FROM orders WHERE id > $O0")   (expect 1)"
echo "new files:      $(( $(ls -1 $DIR | wc -l) - F0 ))   (expect 1)"
q "SELECT id,order_number,status,discount_status,discount_id_image FROM orders WHERE id > $O0"
rm -f $J1 $J2
