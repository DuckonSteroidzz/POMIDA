<?php
// READ-ONLY. Boots the app, instantiates each Eloquent model and calls ONLY the methods that are
// pure relationship declarations, building the relation object (no query is executed).
//
// Safety rails (a first draft of this script wrongly invoked a trait method that writes, so):
//   1. a method is called only if it is declared in the model's OWN file (never from a trait) and its
//      body is, after the opening brace, a bare `return $this-><relation>(...)` statement;
//   2. DB::beforeExecuting() throws on ANY SQL statement, so nothing can ever reach the database.
//
// Usage: php extract_relations.php <project-root> <out.json>
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

$root = rtrim($argv[1], '\\/');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::beforeExecuting(function ($query) {
    throw new RuntimeException('extract_relations.php must not run SQL, attempted: ' . $query);
});

$last = fn (string $s) => str_contains($s, '.') ? substr($s, strrpos($s, '.') + 1) : $s;
$out = [];
$skipped = [];

foreach (glob($root . '/app/Models/*.php') as $file) {
    $class = 'App\\Models\\' . basename($file, '.php');
    if (!class_exists($class)) continue;
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract() || !$rc->isSubclassOf(Model::class)) continue;
    $model = new $class;
    $lines = file($file);
    $realFile = realpath($file);

    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->isStatic() || $m->getNumberOfRequiredParameters() > 0) continue;
        if ($m->getFileName() === false || realpath($m->getFileName()) !== $realFile) continue; // trait / parent method
        $body = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        $afterBrace = ltrim(substr($body, strpos($body, '{') + 1));
        $isRelation = preg_match('/^return\s+\$this->(hasMany|hasOne|belongsTo|belongsToMany|morphTo|morphMany|morphOne|morphToMany|hasManyThrough|hasOneThrough)\(/', $afterBrace);
        if (!$isRelation) {
            if (preg_match('/\$this->(hasMany|hasOne|belongsTo|belongsToMany|morph\w+|has\w+Through)\(/', $body)) {
                $skipped[] = $rc->getShortName() . '::' . $m->getName() . ' (mentions a relation call but is not a bare return - NOT invoked)';
            }
            continue;
        }

        $rel = $m->invoke($model);
        if (!$rel instanceof Relation) continue;
        $row = ['model' => $rc->getShortName(), 'method' => $m->getName(), 'kind' => class_basename($rel)];

        if ($rel instanceof BelongsTo) {
            $row += ['child_table' => $model->getTable(), 'child_col' => $last($rel->getForeignKeyName()),
                     'parent_table' => $rel->getRelated()->getTable(), 'parent_col' => $rel->getOwnerKeyName()];
        } elseif ($rel instanceof HasMany || $rel instanceof HasOne) {
            $row += ['child_table' => $rel->getRelated()->getTable(), 'child_col' => $last($rel->getForeignKeyName()),
                     'parent_table' => $model->getTable(), 'parent_col' => $last($rel->getQualifiedParentKeyName())];
        } elseif ($rel instanceof BelongsToMany) {
            $row += ['pivot_table' => $rel->getTable(),
                     'pivot_col_to_this' => $last($rel->getQualifiedForeignPivotKeyName()), 'this_table' => $model->getTable(),
                     'pivot_col_to_related' => $last($rel->getQualifiedRelatedPivotKeyName()), 'related_table' => $rel->getRelated()->getTable()];
        } else {
            $row['unsupported'] = true;
        }
        $out[] = $row;
    }
}

file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo count($out) . " relationship methods across " . count(array_unique(array_column($out, 'model'))) . " models\n";
foreach ($skipped as $s) echo "skipped: $s\n";
