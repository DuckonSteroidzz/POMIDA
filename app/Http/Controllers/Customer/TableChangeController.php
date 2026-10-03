<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\TableChange;
use App\Services\TableEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The "Table N · Change table" control on the Dine-In menu and cart.
 *
 * Three endpoints, all behind the `table-change` limiter and CSRF:
 *
 *   change   the customer typed the new table's code
 *   confirm  "Table X is already in use. Join it?" — yes
 *   cancel   — no, stay where I am
 *
 * A QR scan needs no endpoint here: the phone's camera opens the QR's URL, and
 * that door (AuthController::showMenu()) asks App\Services\TableChange the same
 * questions this controller does.
 *
 * Only `table_code` is ever read from the request. A branch_id, table_id or
 * table_number posted alongside it is ignored — the table comes from the code.
 */
class TableChangeController extends Controller
{
    public function change(Request $request)
    {
        if (TableChange::currentSeat() === null) {
            return redirect()->route('customer.menu')->with('error', TableChange::ERR_NOT_DINE_IN);
        }

        $code = trim((string) $request->input('table_code'));

        if ($code === '') {
            return $this->backWithError('Please enter the code printed on your new table.');
        }

        /*
         * The SAME guessing budget as the typed-code door in
         * AuthController::processQr(), on the same key — a second door with its
         * own budget would double what a guesser gets per minute.
         */
        $throttleKey = 'table-code:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, TableEntry::MAX_ATTEMPTS)) {
            return $this->backWithError('Too many attempts. Please wait a minute and try again.');
        }

        RateLimiter::hit($throttleKey, 60);

        // First-entry validation, unchanged: resolveCode() hands the code's
        // table to TableEntry::validate().
        $target = TableEntry::resolveCode($code);

        if ($target === null) {
            return $this->backWithError('That code is not valid. Please check the code printed on your new table, or ask our staff for help.');
        }

        if (!$target['ok']) {
            return $this->backWithError($target['error']);
        }

        RateLimiter::clear($throttleKey);

        $verdict = TableChange::assess($target['branch'], $target['table_number']);

        if ($verdict['kind'] === 'occupied') {
            TableChange::stage($target['table'], $verdict['seat'], true);

            return $this->back();
        }

        if ($verdict['kind'] === 'free') {
            return $this->moveAndReport($request, $target);
        }

        return $this->refuse($verdict);
    }

    public function confirm(Request $request)
    {
        $pending = TableChange::pending();

        if ($pending === null) {
            return $this->backWithError(TableChange::ERR_PENDING_EXPIRED);
        }

        session()->forget(TableChange::PENDING_KEY);

        $target = TableChange::revalidate($pending);

        if (!$target['ok']) {
            return $this->backWithError($target['error']);
        }

        // Asked again: an order may have been placed at the old table, or the
        // new table may have emptied, while the prompt was on screen.
        $verdict = TableChange::assess($target['branch'], $target['table_number']);

        if (in_array($verdict['kind'], ['free', 'occupied'], true)) {
            return $this->moveAndReport($request, $target);
        }

        return $this->refuse($verdict);
    }

    public function cancel()
    {
        session()->forget(TableChange::PENDING_KEY);

        $seat = TableChange::currentSeat();

        return $this->back()->with(
            'success',
            $seat ? "You're still at Table {$seat['table_number']}." : 'Table change cancelled.'
        );
    }

    private function moveAndReport(Request $request, array $target)
    {
        $result = TableChange::move($target['branch'], $target['table_number'], $request->ip(), ($target['table'] ?? null)?->id);

        // Taken out of service between the check above and the claim: they
        // stay where they are, and are told why.
        if (!$result['ok']) {
            return $this->backWithError($result['error']);
        }

        $cartCount = count(session('cart', []));

        $message = ($result['continued'] ?? false)
            ? "You've joined Table {$target['table_number']}'s session."
            : "You've moved to Table {$target['table_number']}.";

        if ($cartCount > 0) {
            $message .= ' Your cart is still here.';
        }

        return $this->back()->with('success', $message);
    }

    private function refuse(array $verdict)
    {
        return match ($verdict['kind']) {
            'same'         => $this->back()->with('success', "You're already at Table {$verdict['seat']['table_number']}."),
            'other_branch' => $this->backWithError(TableChange::ERR_OTHER_BRANCH),
            'active_order' => $this->backWithError(TableChange::activeOrderMessage((string) $verdict['order']->table_number)),
            default        => redirect()->route('customer.menu')->with('error', TableChange::ERR_NOT_DINE_IN),
        };
    }

    /**
     * Always a fixed page, never redirect()->back().
     *
     * The phone-camera door renders the menu AT the QR's URL
     * (/customer/menu?branch_id=…&table=…&k=…), so "back" can be the old
     * table's QR — following it would scan the customer straight back to the
     * table they just left. `return_to` only chooses between the two pages
     * that carry the control.
     */
    private function back()
    {
        return redirect()->route(
            request()->input('return_to') === 'cart' ? 'customer.cart' : 'customer.menu'
        );
    }

    private function backWithError(string $message)
    {
        return $this->back()->with('table_change_error', $message);
    }
}
