<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Support\FreeDessertPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * The two endpoints behind the "You earned a reward!" pop-up (Oct 2026). See
 * App\Support\FreeDessertPrompt for the rules.
 *
 *   announce  POST, from the pop-up's script: marks the signed-in customer's
 *             waiting rewards as announced and says what to show, once.
 *   claim     GET, kept for links already sent: goes to More → Vouchers &
 *             Rewards, where every reward is listed with its code and its
 *             "Use now" button. (It used to open the cart's free dessert
 *             chooser, which the reward codes replaced.)
 *
 * Neither takes anything from the request: the customer comes from the
 * session's guard and the rewards from the database.
 */
class FreeDessertPromptController extends Controller
{
    public function announce(): JsonResponse
    {
        $prompt = FreeDessertPrompt::announce();

        return response()->json($prompt === null
            ? ['show' => false]
            : ['show' => true] + $prompt);
    }

    public function claim(): RedirectResponse
    {
        return redirect()->to(route('customer.vouchers') . '#rewards');
    }
}
