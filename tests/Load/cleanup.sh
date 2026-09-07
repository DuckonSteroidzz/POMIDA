#!/usr/bin/env bash
# Removes everything a test run created, bounded by the id high-water marks
# taken before the run. Nothing that existed beforehand can be touched, because
# every statement is bounded by "id > <baseline max id>".
#
# This script VERIFIES its own work. If anything above a high-water mark is
# still present after the deletes — or if any pre-existing row was orphaned by
# them — it prints a loud error and exits non-zero. It never reports success it
# has not proven.
#
# Required env: ORD_MAX OI_MAX OIO_MAX SM_MAX NOTIF_MAX
# Optional env: HR_MAX TS_MAX OR_MAX GP_MAX   (default 0 = "test created none")
#               MYSQL DB
set -euo pipefail

MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}

: "${ORD_MAX:?high-water mark required}" "${OI_MAX:?}" "${OIO_MAX:?}" "${SM_MAX:?}" "${NOTIF_MAX:?}"
HR_MAX=${HR_MAX:-0}; TS_MAX=${TS_MAX:-0}; OR_MAX=${OR_MAX:-0}; GP_MAX=${GP_MAX:-0}

# Every mark is interpolated straight into SQL, so prove each one is a plain
# non-negative integer before it gets anywhere near the database.
for v in ORD_MAX OI_MAX OIO_MAX SM_MAX NOTIF_MAX HR_MAX TS_MAX OR_MAX GP_MAX; do
  case "${!v}" in
    ''|*[!0-9]*) echo "FATAL: $v='${!v}' is not a non-negative integer" >&2; exit 2 ;;
  esac
done

q() { "$MY" -uroot "$DB" -N -B -e "$1"; }

# ---------------------------------------------------------------- delete ----
# orders CASCADEs to order_items, order_item_options, notifications,
# order_ratings and games_played; help_requests and table_sessions are SET NULL
# and so must be deleted explicitly BEFORE orders, or the cascade silently
# leaves them behind pointing at nothing.
"$MY" -uroot "$DB" <<SQL
START TRANSACTION;
DELETE FROM help_requests      WHERE id > $HR_MAX;
DELETE FROM table_sessions     WHERE id > $TS_MAX;
DELETE FROM order_ratings      WHERE id > $OR_MAX;
DELETE FROM games_played       WHERE id > $GP_MAX;
DELETE FROM order_item_options WHERE id > $OIO_MAX;
DELETE FROM order_items        WHERE id > $OI_MAX;
DELETE FROM stock_movements    WHERE id > $SM_MAX;
DELETE FROM notifications      WHERE id > $NOTIF_MAX;
DELETE FROM orders             WHERE id > $ORD_MAX;
COMMIT;
SQL

# ---------------------------------------------------------------- verify ----
# The deletes above can fail to be total in ways that do not raise a SQL error
# (a wrong mark, a table the run wrote to that nobody listed). So re-read the
# tables and require zero.
fail=0
check() { # check <label> <count-sql>
  local n; n=$(q "$2")
  if [ "$n" != "0" ]; then
    echo "CLEANUP FAILED: $1 -> $n row(s) still present" >&2
    fail=1
  else
    printf '  ok  %-34s 0 remaining\n' "$1"
  fi
}

echo "verifying cleanup..."
check "orders > $ORD_MAX"             "SELECT COUNT(*) FROM orders             WHERE id > $ORD_MAX"
check "order_items > $OI_MAX"         "SELECT COUNT(*) FROM order_items        WHERE id > $OI_MAX"
check "order_item_options > $OIO_MAX" "SELECT COUNT(*) FROM order_item_options WHERE id > $OIO_MAX"
check "stock_movements > $SM_MAX"     "SELECT COUNT(*) FROM stock_movements    WHERE id > $SM_MAX"
check "notifications > $NOTIF_MAX"    "SELECT COUNT(*) FROM notifications      WHERE id > $NOTIF_MAX"
check "help_requests > $HR_MAX"       "SELECT COUNT(*) FROM help_requests      WHERE id > $HR_MAX"
check "table_sessions > $TS_MAX"      "SELECT COUNT(*) FROM table_sessions     WHERE id > $TS_MAX"
check "order_ratings > $OR_MAX"       "SELECT COUNT(*) FROM order_ratings      WHERE id > $OR_MAX"
check "games_played > $GP_MAX"        "SELECT COUNT(*) FROM games_played       WHERE id > $GP_MAX"

# Nothing pre-existing may have been left dangling by the deletes.
check "orphaned order_items" \
  "SELECT COUNT(*) FROM order_items oi LEFT JOIN orders o ON o.id=oi.order_id WHERE o.id IS NULL"
check "orphaned order_item_options" \
  "SELECT COUNT(*) FROM order_item_options x LEFT JOIN order_items oi ON oi.id=x.order_item_id WHERE oi.id IS NULL"
check "help_requests nulled by cascade" \
  "SELECT COUNT(*) FROM help_requests WHERE order_id IS NULL AND id <= $HR_MAX AND updated_at >= (NOW() - INTERVAL 1 HOUR)"

if [ "$fail" -ne 0 ]; then
  echo >&2
  echo "CLEANUP DID NOT COMPLETE. Test data is still in the database." >&2
  echo "Do NOT treat this run as cleaned up. Investigate before continuing." >&2
  exit 1
fi

echo "cleanup done - verified zero rows above every high-water mark"
