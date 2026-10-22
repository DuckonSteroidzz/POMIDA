<?php
// READ-ONLY: queries information_schema only (never the application's own tables).
// Usage: php extract_schema.php <project-root> <out.json>   (reads DB_* from <project-root>/.env)
$envFile = $argv[1] . DIRECTORY_SEPARATOR . '.env';
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
    $line = rtrim($line, "\r");
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, " \t\"'");
}
$db = $env['DB_DATABASE'];
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname=information_schema;charset=utf8mb4",
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$q = function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
};

$out = ['database' => $db, 'tables' => []];

$tables = $q("SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COMMENT FROM TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME", [$db]);
foreach ($tables as $t) {
    $out['tables'][$t['TABLE_NAME']] = [
        'type' => $t['TABLE_TYPE'], 'engine' => $t['ENGINE'], 'comment' => $t['TABLE_COMMENT'],
        'columns' => [], 'indexes' => [], 'fks' => [],
    ];
}

$cols = $q("SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT
            FROM COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION", [$db]);
foreach ($cols as $c) {
    $out['tables'][$c['TABLE_NAME']]['columns'][] = [
        'name' => $c['COLUMN_NAME'], 'pos' => (int) $c['ORDINAL_POSITION'], 'default' => $c['COLUMN_DEFAULT'],
        'null' => $c['IS_NULLABLE'] === 'YES', 'data_type' => $c['DATA_TYPE'], 'type' => $c['COLUMN_TYPE'],
        'key' => $c['COLUMN_KEY'], 'extra' => $c['EXTRA'], 'comment' => $c['COLUMN_COMMENT'],
    ];
}

$idx = $q("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
           FROM STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", [$db]);
foreach ($idx as $i) {
    $n = $i['INDEX_NAME'];
    $ix = &$out['tables'][$i['TABLE_NAME']]['indexes'];
    if (!isset($ix[$n])) $ix[$n] = ['name' => $n, 'unique' => $i['NON_UNIQUE'] == 0, 'primary' => $n === 'PRIMARY', 'columns' => []];
    $ix[$n]['columns'][] = $i['COLUMN_NAME'];
    unset($ix);
}
foreach ($out['tables'] as &$t) $t['indexes'] = array_values($t['indexes']);
unset($t);

$fks = $q("SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                  r.DELETE_RULE, r.UPDATE_RULE
           FROM KEY_COLUMN_USAGE k
           JOIN REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
           WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
           ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION", [$db]);
foreach ($fks as $f) {
    $n = $f['CONSTRAINT_NAME'];
    $fk = &$out['tables'][$f['TABLE_NAME']]['fks'];
    if (!isset($fk[$n])) $fk[$n] = ['name' => $n, 'columns' => [], 'ref_table' => $f['REFERENCED_TABLE_NAME'], 'ref_columns' => [], 'on_delete' => $f['DELETE_RULE'], 'on_update' => $f['UPDATE_RULE']];
    $fk[$n]['columns'][] = $f['COLUMN_NAME'];
    $fk[$n]['ref_columns'][] = $f['REFERENCED_COLUMN_NAME'];
    unset($fk);
}
foreach ($out['tables'] as &$t) $t['fks'] = array_values($t['fks']);
unset($t);

$ver = $q("SELECT VERSION() AS v")[0]['v'] ?? '?';
$out['server_version'] = $ver;

file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$nc = array_sum(array_map(fn ($t) => count($t['columns']), $out['tables']));
$nf = array_sum(array_map(fn ($t) => count($t['fks']), $out['tables']));
echo "tables=" . count($out['tables']) . " columns=$nc fks=$nf server=$ver\n";
