<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRating;
use App\Models\Voucher;
use App\Models\DiscountCard;
use App\Services\InventoryDeductionService;
use App\Support\GuestOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Seconds the placeOrder() duplicate-submit lock is held for at most.
     *
     * This is a safety-net ceiling, not the normal hold time — the lock is
     * released in a finally block the instant the request finishes, which in
     * practice is well under a second. The ceiling only matters if PHP were
     * killed mid-request in a way that skips the finally block (a fatal crash
     * rather than a thrown exception, which finally still runs for), so it
     * just needs to be comfortably longer than any real request takes, not
     * tuned to feel instant.
     */
    private const DUPLICATE_SUBMIT_LOCK_SECONDS = 20;

    /**
     * Return the customer's current active order, if any.
     * Active orders are not allowed to place another order.
     */
    private function getActiveCustomerOrder(): ?Order
    {
        $query = Order::whereIn('status', [
            'pending',
            'preparing',
            'serving',
        ]);

        if (Auth::guard('customer')->check()) {
            return $query
                ->where('user_id', Auth::guard('customer')->id())
                ->latest()
                ->first();
        }

        $guestOrderIds = GuestOrders::ids();

        if (!$guestOrderIds) {
            return null;
        }

        return $query
            ->whereIn('id', $guestOrderIds)
            ->latest()
            ->first();
    }

    /**
     * Resolve an order the CURRENT visitor is actually allowed to see or act on.
     *
     * Every customer-facing endpoint that takes an order ID from the URL must
     * go through this, otherwise the ID alone is the only thing standing
     * between a stranger and someone else's order (an IDOR).
     *
     * Rules:
     *  - Logged-in customer -> the order must belong to their user_id.
     *  - Guest (not logged in) -> the order must be the one THIS session
     *    placed (see App\Support\GuestOrders) AND must be a genuine guest order
     *    (user_id IS NULL). The second condition means a guest can never read
     *    an order that belongs to a real account, even by guessing an ID.
     *
     * Returns null when the visitor has no claim to the order; callers decide
     * whether that becomes a 404, a redirect, or a JSON error.
     */
    private function resolveOwnedOrder(int $id, array $with = []): ?Order
    {
        // The rule itself now lives in CustomerOrderAccess so the rating
        // endpoints can apply exactly the same one. This method stays as the
        // in-controller shorthand every existing caller already uses.
        return \App\Services\CustomerOrderAccess::resolveOwnedOrder($id, $with);
    }

    public function placeOrder(Request $request)
    {
        $validated = $request->validate([
            'order_type'            => 'required|in:dine_in,pick_up,walk_in',
            'items'                 => 'required|array|min:1',
            'items.*.menu_item_id'  => 'required|exists:menu_items,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'table_number'          => 'nullable|string',
            'payment_method'        => 'nullable|in:cash,gcash,card',
            'voucher_code_confirmed' => 'nullable|string|max:100',
            'discount_card_id'       => 'nullable|integer|exists:discount_cards,id',
            'discount_type'          => 'nullable|in:pwd,senior',
            'discount_beneficiary_name' => [
                'nullable',
                'string',
                'max:100',
                "regex:/^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ .'-]{1,99}$/u",
            ],
            'discount_beneficiary_id' => [
                'nullable',
                'string',
                'max:100',
                "regex:/^[A-Za-z0-9\-\/ ]+$/",
            ],
            // Deliberately only 'date' here: whether the date is still in the
            // future is DiscountCard::expirationErrorFor()'s call, so the cart
            // preview and checkout cannot end up with two different rules (or
            // two different messages) for the same card.
            'discount_beneficiary_expiration' => 'nullable|date',
            'discount_beneficiary_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        // Branch logic — from session only (set from QR or branch selector)
        $branchId = session('branch_id') ?? $request->input('branch_id');

        // Table number — request first, then session fallback
        $tableNumber = $request->input('table_number') ?? session('table_number');

        if (!$branchId) {
            return back()->withErrors(['error' => 'No branch selected. Please select a branch first.']);
        }

        if ($validated['order_type'] === 'dine_in' && empty($tableNumber)) {
            return back()->withErrors(['table_number' => 'Table number is required for dine-in']);
        }

        /*
         * DUPLICATE-SUBMIT LOCK, added 2026-09-01 alongside removing the cart
         * confirm modal's 5-second countdown.
         *
         * The countdown used to make a genuine double-submit almost
         * impossible by accident: the confirm button was disabled for the
         * whole 5 seconds, so there was no window in which a second POST
         * could be triggered. Removing it exposed that the client-side guard
         * (confirmButton.disabled, set synchronously on click — see
         * confirmOrderNow() in cart.blade.php) is airtight against a literal
         * double-click on the SAME rendered page, but does nothing for a
         * request that reaches the server twice some OTHER way: a browser
         * back-button resubmission, two tabs open to the same cart, or a
         * flaky connection causing a client to retry.
         *
         * This is a true mutex, not an idempotency window: it is held only
         * for the duration of THIS request's processing and is ALWAYS
         * released in the finally block below, success or failure. That is
         * what keeps it from affecting anything it should not:
         *
         *   - it blocks two requests that are genuinely overlapping in time
         *     for the same visitor, which is the actual shape of a
         *     double-submit;
         *   - it does NOT block a second, later order from the same visitor
         *     (the legitimate "second round" case CustomerOrderAccess::
         *     mayOrderAlongside() exists for below) — by the time that
         *     request arrives, the first one has long since finished and
         *     released the lock;
         *   - it does NOT hold the lock through a validation failure, so
         *     fixing a rejected field (wrong table, expired card) and
         *     resubmitting immediately is never wrongly blocked — every
         *     return in this method, success or refusal, is inside the
         *     try block below and releases the lock in finally.
         *
         * Keyed on the same visitor identity RateLimitServiceProvider already
         * uses for its per-visitor limits (account id, or session id for a
         * guest) — two browser tabs from the same guest share one session and
         * therefore one key, which is exactly the case this needs to catch.
         */
        $duplicateSubmitLock = Cache::lock(
            'place-order-inflight:' . \App\Providers\RateLimitServiceProvider::visitorKey($request),
            self::DUPLICATE_SUBMIT_LOCK_SECONDS
        );

        if (!$duplicateSubmitLock->get()) {
            return redirect()
                ->route('customer.cart')
                ->withErrors([
                    'error' => 'This order is already being submitted. Please wait a moment before trying again.',
                ]);
        }

        try {

        /*
         * One order at a time — except for a dine-in customer still seated at
         * the table their open order belongs to, who is simply ordering again
         * during the same visit. CustomerOrderAccess::mayOrderAlongside()
         * holds that rule, and the cart gate in AuthController uses the same
         * one so the two can never disagree.
         */
        $activeOrder = $this->getActiveCustomerOrder();

        if ($activeOrder && !\App\Services\CustomerOrderAccess::mayOrderAlongside($activeOrder)) {
            return redirect()
                ->route('customer.orders')
                ->with(
                    'error',
                    'You already have an ongoing order. Please wait until it is completed or cancelled by staff.'
                );
        }
        /*
         * Build the order lines from the SESSION CART, not from the posted
         * items[] array.
         *
         * The session cart is the authoritative, already-correctly-priced
         * source: it is keyed per distinct selection ("26" for a plain item,
         * "26_8" for the same item with add-on 8) and each entry stores its own
         * unit price including options.
         *
         * The posted items[] only carries menu_item_id + quantity, so it cannot
         * distinguish two lines of the same item with different add-ons. The old
         * code tried to reconcile the two by scanning the cart for the first
         * matching menu_item_id and breaking — which silently billed BOTH lines
         * at the first line's price (a plain ₱80 coffee plus a ₱90 coffee with
         * creamer was charged ₱160 instead of ₱170), and attached the first
         * line's add-ons to both order_items and both stock deductions.
         */
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return back()->withErrors(['items' => 'Your cart is empty.']);
        }

        /*
         * Prices come from CartPricing, which is also what the cart page
         * renders — so the figure the customer was shown and the figure saved
         * on the order are produced by the same code, from the same live menu
         * rows, in every case.
         *
         * Re-deriving here (rather than trusting the price cached in the
         * session) is still the tamper protection it always was. What changed
         * is that the cart page no longer disagrees with it.
         */
        $priced = \App\Support\CartPricing::price($cart);

        if ($priced['missing']) {
            return back()->withErrors(['items' => 'Invalid menu item']);
        }

        $total = $priced['subtotal'];
        $itemsData = [];

        foreach ($priced['lines'] as $line) {
            $itemsData[] = [
                'menu_item' => $line['menu_item'],
                'quantity' => $line['quantity'],
                'subtotal' => $line['subtotal'],
                'unit_price' => $line['unit_price'],
                'option_details' => $line['option_details'],
                'option_ids' => $line['option_ids'],
            ];
        }

        // Check stock availability.
        // Uses InventoryDeductionService so the check accounts for Recipe Ingredients
        // (MenuItemIngredient) and the add-ons the customer actually selected
        // (MenuOptionIngredient) — not only the legacy inventory_item_id link.
        // Amounts are aggregated across lines, so an ingredient shared by several
        // items is checked against the total the whole cart needs.
        // Each $itemsData entry already carries its OWN option ids (taken from
        // its own cart line), so two lines of the same item with different
        // add-ons now deduct their own ingredients instead of both inheriting
        // the first line's.
        $stockLines = [];

        foreach ($itemsData as $data) {
            // Orderability guard, phrased for the customer. Refuses an item with
            // no recipe set at all, and an item whose recipe the current
            // inventory cannot cover — each with its own message, before the
            // more technical per-ingredient shortfall check below.
            if ($reason = $data['menu_item']->orderBlockedReason((int) $data['quantity'])) {
                return back()->withErrors(['items' => $reason]);
            }

            $stockLines[] = [
                'menu_item' => $data['menu_item'],
                'quantity' => $data['quantity'],
                'selected_option_ids' => $data['option_ids'],
            ];
        }

        $stockErrors = app(InventoryDeductionService::class)->validateCartLines($stockLines);

        if (!empty($stockErrors)) {
            return back()->withErrors(['items' => $stockErrors[0]]);
        }

        // Calculate voucher / PWD / Senior Citizen discount.
        // The server is authoritative; values sent by the browser are not trusted.
        $discountAmount = 0;
        $discountType = null;
        $discountCardId = null;
        $discountBeneficiaryName = null;
        $discountBeneficiaryCardNumber = null;
        $discountIdImage = null;
        $discountBeneficiaryExpiration = null;
        $voucherCode = trim((string) $request->input('voucher_code_confirmed'));
        $voucherId = null;
        $voucherClaim = null;
        $discountStatus = 'approved';

        $selectedDiscountCard = null;

        /*
         * Bookkeeping for the bigger-discount comparison (2026-09-02).
         *
         * $cardDiscountAmount stays null when no PWD/Senior discount is in
         * play at all, which is what distinguishes "no card" from "a card
         * worth ₱0.00" — the latter still wins a tie against a ₱0 voucher and
         * must not be silently treated as absent.
         */
        $cardDiscountAmount = null;
        $freshlyUploadedIdImage = null;

        /*
         * A customer may benefit from exactly one promotional mechanism per
         * order — a voucher OR a PWD/Senior discount, never stacked.
         *
         * CHANGED 2026-09-02: sending both used to be REFUSED outright. It is
         * now allowed and resolved automatically — both discounts are
         * validated and priced independently, and only the LARGER one is
         * applied (see the comparison after the voucher branch below). The
         * customer no longer has to work out which is worth more, and the
         * loser is left completely unspent: a voucher that loses keeps its
         * claim for a future order.
         *
         * Still refused: two discount CARDS at once (below). That is a
         * genuine ambiguity about which card is being presented, not a
         * question of which is worth more.
         */
        $hasVoucher = $voucherCode !== '';
        $hasSavedCard = $request->filled('discount_card_id');
        $hasTransactionDiscount = $request->filled('discount_type');

        if ($hasSavedCard && $hasTransactionDiscount) {
            return back()->withErrors([
                'discount_card_id' => 'Please choose only one discount card option.'
            ])->withInput();
        }

        if ($request->filled('discount_card_id')) {
            if (!Auth::guard('customer')->check()) {
                return back()->withErrors([
                    'discount_card_id' => 'A saved discount card can only be used by a logged-in customer.'
                ])->withInput();
            }

            $selectedDiscountCard = DiscountCard::where('id', $request->integer('discount_card_id'))
                ->where('user_id', Auth::guard('customer')->id())
                ->where('is_active', true)
                ->first();

            if (!$selectedDiscountCard) {
                return back()->withErrors([
                    'discount_card_id' => 'The selected discount card is not available.'
                ])->withInput();
            }

            $isVerified = (bool) ($selectedDiscountCard->is_verified ?? false);
            $verificationStatus = strtolower((string) ($selectedDiscountCard->verification_status ?? ''));

            if (!$isVerified && $verificationStatus !== 'verified') {
                return back()->withErrors([
                    'discount_card_id' => 'The selected discount card has not been verified yet.'
                ])->withInput();
            }

            $cardName = trim((string) ($selectedDiscountCard->full_name ?? $selectedDiscountCard->name ?? ''));
            $cardNumber = trim((string) ($selectedDiscountCard->id_number ?? $selectedDiscountCard->card_number ?? ''));
            $cardImage = trim((string) ($selectedDiscountCard->id_image ?? ''));
            $cardExpiration = $selectedDiscountCard->expiration_date;

            if (!$cardName || !$cardNumber || !$cardImage || !$cardExpiration) {
                return back()->withErrors([
                    'discount_card_id' => 'The selected discount card is missing required information. Please update the card before using it.'
                ])->withInput();
            }

            // Same rule, same wording, same answer as the cart preview and the
            // transaction-card branch below — see DiscountCard::expirationErrorFor().
            $cardExpirationError = DiscountCard::expirationErrorFor($cardExpiration);

            if ($cardExpirationError !== null) {
                return back()->withErrors([
                    'discount_card_id' => $cardExpirationError
                ])->withInput();
            }

            $discountType = strtolower((string) $selectedDiscountCard->type) === 'senior'
                ? 'senior'
                : 'pwd';

            $discountCardId = $selectedDiscountCard->id;
            $discountBeneficiaryName = $cardName;
            $discountBeneficiaryCardNumber = $cardNumber;
            $discountBeneficiaryExpiration = $cardExpiration->toDateString();
            $discountIdImage = $cardImage;
            $discountAmount = \App\Models\Order::pwdSeniorDiscountFor($total);
            $cardDiscountAmount = $discountAmount;

            // Staff must approve this transaction-specific use.
            $discountStatus = 'pending';
        } elseif ($request->filled('discount_type')) {
            $discountType = strtolower((string) $request->input('discount_type'));
            $discountBeneficiaryName = trim((string) $request->input('discount_beneficiary_name'));
            $discountBeneficiaryCardNumber = trim((string) (
                $request->input('discount_beneficiary_id')
                ?? $request->input('discount_beneficiary_card_number')
            ));
            $discountBeneficiaryExpiration = $request->input('discount_beneficiary_expiration');

            if (!in_array($discountType, ['pwd', 'senior'], true)) {
                return back()->withErrors([
                    'discount_type' => 'Please select either PWD or Senior Citizen.'
                ])->withInput();
            }

            if (!$discountBeneficiaryName || !$discountBeneficiaryCardNumber ||
                !$discountBeneficiaryExpiration || !$request->hasFile('discount_beneficiary_image')) {
                return back()->withErrors([
                    'discount_type' => 'Please provide the beneficiary name, ID number, expiration date, and ID image.'
                ])->withInput();
            }

            /*
             * The expiry rule that the cart page also previews with. This is
             * the check that must refuse the reported "expiration 01/01/1940"
             * card; the validation rule above no longer duplicates it, so
             * there is exactly one place that decides, and exactly one message.
             */
            $expirationError = DiscountCard::expirationErrorFor($discountBeneficiaryExpiration);

            if ($expirationError !== null) {
                return back()->withErrors([
                    'discount_beneficiary_expiration' => $expirationError
                ])->withInput();
            }

            if ($request->hasFile('discount_beneficiary_image')) {
                /*
                 * The `local` disk (storage/app), NOT `public`.
                 *
                 * Security review 2026-08-31 (Pass 4, item #10): this used to
                 * name the `public` disk here instead of `local`, and the
                 * public disk is exposed through the storage symlink. Because
                 * public/.htaccess serves an existing file directly
                 * (RewriteCond %{REQUEST_FILENAME} !-f), Laravel never ran for
                 * those URLs at all — an anonymous request with no cookies and
                 * no session returned the complete identity document with
                 * HTTP 200. Proven live before this change.
                 *
                 * `local` is not web-reachable. The file is now served only by
                 * DiscountIdController, which authorises the request first;
                 * see App\Services\DiscountIdAccess for who may view one.
                 */
                $discountIdImage = $request->file('discount_beneficiary_image')
                    ->store('discount_ids', 'local');

                /*
                 * Remembered separately so it can be deleted again if this
                 * card ends up LOSING the bigger-discount comparison below.
                 * An identity document that no order references is a privacy
                 * liability sitting in storage for nothing — and only a
                 * freshly uploaded file may ever be deleted here, never the
                 * image belonging to a saved DiscountCard (that one is the
                 * customer's own record and is reused across orders).
                 */
                $freshlyUploadedIdImage = $discountIdImage;
            }

            $discountAmount = \App\Models\Order::pwdSeniorDiscountFor($total);
            $cardDiscountAmount = $discountAmount;

            // Staff must approve this transaction-specific use.
            $discountStatus = 'pending';
        }

        /*
         * The voucher branch is a SEPARATE `if`, not the `elseif` it used to
         * be (2026-09-02).
         *
         * It has to be able to run alongside a PWD/Senior card so both
         * discounts can be priced and compared. Every validation below is
         * unchanged and still runs in full — an invalid voucher is still
         * refused outright even when a valid card would have covered it,
         * because silently ignoring a code the customer typed would leave
         * them believing it was banked for later when it was actually
         * rejected.
         */
        if ($voucherCode) {
            /*
             * The SAME resolver the cart preview uses, so a shared voucher code
             * and a guest's unique claim code are told apart identically on
             * both sides and the claim judged here is the claim spent below.
             */
            $resolved = \App\Services\VoucherClaims::resolveTypedCode($voucherCode);
            $voucher  = $resolved['voucher'];
            $voucherClaim = $resolved['claim'];

            if (!$voucher) {
                return back()->withErrors([
                    'voucher_code_confirmed' => 'Invalid voucher code.'
                ])->withInput();
            }

            // Same rules, same method, same answer as the cart preview.
            // This previously only called isValid(), which skipped the
            // minimum-order, valid-from and ownership rules — so a voucher the
            // cart page had already rejected was still honoured at checkout.
            $voucherError = $voucher->redemptionErrorFor(
                Auth::guard('customer')->user(),
                $total,
                $voucherClaim
            );

            if ($voucherError !== null) {
                return back()->withErrors([
                    'voucher_code_confirmed' => $voucherError
                ])->withInput();
            }

            /*
             * The specific claim row being spent, so it can be marked used
             * inside the order transaction below.
             *
             *  - Typed a CLAIM code -> resolveTypedCode() already named the
             *    exact row, and it must not be second-guessed here.
             *  - Typed a SHARED code as a signed-in customer -> fall back to
             *    their own oldest unused claim, exactly as before.
             *  - A public promo code (points_required = 0) -> null, as before.
             */
            if (!$voucherClaim) {
                $voucherClaim = $voucher->unusedClaimFor(Auth::guard('customer')->user());
            }

            $voucherDiscountAmount = $voucher->discountFor($total);

            /*
             * WHICH DISCOUNT WINS (2026-09-02).
             *
             * Both are now priced against the same subtotal, and only the
             * larger is applied — never both. Strictly greater-than, so a
             * PWD/Senior card WINS A TIE and the voucher is left unspent.
             *
             * That tie-break is deliberately the customer-favouring one: on
             * equal money today they keep the voucher for a future order, and
             * the PWD/Senior entitlement (which is a legal right, not a
             * promotion that can run out) costs them nothing to use. Picking
             * the voucher on a tie would burn a claim for no extra benefit.
             */
            $voucherWins = \App\Models\Order::voucherBeatsCard(
                $voucherDiscountAmount,
                $cardDiscountAmount
            );

            if ($voucherWins) {
                $discountAmount = $voucherDiscountAmount;
                $discountType = 'voucher';
                $voucherId = $voucher->id;
                $discountStatus = 'approved';

                /*
                 * The card lost: erase every trace of it from this order so
                 * nothing downstream reads it as applied. Without this the
                 * order would carry a discount_card_id and a beneficiary name
                 * next to a voucher discount, and discount_status would still
                 * be 'pending' — parking a voucher order in the staff
                 * ID-verification queue for a card that was never used.
                 */
                if ($cardDiscountAmount !== null) {
                    $discountCardId = null;
                    $discountBeneficiaryName = null;
                    $discountBeneficiaryCardNumber = null;
                    $discountBeneficiaryExpiration = null;
                    $discountIdImage = null;

                    // Only ever a file uploaded in THIS request — a saved
                    // card's own image is the customer's record and is never
                    // touched here.
                    if ($freshlyUploadedIdImage !== null) {
                        Storage::disk('local')->delete($freshlyUploadedIdImage);
                        $freshlyUploadedIdImage = null;
                    }
                }
            } else {
                /*
                 * The card wins, so the voucher is NOT spent: both of these
                 * drive consumption inside the order transaction below
                 * ($voucherId increments used_count, $voucherClaim marks the
                 * claim used). Clearing them is what lets the customer keep
                 * the voucher for another day.
                 */
                $voucherId = null;
                $voucherClaim = null;
            }
        }

        // Round the discount ONCE, then derive the total from that rounded
        // figure. Rounding the discount and the total independently drifted by a
        // centavo whenever the raw discount landed on a half-centavo, so the
        // receipt could show subtotal - discount != total.
        $discountAmount = round($discountAmount, 2);
        $finalTotal = max(0, round($total - $discountAmount, 2));

        try {
            $order = DB::transaction(function () use (
                $validated,
                $itemsData,
                $total,
                $finalTotal,
                $discountAmount,
                $branchId,
                $voucherCode,
                $cart,
                $tableNumber,
                $discountType,
                $discountCardId,
                $discountBeneficiaryName,
                $discountBeneficiaryCardNumber,
                $discountBeneficiaryExpiration,
                $discountIdImage,
                $voucherId,
                $voucherClaim,
                $discountStatus
            ) {
                $order = Order::create([
                    'user_id' => Auth::guard('customer')->id(),
                    'branch_id' => $branchId,
                    'type' => $validated['order_type'],
                    'table_number' => $tableNumber ?? null,
                    'order_number' => 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                    'subtotal' => $total,
                    'total' => $finalTotal,
                    'discount_amount' => $discountAmount,
                    'discount_type' => $discountType,
                    'discount_card_id' => $discountCardId,
                    'voucher_id' => $voucherId,
                    'discount_beneficiary_name' => $discountBeneficiaryName,
                    'discount_beneficiary_card_number' => $discountBeneficiaryCardNumber,
                    'discount_beneficiary_expiration' => $discountBeneficiaryExpiration,
                    'discount_id_image' => $discountIdImage,
                    'discount_status' => $discountStatus,
                    'tax_amount' => 0,
                    'status' => 'pending',
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'payment_status' => 'pending',
                    'amount_paid' => 0,
                    'change_amount' => 0,
                ]);

                foreach ($itemsData as $data) {
                    // Price and add-ons come from THIS line's own cart entry
                    // (resolved above), not from a first-match scan of the whole
                    // cart, so repeat items with different add-ons bill correctly.
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $data['menu_item']->id,
                        'item_name' => $data['menu_item']->name,
                        'quantity' => $data['quantity'],
                        'item_price' => $data['unit_price'],
                        'subtotal' => $data['subtotal'],
                    ]);

                    foreach ($data['option_details'] as $opt) {
                        DB::table('order_item_options')->insert([
                            'order_item_id' => $orderItem->id,
                            'menu_option_id' => $opt['id'],
                            'option_name' => $opt['name'],
                            'additional_price' => $opt['price'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                /*
                 * Spend the voucher, inside the same transaction that creates
                 * the order — if anything above rolls back, it stays unspent.
                 *
                 * Both writes (the shared used_count and the specific claim
                 * row) live in VoucherClaims::redeem(), which the admin Manual
                 * Order flow calls too, so a code is spendable exactly once no
                 * matter which path spends it. A LOSING voucher never gets here:
                 * the bigger-discount comparison above cleared both arguments to
                 * null, which is how the loser is left completely untouched.
                 */
                \App\Services\VoucherClaims::redeem($voucherId, $voucherClaim);

                return $order;
            });

            // Clear cart
            session()->forget(['cart', 'voucher_code']);

            // The order transaction has committed by this point, so the counter
            // can safely be told about it. Manual/walk-in orders never reach
            // here — staff key those in themselves via storeManualOrder().
            \App\Models\Notification::newOrderForStaff($order);

            // Track the most recent order so the customer can still be
            // notified if staff completes/cancels it before the page reloads.
            session()->put('customer_order_id', $order->id);

            // Guest/dine-in customers are tracked by the set of orders this
            // session placed, so a second round during the same visit does not
            // orphan the first one.
            if (!Auth::guard('customer')->check()) {
                GuestOrders::remember($order->id);
            }

            /*
             * Bind this order to the table occupancy the customer is holding.
             * Two things depend on it: the table is released automatically when
             * the order reaches completed/cancelled, and an occupancy with a
             * linked order can never be treated as abandoned, so a long meal
             * cannot have its table taken from underneath it.
             *
             * Deliberately after the guest order set is updated, since that is one of the
             * ownership signals TableOccupancy uses.
             */
            \App\Services\TableOccupancy::attachOrder($order);

            // Reset points (only if logged in)
            if (Auth::guard('customer')->check()) {
                /** @var \App\Models\User $user */
                $user = Auth::guard('customer')->user();
                $user->points = 0;
                $user->save();
            }

// PWD/Senior orders must wait for staff verification.
// Send the customer to the cart page because its existing
// discount-checking modal is triggered by these session values.
if (
    in_array(strtolower((string) $discountType), ['pwd', 'senior'], true)
    && $discountStatus === 'pending'
) {
    return redirect()
        ->route('customer.cart')
        ->with([
            'discount_pending' => true,
            'pending_order_id' => $order->id,
        ]);
}

// GCash orders go to the GCash payment page.
// The order total already contains the approved voucher discount
// or the currently calculated PWD/Senior discount.
if (($validated['payment_method'] ?? 'cash') === 'gcash') {
    return redirect()
        ->route('customer.gcash-payment', $order->id);
}

// Cash orders continue using the normal order flow.
return redirect()->route('customer.orders')
    ->with('success', 'Order placed! 🎉 Order #' . $order->order_number);
        } catch (\RuntimeException $e) {
            // Deliberate, user-facing refusals thrown inside the transaction
            // (voucher already used, not enough stock). The message is written
            // for the customer, so show it as-is.
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Anything else is a bug or an infrastructure failure. Its message
            // can contain SQL, file paths or credentials, and APP_DEBUG=false
            // does NOT filter a message we print ourselves - so log it and show
            // the customer a generic line instead.
            Log::error('Order placement failed', [
                'user_id'   => Auth::id(),
                'exception' => $e,
            ]);

            return back()->withErrors([
                'error' => 'Sorry, we could not place your order just now. '
                    . 'Nothing was charged. Please try again, or ask staff for help.',
            ]);
        }
        } finally {
            // Always released here, success or failure — see the long comment
            // on the lock's acquisition above for why this is a short-lived
            // mutex and not a hold-after-success debounce window.
            $duplicateSubmitLock->release();
        }
    }
    /**
 * Show the GCash payment page for an order.
 */
public function showGcashPayment(int $id)
{
    // Scope by owner first: without this, any visitor could read another
    // customer's GCash order (total, items, order number) by guessing the ID.
    $order = $this->resolveOwnedOrder($id, ['items']);

    if (!$order) {
        abort(404);
    }

    // Only GCash orders can access this page.
    if ($order->payment_method !== 'gcash') {
        return redirect()
            ->route('customer.orders')
            ->withErrors([
                'payment' => 'This order does not use GCash.'
            ]);
    }

    // Only the initial payment state should show the QR.
    if (!in_array($order->payment_status, ['pending', 'awaiting_verification'])) {
        return redirect()
            ->route('customer.orders');
    }

        $gcashQrSetting = \App\Models\Setting::where('key', 'gcash_qr')
            ->whereNull('branch_id')
            ->first();

        $gcashPhoneSetting = \App\Models\Setting::where('key', 'gcash_phone')
            ->whereNull('branch_id')
            ->first();

        return view('customer.gcash-payment', [
            'order' => $order,
            'gcashQrSetting' => $gcashQrSetting,
            'gcashPhoneSetting' => $gcashPhoneSetting,
        ]);
}

/**
 * Customer confirms that they have completed the GCash payment.
 */
public function markGcashAsPaid(int $id)
{
    // Scope by owner first. Unscoped, anyone could push a stranger's pending
    // GCash order into the staff verification queue as a false "I paid"
    // claim — the state that staff are asked to approve.
    $order = $this->resolveOwnedOrder($id);

    if (!$order) {
        abort(404);
    }

    if ($order->payment_method !== 'gcash') {
        return redirect()
            ->route('customer.orders')
            ->withErrors([
                'payment' => 'This order does not use GCash.'
            ]);
    }

    if ($order->payment_status !== 'pending') {
        return redirect()
            ->route('customer.gcash-payment', $order->id);
    }

    $order->payment_status = 'awaiting_verification';
    $order->save();

    // Put it in front of the counter. Before this, staff had no signal that a
    // GCash payment was waiting on them except refreshing the dashboard.
    \App\Models\Notification::gcashAwaitingVerification($order);

    return redirect()
        ->route('customer.gcash-payment', $order->id)
        ->with('success', 'Your payment has been submitted for verification.');
}

/**
 * Check the current GCash payment status.
 * Used by the customer payment page to detect
 * staff approval/rejection without refreshing.
 */
public function gcashPaymentStatus(int $id)
{
    $query = \App\Models\Order::where('id', $id);

    // Logged-in customer
    if (Auth::guard('customer')->check()) {
        $query->where('user_id', Auth::guard('customer')->id());
    } 
    // Guest / dine-in customer
    else {
        $query->whereIn('id', GuestOrders::ids() ?: [0]);
    }

    $order = $query->first();

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    return response()->json([
        'success' => true,
        'payment_status' => $order->payment_status,
        'order_status' => $order->status,
    ]);
}
    /**
     * Allow the customer to continue a rejected-discount order at the regular price.
     */
    public function continueWithoutDiscount(Request $request, int $id)
    {
        $query = Order::where('id', $id);

        if (Auth::guard('customer')->check()) {
            $query->where('user_id', Auth::guard('customer')->id());
        } else {
            $query->whereIn('id', GuestOrders::ids() ?: [0]);
        }

        $order = $query->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This order can no longer be changed.'
            ], 422);
        }

        // Customer chose Continue Transaction.
        // Write the decision directly to the database so the next polling
        // request cannot receive the old rejected state from this model.
        DB::table('orders')
            ->where('id', $order->id)
            ->update([
                'discount_status' => 'approved',
                'discount_amount' => 0,
                'discount_type' => null,
                'total' => $order->subtotal,
                'updated_at' => now(),
            ]);

        $order = Order::find($order->id);

        if (!$order || $order->discount_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'The discount decision could not be saved.'
            ], 500);
        }

        // Remember this exact order in the customer's session.
        session()->put('discount_continued_order_id', $order->id);
        session()->forget(['discount_pending', 'pending_order_id']);
        session()->save();

        return response()->json([
            'success' => true,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'discount_status' => 'approved',
            'discount_amount' => 0,
            'total' => (float) $order->total,
            'message' => 'The order will continue at the regular price.'
        ]);
    }

    /**
 * Cancel the customer's OWN order, while it is still pending.
 *
 * Two callers, deliberately sharing one endpoint and therefore one set of
 * rules:
 *
 *   1. the discount-rejection popup on the orders page (fetch/JSON), which
 *      is what this method was originally written for; and
 *   2. the general "Cancel Order" button added 2026-09-02 (plain form POST),
 *      which is self-service cancellation for any pending order.
 *
 * They differ only in how the answer is delivered, so the response shape is
 * negotiated at the end rather than the logic being duplicated. Every rule
 * below — ownership, the pending-only window, the refund handling — is
 * enforced here on the server, because the button simply not being drawn is
 * a UI convenience and not a control: this route is a guessable integer away
 * from anyone who wants to POST at it directly.
 */
