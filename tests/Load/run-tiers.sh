#!/usr/bin/env bash
# Tiered load run + per-tier data-integrity verification.
#
# Tiers are spaced apart on purpose: the per-IP ceiling on POST
# /customer/place-order is 120 requests/minute (RateLimitServiceProvider), and
# every virtual customer here comes from 127.0.0.1, so back-to-back tiers would
# be refused by design and the earlier tiers' numbers would be unreadable.
set -euo pipefail
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}
OUT=${OUT:?output directory required}
GAP=${GAP:-75}
mkdir -p "$OUT"

q() { "$MY" -uroot "$DB" -N -B -e "$1"; }

for TIER in ${TIERS:-10 25 50 100 150}; do
  BEFORE_ORDERS=$(q "SELECT COALESCE(MAX(id),0) FROM orders")
  BEFORE_SM=$(q "SELECT COUNT(*) FROM stock_movements")
  BEFORE_INV=$(q "SELECT COALESCE(SUM(quantity),0) FROM inventory")

  node tests/Load/load-test.mjs "$TIER" "tier-$TIER" > "$OUT/tier-$TIER.json"

  # ---- data integrity, read straight out of the tables ----
  q "
  SELECT
    '$TIER'                                                       AS tier,
    (SELECT COUNT(*) FROM orders WHERE id > $BEFORE_ORDERS)       AS orders_created,
    (SELECT COUNT(DISTINCT order_number) FROM orders WHERE id > $BEFORE_ORDERS) AS distinct_order_numbers,
    (SELECT COUNT(*) FROM order_items WHERE order_id > $BEFORE_ORDERS)          AS order_items_created,
    (SELECT COUNT(*) FROM orders o WHERE o.id > $BEFORE_ORDERS
       AND (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) <> 1)   AS orders_with_wrong_line_count,
    (SELECT COUNT(*) FROM orders o WHERE o.id > $BEFORE_ORDERS
       AND (o.subtotal <> 200.00 OR o.total <> 200.00))                          AS orders_with_wrong_total,
    (SELECT COUNT(*) FROM stock_movements) - $BEFORE_SM                          AS stock_movements_added,
    (SELECT COALESCE(SUM(quantity),0) FROM inventory) - $BEFORE_INV              AS inventory_delta
  " > "$OUT/tier-$TIER.integrity.tsv"

  echo "--- tier $TIER integrity ---"; cat "$OUT/tier-$TIER.integrity.tsv"

  if [ "$TIER" != "${TIERS##* }" ]; then sleep "$GAP"; fi
done
