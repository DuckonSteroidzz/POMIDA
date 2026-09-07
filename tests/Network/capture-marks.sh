#!/usr/bin/env bash
# Writes the id high-water marks for every table a network-resilience test can
# touch, as a sourceable env file. Run BEFORE any test that writes to the DB.
set -euo pipefail
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}
OUT=${1:?output file required}
q() { "$MY" -uroot "$DB" -N -B -e "$1"; }
{
  echo "# captured $(date -Is)"
  echo "export ORD_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM orders')"
  echo "export OI_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM order_items')"
  echo "export OIO_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM order_item_options')"
  echo "export SM_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM stock_movements')"
  echo "export NOTIF_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM notifications')"
  echo "export HR_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM help_requests')"
  echo "export TS_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM table_sessions')"
  echo "export OR_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM order_ratings')"
  echo "export GP_MAX=$(q 'SELECT COALESCE(MAX(id),0) FROM games_played')"
} > "$OUT"
echo "marks written to $OUT"; cat "$OUT"
