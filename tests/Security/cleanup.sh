#!/usr/bin/env bash
# Bounded cleanup for the manual security review's test data, following the
# same capture-before / delete-above / verify-zero pattern as
# tests/Load/cleanup.sh. Verifies its own work and exits non-zero if anything
# it created is still present.
set -euo pipefail
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DB=${DB:-pomida_db}
: "${USR_MAX:?high-water mark required}"
case "$USR_MAX" in ''|*[!0-9]*) echo "FATAL: USR_MAX='$USR_MAX' not an integer" >&2; exit 2;; esac

q(){ "$MY" -uroot "$DB" -N -B -e "$1"; }

echo "deleting accounts created above id $USR_MAX ..."
q "SELECT CONCAT('  - id=', id, ' ', email, ' (', role, ')') FROM users WHERE id > $USR_MAX"

"$MY" -uroot "$DB" <<SQL
START TRANSACTION;
DELETE FROM password_reset_tokens
  WHERE email IN (SELECT email FROM (SELECT email FROM users WHERE id > $USR_MAX) t);
DELETE FROM password_reset_tokens WHERE email LIKE '%invalid.local';
DELETE FROM users WHERE id > $USR_MAX;
COMMIT;
SQL

fail=0
check(){ n=$(q "$2"); if [ "$n" != "0" ]; then echo "CLEANUP FAILED: $1 -> $n row(s) left" >&2; fail=1;
         else printf '  ok  %-40s 0 remaining\n' "$1"; fi; }

echo "verifying..."
check "users > $USR_MAX"                 "SELECT COUNT(*) FROM users WHERE id > $USR_MAX"
check "test accounts (*.invalid.local)"  "SELECT COUNT(*) FROM users WHERE email LIKE '%invalid.local'"
check "reset tokens for test accounts"   "SELECT COUNT(*) FROM password_reset_tokens WHERE email LIKE '%invalid.local'"
check "orphaned reset tokens"            "SELECT COUNT(*) FROM password_reset_tokens p LEFT JOIN users u ON u.email=p.email WHERE u.id IS NULL"

if [ "$fail" -ne 0 ]; then
  echo >&2; echo "CLEANUP DID NOT COMPLETE. Test data is still in the database." >&2
  exit 1
fi
echo "cleanup done - verified zero rows above the high-water mark"
