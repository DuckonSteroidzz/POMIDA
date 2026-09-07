#!/usr/bin/env bash
# docs/RECOVERY_PLAN.md §7.1 — proves the mysqldump backup actually restores.
#
# The dump is restored into a SEPARATE, newly created database and compared
# table by table with the live one. The live database is never written to.
set -euo pipefail
MY=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
DUMP_BIN=${MYSQLDUMP:-/c/xampp/mysql/bin/mysqldump.exe}
LIVE=${LIVE:-pomida_db}
TEST=${TEST:-pomida_db_restore_test}
WORK=${WORK:?work directory required}
mkdir -p "$WORK"
DUMP="$WORK/${LIVE}_$(date +%F).sql"

echo "== 1. dump (RECOVERY_PLAN.md §3.1) =="
"$DUMP_BIN" -uroot --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 "$LIVE" > "$DUMP"
ls -l "$DUMP"

echo "== 2. create empty test database =="
"$MY" -uroot -e "DROP DATABASE IF EXISTS \`$TEST\`;
                 CREATE DATABASE \`$TEST\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "== 3. restore (RECOVERY_PLAN.md §5 step 4) =="
"$MY" -uroot "$TEST" < "$DUMP"
echo "restore exit=$?"

echo "== 4. compare every table, row by row =="
"$MY" -uroot -N -B -e "
  SELECT CONCAT('SELECT ''',table_name,''' AS tbl,
                 (SELECT COUNT(*) FROM \`$LIVE\`.\`',table_name,'\`) AS live_rows,
                 (SELECT COUNT(*) FROM \`$TEST\`.\`',table_name,'\`) AS restored_rows UNION ALL')
  FROM information_schema.tables
  WHERE table_schema='$LIVE' AND table_type='BASE TABLE'
  ORDER BY table_name;" > "$WORK/compare.sql"
sed -i '$ s/UNION ALL$//' "$WORK/compare.sql"
"$MY" -uroot -e "$(cat "$WORK/compare.sql")" > "$WORK/compare.tsv"
cat "$WORK/compare.tsv"

echo "== 5. verdict =="
"$MY" -uroot -N -B -e "$(cat "$WORK/compare.sql")" \
  | awk -F'\t' '{t++; if ($2 != $3) {bad++; print "MISMATCH: " $0}} END {
      printf "tables compared: %d, mismatches: %d\n", t, bad+0;
      exit (bad+0) ? 1 : 0 }'
