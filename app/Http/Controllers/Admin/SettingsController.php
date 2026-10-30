<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\BranchGcash;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    /**
     * The Account Settings field shows a fixed +63 and takes the 10 digits
     * after it (since Oct 2026, Defect Report 5 item 3), so its message speaks
     * in those terms. The number is still stored as 09XXXXXXXXX.
     */
    public const NUMBER_MESSAGE = 'Enter a valid PH mobile number: 10 digits after +63, starting with 9.';

    /**
     * Save ONE branch's GCash QR code and phone number from Account Settings.
     *
     * Since GCash became per branch (Defect Report 5, item 2 — Oct 2026) this
     * card edits the branch chosen in the "Viewing:" bar, posted as branch_id.
     * With All Branches chosen the card is disabled and nothing is posted; a
     * request without a branch is refused rather than guessed. The card has no
     * on/off switch — that lives on the Branches page — so the switch is left
     * as it is, except that a branch with GCash ON cannot be left without a
     * number (it would stop being offered without anyone choosing that).
     *
     * Owner only: the route is in the owner group and BranchGcash::save()
     * refuses anyone else with a 403.
     */
    public function updateGcashQr(Request $request)
    {
        $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'gcash_qr' => BranchGcash::qrRules(),
            'gcash_phone' => ['nullable', 'string', 'max:20'],
        ], array_merge(BranchGcash::qrMessages('gcash_qr'), [
            'branch_id.required' => 'Select a branch in the Viewing bar to manage its GCash.',
            'branch_id.exists' => 'Select a branch in the Viewing bar to manage its GCash.',
        ]));

        $branch = Branch::findOrFail((int) $request->input('branch_id'));

        $number = null;
        if (filled($request->input('gcash_phone'))) {
            $number = BranchGcash::normalizeNumber($request->input('gcash_phone'));

            if ($number === null) {
                throw ValidationException::withMessages(['gcash_phone' => self::NUMBER_MESSAGE]);
            }
        }

        if ($number === null && BranchGcash::for($branch)['enabled']) {
            throw ValidationException::withMessages([
                'gcash_phone' => 'GCash is on for ' . $branch->name . ', so it needs a number. Turn GCash off on the Branches page first.',
            ]);
        }

        BranchGcash::save($branch, null, $number, $request->file('gcash_qr'));

        // Its own flash key, shown inside the GCash card only. The layout
        // prints `success` as a page-wide banner, which would say it twice.
        return redirect()
            ->route('admin.account')
            ->with('gcash_success', 'GCash payment details for ' . $branch->name . ' saved.');
    }
}
