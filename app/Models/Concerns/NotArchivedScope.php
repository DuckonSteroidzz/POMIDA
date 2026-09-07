<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides archived rows from every query by default. See the Archivable trait for
 * why this is a global scope rather than an opt-in one.
 *
 * Its own file, not tucked inside Archivable.php, so that PSR-4 can autoload it
 * by name: OrderItem's relations remove it by class name without ever touching
 * the trait, and a class the autoloader cannot find would only fail at runtime
 * on the history pages — the exact place a mistake is least likely to be seen.
 */
class NotArchivedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable() . '.archived_at');
    }
}