public function cancelCustomerOrder(Request $request, int $id)
{
    /*
     * Ownership first, before anything is read back to the caller.
     *
     * For a signed-in customer that means their own user_id; for a guest
     * (QR/dine-in) it means an order id this browser session actually placed.
     * Without this, order ids being sequential integers would let anyone
     * cancel a stranger's order by counting upward — the same reasoning as
     * resolveOwnedOrder() elsewhere in this controller.
     */
    $query = Order::where('id', $id);

    if (Auth::guard('customer')->check()) {
        $query->where('user_id', Auth::guard('customer')->id());
    } else {
        $query->whereIn('id', GuestOrders::ids() ?: [0]);
    }

    $order = $query->first();

    if (!$order) {
        /*
         * A hard 404 for BOTH callers, deliberately — this is the one refusal
         * that does not use cancelResponse() below.
         *
         * Two reasons. Semantically, an order the visitor has no claim to
         * should not exist as far as they are concerned, and the same 404
         * covers "no such order" and "not yours" so the endpoint cannot be
         * used to work out which ids are real. And practically,
         * ReceiptAccessTest pins exactly this: a guest holding only the
         * read-only past-visit archive may still read their receipt but gets
         * a 404 from every endpoint that ACTS on the order.
         *
         * Returning the redirect shape here instead would have quietly
         * downgraded that to a 302 — the order still would not have been
         * cancelled, but a pinned security contract would have changed. The
         * JSON body is kept identical to what the discount-rejection popup
         * has always received.
         */
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        abort(404);
    }

    /*
     * Pending only — the hard rule.
     *
     * Once staff have started cooking (preparing), plated (serving) or closed
     * the order (completed), the customer no longer gets to withdraw it
     * unilaterally: food and staff time have already been spent, and the
     * counter has to be the one to decide what happens. 'cancelled' is caught
     * by the same check, which also makes this endpoint safely repeatable —
     * a double-submit cancels once and is refused the second time rather than
     * re-firing the refund notification below.
     */
    if ($order->status !== 'pending') {
        return $this->cancelResponse(
            $request,
            false,
            'This order can no longer be cancelled.',
            422
        );
    }

    /*
     * Did the customer already tell us they paid?
     *
     * 'awaiting_verification' is set by markGcashAsPaid() when the customer
     * taps "I have paid" — it means money may genuinely have left their
     * GCash account and is sitting unverified. There is no merchant API in
     * this system, so nothing here can reverse that; a refund is a person at
     * the counter sending money back. Such a cancellation therefore must NOT
     * settle as a plain one, or the refund quietly never happens.
     *
     * A GCash order cancelled BEFORE that tap has payment_status 'pending' —
     * no money moved, nothing to refund — and is treated exactly like cash.
     */
    $needsRefund = $order->payment_method === 'gcash'
        && $order->payment_status === 'awaiting_verification';

    $order->status = 'cancelled';
    $order->cancelled_at = now();

    if ($needsRefund) {
        $order->payment_status = 'refund_pending';

        $order->cancellation_reason =
            'Cancelled by the customer after they marked it paid via GCash. '
            . 'Refund must be sent manually.';
    } else {
        $order->cancellation_reason = 'Cancelled by the customer.';
    }

    $order->save();

    if ($needsRefund) {
        // Fired only after a successful save: notifying staff about a refund
        // for an order that failed to cancel would send them chasing money
        // for a live order.
        \App\Models\Notification::refundPending($order);
    }

    return $this->cancelResponse(
        $request,
        true,
        $needsRefund
            ? 'Your order was cancelled. Our staff have been notified to process your refund.'
            : 'Your order has been cancelled.'
    );
}

