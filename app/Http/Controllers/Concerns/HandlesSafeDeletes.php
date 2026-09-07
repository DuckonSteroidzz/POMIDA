<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * No admin delete may ever end at a raw exception page.
 *
 * The reported failure: deleting a menu option that had been chosen on a past
 * order threw an unhandled QueryException and rendered the full Ignition debug
 * page — stack trace, file paths, and the raw SQL including the database name
 * and the constraint `order_item_options_menu_option_id_foreign`. To an admin
 * that is a crash; to anyone who can reach that screen it is also a free map of
 * the schema.
 *
 * The cause is a deliberate database rule, not a bug: order_items.menu_item_id
 * and order_item_options.menu_option_id are ON DELETE RESTRICT, because a past
 * order must keep saying what was actually ordered. The database is right to
 * refuse. The application was wrong to translate that refusal into a 500.
 *
 * So every delete endpoint in AdminController runs through here:
 *
 *   1. Where a blocking reference is known in advance, the endpoint checks for
 *      it first and explains it in the admin's own terms ("used by N past
 *      order(s)"), which is far more useful than any generic message.
 *   2. Whatever slips past that — a constraint added later, a trigger, a
 *      deadlock, a dropped connection — is caught here and turned into a
 *      friendly redirect. The details go to the log, not to the screen.
 *
 * This round deliberately does NOT change what is deletable. Nothing that used
 * to succeed now fails, and nothing that used to fail now succeeds; the failure
 * is just no longer a crash. The archive-vs-hard-delete design for menu items,
 * categories and options is a separate piece of work.
 */
trait HandlesSafeDeletes
{
    /**
     * Run a delete and never let it escape as an exception page.
     *
     * @param  callable  $operation  Does the delete, returns the success response.
     * @param  string    $route      Where the admin lands if it fails.
     * @param  string    $subject    What they tried to delete, e.g. 'the option "Extra Cheese"'.
     * @param  string|null $hint     What to do instead, appended to the message.
     */
    protected function safelyDelete(callable $operation, string $route, string $subject, ?string $hint = null)
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            $isReference = $this->isForeignKeyViolation($e);

            Log::warning('Admin delete refused', [
                'subject' => $subject,
                'foreign_key' => $isReference,
                'sql_state' => $e->getCode(),
                'exception' => $e->getMessage(),
            ]);

            $message = $isReference
                ? 'Cannot delete ' . $subject . ' because other records still refer to it. '
                    . ($hint ?: 'Remove or reassign those records first, or hide it instead of deleting it.')
                : 'Could not delete ' . $subject . ' just now. Nothing was changed — please try again.';

            return redirect()->route($route)->withErrors(['error' => $message]);
        } catch (\Throwable $e) {
            Log::error('Admin delete failed', [
                'subject' => $subject,
                'exception' => $e,
            ]);

            return redirect()->route($route)->withErrors([
                'error' => 'Could not delete ' . $subject . ' just now. Nothing was changed — please try again.',
            ]);
        }
    }

    /**
     * Is this the database refusing to orphan a referencing row?
     *
     * SQLSTATE 23000 covers integrity-constraint violations generally; MySQL
     * narrows it to 1451 (parent row still referenced) and 1452 (child row has
     * no parent). Checked by driver code as well as SQLSTATE so the message
     * stays accurate rather than guessing from the text.
     */
    protected function isForeignKeyViolation(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if (in_array($driverCode, [1451, 1452], true)) {
            return true;
        }

        return (string) $e->getCode() === '23000';
    }

    /** "1 past order" / "3 past orders" — used in the pre-check messages. */
    protected function countLabel(int $count, string $singular, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $singular : ($plural ?: $singular . 's'));
    }
}