/**
 * Answer a cancellation in whichever shape the caller asked for.
 *
 * The discount popup speaks JSON and reads `success`/`message`; the plain
 * "Cancel Order" form is an ordinary browser POST that must land back on the
 * orders page rather than dumping raw JSON on screen. Keeping this in one
 * place means the two callers can never drift apart on status codes or
 * wording.
 */
private function cancelResponse(Request $request, bool $ok, string $message, int $status = 200)
{
    if ($request->expectsJson() || $request->ajax()) {
        return response()->json([
            'success' => $ok,
            'message' => $message,
        ], $status);
    }

    if (!$ok) {
        return redirect()
            ->route('customer.orders')
            ->withErrors(['cancel' => $message]);
    }

    return redirect()
        ->route('customer.orders')
        ->with('success', $message);
}

    public function showOrders()
    {
        // Guest users (QR scan dine-in) - track via session
        if (!Auth::guard('customer')->check()) {
            $guestOrderIds = GuestOrders::ids();
            $currentOrders = collect();
            if ($guestOrderIds) {
                /*
                 * EVERY still-open order this visit produced, newest first —
                 * not just the newest one.
                 *
                 * A party can legitimately have several orders in flight now
                 * (dine-in second round since item 43, pick-up follow-up since
                 * this item). ->first() meant the earlier order vanished from
                 * the customer's own Orders page the instant they placed
                 * another, so they could no longer see its progress, its
                 * total, or that it existed at all.
                 */
                $currentOrders = Order::with(['items.menuItem', 'items.options', 'discountCard', 'voucher', 'customer'])
                    ->whereIn('id', $guestOrderIds)
                    ->whereIn('status', ['pending', 'preparing', 'serving'])
                    ->orderByDesc('id')
                    ->get();
            }
            $currentOrder = $currentOrders->first();
            $orderHistory = collect();
            $orderRatings = collect();

            /*
             * What the live poll (customer.orders-status) should keep asking
             * about — see the docblock on customerOrdersStatus() for why this
             * has to be the FULL set a guest ever placed, not just whichever
             * ones are still in-flight right now. A guest has no History tab,
             * so this is the only path back to a receipt for an order that
             * finished before this exact page load.
             */
            $pollableOrderIds = $guestOrderIds;

            // Pick-up customers can't call a server to a table, so the order
            // page shows them the shop's real contact details instead.
            $storeContact = \App\Support\StoreContact::forBranch(
                session('branch_id') ?: $currentOrders->first()?->branch_id
            );

            return view('customer.orders', compact(
                'currentOrders', 'currentOrder', 'orderHistory', 'orderRatings', 'pollableOrderIds', 'storeContact'
            ));
        }

        // Same reasoning as the guest branch above: all of them, newest first.
        $currentOrders = Order::with(['items.menuItem', 'items.options', 'discountCard', 'voucher', 'customer'])
            ->where('user_id', Auth::guard('customer')->id())
            ->whereIn('status', ['pending', 'preparing', 'serving'])
            ->orderByDesc('id')
            ->get();

        $currentOrder = $currentOrders->first();

        $orderHistory = Order::with([
            'discountCard',
            'voucher',
        ])->withCount('items')
            ->where('user_id', Auth::guard('customer')->id())
            ->whereIn('status', ['completed', 'cancelled'])
            ->latest()
            ->get();

        /*
         * Keyed on order_id, not user_id. A rating belongs to an ORDER — that
         * is where the unique constraint sits — and user_id is now nullable so
         * guests can rate. $orderHistory is already scoped to this customer, so
         * matching on order_id is both narrower and correct.
         */
        $orderRatings = OrderRating::whereIn('order_id', $orderHistory->pluck('id'))
            ->get()
            ->keyBy('order_id');

        /*
         * A logged-in customer's own History tab is a permanent, non-poll
         * path back to any finished order, so the live poll only needs to
         * cover what is genuinely still in flight right now.
         */
        $pollableOrderIds = $currentOrders->pluck('id')->all();

        $storeContact = \App\Support\StoreContact::forBranch(
            session('branch_id')
                ?: $currentOrders->first()?->branch_id
                ?: Auth::guard('customer')->user()?->branch_id
        );

        return view('customer.orders', compact(
            'currentOrders', 'currentOrder', 'orderHistory', 'orderRatings', 'pollableOrderIds', 'storeContact'
        ));
    }

    /**
     * Save (or update) a customer's star rating for a completed order.
     * Only dine_in / pick_up orders can be rated — same rule the blade
     * already enforces client-side via $canRate, re-checked here so it
     * can't be bypassed by posting to this endpoint directly.
     */
    public function submitRating(Request $request, int $id)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'rating_type' => 'nullable|string|max:20',
        ]);

        // One shared decision for both entry points — the Order History modal
        // and the in-place control in the order-completed popup — and for both
        // logged-in customers and guests.
        $check = \App\Services\CustomerOrderAccess::resolveRatableOrder($id);

        if (!$check['ok']) {
            return $this->ratingFailure($request, $check);
        }

        $order = $check['order'];

        $rating = OrderRating::create([
            'order_id'    => $order->id,
            // Null for a guest: there is no account behind the rating, and the
            // UNIQUE(order_id) constraint is what stops a second submission.
            'user_id'     => Auth::guard('customer')->id(),
            'rating_type' => $validated['rating_type'] ?? 'stars',
            'rating'      => $validated['rating'],
        ]);

        if ($this->wantsJson($request)) {
            return response()->json([
                'ok'      => true,
                'rating'  => $rating->rating,
                'message' => 'Thanks for rating your meal!',
            ]);
        }

        return redirect()->back()->with('rating_success', 'Thanks for rating your meal!');
    }

    /**
     * The rating already stored for an order this visitor owns.
     *
     * Lets the completed-order popup show "you rated this 4/5" when it is
     * reopened, instead of offering an empty control that would be rejected.
     */
    public function orderRating(Request $request, int $id)
    {
        $existing = \App\Services\CustomerOrderAccess::existingRatingFor($id);

        return response()->json([
            'rated'  => $existing !== null,
            'rating' => $existing?->rating,
        ]);
    }

    /** Rating requests arrive both as normal form posts and as fetch() calls. */
    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    /**
     * @param array{error?: string, status?: int, rating?: \App\Models\OrderRating} $check
     */
    private function ratingFailure(Request $request, array $check)
    {
        $message = $check['error'] ?? 'This order cannot be rated.';
        $status = $check['status'] ?? 422;

        if ($this->wantsJson($request)) {
            return response()->json([
                'ok'      => false,
                'message' => $message,
                'rated'   => isset($check['rating']),
                'rating'  => $check['rating']->rating ?? null,
            ], $status);
        }

        if ($status === 404) {
            abort(404);
        }

        return redirect()->back()->withErrors(['rating' => $message]);
    }

    /**
     * Lightweight status endpoint used by the customer pages.
     * It lets the customer see when staff completes or cancels an order
     * without refreshing the entire page.
     */
    /**
     * Lightweight status endpoint used by the customer pages.
     *
     * The browser can pass the current order ID while an order is active.
     * Once the order becomes completed/cancelled, the same order ID is
     * still checked, so the notification cannot be missed by a page reload.
     */
    
    public function customerOrderStatus(Request $request)
    {
        $requestedOrderId = $request->integer('order_id');
        $trackedOrderId = $requestedOrderId ?: session('customer_order_id');

        if (!$trackedOrderId) {
            return response()->json([
                'has_order' => false,
            ]);
        }

        $query = Order::where('id', $trackedOrderId);

        if (Auth::guard('customer')->check()) {
            $query->where('user_id', Auth::guard('customer')->id());
        } else {
            $query->whereIn('id', GuestOrders::ids() ?: [0]);
        }

        $order = $query->first();

        if (!$order) {
            return response()->json([
                'has_order' => false,
            ]);
        }

        return response()->json(array_merge(
            ['has_order' => true],
            $this->orderStatusPayload($order)
        ));
    }

    /**
     * Every order this visitor should be polling for right now — the
     * multi-order counterpart to customerOrderStatus() above.
     *
     * THE BUG THIS EXISTS FOR
     * -----------------------
     * Item 43 (dine-in second round) and Round 3A (pick-up follow-up) both
     * made it legitimate for a visitor to have MORE THAN ONE order open at
     * once, and showOrders() already lists every one of them. But the poll
     * that keeps those cards' Pending/Preparing/Serving trackers live, and the
     * "your order is done, here is your receipt" popup, both still resolved
     * exactly ONE order — whichever id session('customer_order_id') happened
     * to hold, which is overwritten to the JUST-PLACED order every time
     * placeOrder() runs. Reported live: a customer's SECOND order sat frozen
     * on "Pending" on their own Orders page while the admin board correctly
     * showed it moving through Preparing and Serving — the newer order (in
     * session) tracked perfectly; the older one was simply never asked about.
     *
     * WHY THE CANDIDATE IDS COME FROM THE CLIENT, NOT A SERVER-SIDE QUERY
     * --------------------------------------------------------------------
     * The first version of this endpoint re-derived "every order still in
     * flight" from the database on each poll (whereIn('status', [...])) for a
     * logged-in customer. That is wrong in a subtle way that only shows up at
     * the exact moment it matters: the instant an order transitions to
     * completed/cancelled, it no longer MATCHES that status filter — so the
     * one poll that needed to notice the transition is the one poll that can
     * no longer see the order at all. The customer's popup and receipt
     * shortcut for THAT order would never fire; only the History tab would
     * eventually show it. Reproduced live: two orders both genuinely
     * completed server-side, but only whichever one session('customer_order_id')
     * happened to still be tracking got its popup.
     *
     * The fix is to ask about the exact ids the PAGE is currently showing
     * tracker cards for — embedded into the page as trackedOrderIds when
     * showOrders() rendered it — rather than re-deriving "what is still
     * in-flight" fresh on every request. Those ids keep being asked about for
     * as long as this page stays open, so the poll that catches the
     * transition is looking at the right id at the right moment regardless of
     * what its status has become by then.
     *
     * Ownership is still resolved purely server-side, exactly like
     * customerOrderStatus() above and every other customer endpoint in this
     * file: the client can only ever narrow the result to orders it already
     * owns, never widen it. Passing someone else's id here returns nothing for
     * that id, the same IDOR protection as the singular endpoint.
     *
     *  - Logged-in customer: any of the requested ids that belong to them,
     *    in whatever status they are now — completed included, which is the
     *    entire point.
     *
     *  - Guest: the requested ids UNIONED with the full set this browser
     *    session has ever placed (App\Support\GuestOrders::ids()), not just
     *    what the client happened to ask about. A guest has no History tab at
     *    all, so this is the only way back to a receipt once an order leaves
     *    the Current Order tab — narrowing it to only the client-supplied ids
     *    would silently drop an order the guest's page never got a chance to
     *    embed (e.g. it finished between visits). GuestOrders is already
     *    capped (MAX_TRACKED = 25), so this stays bounded.
     */
    public function customerOrdersStatus(Request $request)
    {
        $requestedIds = collect($request->input('order_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        if (Auth::guard('customer')->check()) {
            $orders = $requestedIds->isEmpty()
                ? collect()
                : Order::where('user_id', Auth::guard('customer')->id())
                    ->whereIn('id', $requestedIds->all())
                    ->orderByDesc('id')
                    ->get();
        } else {
            $candidateIds = $requestedIds->merge(GuestOrders::ids())->unique();

            $orders = $candidateIds->isEmpty()
                ? collect()
                : Order::whereIn('id', $candidateIds->all())
                    ->whereNull('user_id')
                    ->orderByDesc('id')
                    ->get()
                    ->filter(fn (Order $order) => GuestOrders::owns($order->id))
                    ->values();
        }

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => array_merge(
                ['has_order' => true],
                $this->orderStatusPayload($order)
            ))->values(),
        ]);
    }

    /**
     * The fields both order-status endpoints answer with, for one order.
     * Pulled out so the single-order and multi-order responses can never
     * describe the same order differently.
     */
    private function orderStatusPayload(Order $order): array
    {
        $discountStatus = $order->discount_status ?? 'approved';
        $discountType = $order->discount_type;
        $discountAmount = (float) ($order->discount_amount ?? 0);

        // If the customer already chose Continue Transaction for this
        // exact order, the customer-facing status must stay approved even
        // if an old/stale rejected value is encountered.
        if ((int) session('discount_continued_order_id') === (int) $order->id) {
            $discountStatus = 'approved';
            $discountType = null;
            $discountAmount = 0;
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            // Drives the customer tracker's step-3 label and helper text
            // ("Ready for Pick-up" vs "Served"). Additive — existing consumers
            // ignore it.
            'type' => $order->type,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'discount_status' => $discountStatus,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'total' => (float) ($order->total ?? 0),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
        ];
    }

    public function showReceipt(int $id)
    {
        // Guest checkout still needs to reach its own receipt, but "guest"
        // previously meant "anyone not logged in", with no ownership check at
        // all — so a logged-out visitor could read any registered customer's
        // receipt by incrementing the ID. resolveOwnedOrder() keeps the guest
        // case working (the order this session placed, and only if it is a
        // genuine guest order) while closing the enumeration.
        /*
         * $includePastVisits = true, and ONLY here.
         *
         * A receipt is a read-only record of something the visitor already
         * paid for, so it stays reachable after their dine-in visit ends and
         * the active guest claim set is cleared — which is what made this
         * endpoint 404 straight after an order was completed. Every other
         * endpoint (cancel, rate, continue-without-discount, GCash) keeps the
         * narrow claim, so nothing that can CHANGE an order got wider.
         */
        $order = \App\Services\CustomerOrderAccess::resolveOwnedOrder($id, [
            'items.menuItem',
            'items.options',
            'discountCard',
            'voucher',
            'customer',
        ], true);

        if (!$order) {
            abort(404);
        }

        // Pick-up customers get the shop's real phone/email on the receipt too —
        // a refund question often surfaces here. See App\Support\StoreContact.
        $storeContact = \App\Support\StoreContact::forBranch($order->branch_id);

        return view('customer.receipt', compact('order', 'storeContact'));
    }
}
