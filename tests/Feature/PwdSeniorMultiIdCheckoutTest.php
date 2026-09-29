<?php

namespace Tests\Feature;

use App\Models\DiscountCard;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderDiscountBeneficiary;
use App\Models\User;
use App\Models\Voucher;
use App\Support\DiscountBeneficiaries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PWD / Senior Citizen: many IDs on one order, ONE discount (September 2026).
 *
 * A group order can include more than one PWD or Senior Citizen. Checkout and
 * the counter now record each of them — ID number + full name, one row per
 * person — in order_discount_beneficiaries. The rows are a record for the
 * receipt and staff, never a multiplier.
 *
 * THE RULE THESE TESTS PIN: the discount is the EXISTING calculation,
 * Order::pwdSeniorDiscountFor($subtotal) — 20% of the whole order subtotal,
 * capped at the subtotal — applied exactly once per order, whatever the row
 * count. That target (the whole subtotal, not one dish) is what the code did
 * before this change; these tests keep it, they do not redefine it.
 *
 * Found during investigation, before any change: the reported stacking did
 * NOT reproduce — an order could only ever hold one ID, and posting three
 * gave one 20% discount. The real defect was data loss: the second and third
 * ID were silently dropped. So the headline test here is a regression guard
 * for the new multi-row shape: 1, 2, 5, 12 or 50 IDs all earn the same one
 * discount.
 *
 * Also pinned: checkout no longer collects or stores an ID photo (staff check
 * the physical ID in person, as the counter always has), and the PWD expiry
 * eligibility check is unchanged.
 */
class PwdSeniorMultiIdCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing should be written to either disk any more; faking both lets
        // the photo test prove that, and stops a regression leaking real files.
        Storage::fake('local');
        Storage::fake('public');
    }

    // ══════════ fixtures ══════════

    private function customer(): User
    {
        return User::where('role', 'customer')->orderBy('id')->firstOrFail();
    }

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function cart(MenuItem $item, int $qty): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    /** $n distinct, valid ID rows in the repeatable-row shape. */
    private function rows(int $n, string $prefix = 'SC'): array
    {
        $rows = [];

        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['id_number' => $prefix . '-' . (1000 + $i), 'full_name' => 'Guest Number ' . $this->word($i)];
        }

        return $rows;
    }

    /** Names must be letters only (the shared NAME_REGEX), so spell the index. */
    private function word(int $i): string
    {
        $letters = '';

        do {
            $letters = chr(ord('a') + ($i % 26)) . $letters;
            $i = intdiv($i, 26);
        } while ($i > 0);

        return ucfirst($letters);
    }

    private function seniorWith(array $rows): array
    {
        return ['discount_type' => 'senior', 'discount_beneficiaries' => $rows];
    }

    private function pwdWith(array $rows, ?string $expiration = null): array
    {
        return [
            'discount_type'                   => 'pwd',
            'discount_beneficiaries'          => $rows,
            'discount_beneficiary_expiration' => $expiration ?? now()->addYear()->format('n/j/Y'),
        ];
    }

    /**
     * Place a customer checkout. Returns the response, the order created (or
     * null when refused) and the subtotal the server priced.
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: ?Order, 2: float}
     */
    private function place(array $extra, int $qty = 2): array
    {
        $item = $this->item();
        $before = (int) Order::max('id');

        $response = $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($item, $qty), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', array_merge([
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => $qty]],
            ], $extra));

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        return [$response, $order, round((float) $item->price * $qty, 2)];
    }

    private function fixedVoucher(float $peso): Voucher
    {
        return Voucher::create([
            'code'            => 'PMI' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'multi-ID one-discount test',
            'discount_type'   => 'fixed',
            'discount_value'  => $peso,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ]);
    }

    private function storedRows(Order $order): array
    {
        return OrderDiscountBeneficiary::where('order_id', $order->id)
            ->orderBy('position')
            ->get(['position', 'id_number', 'full_name'])
            ->map(fn ($r) => [$r->position, $r->id_number, $r->full_name])
            ->all();
    }

    /** The counter's form, for one of the checkout item with no add-ons. */
    private function counterPayload(array $override = []): array
    {
        $item = $this->item();

        return array_merge([
            'branch_id'      => 1,
            'order_type'     => 'pick_up',
            'table_number'   => '',
            'payment_method' => 'cash',
            'amount_paid'    => '100000',
            'items'          => [
                (string) $item->id => [
                    'menu_item_id' => (string) $item->id,
                    'quantity'     => '2',
                    'options'      => [],
                ],
            ],
        ], $override);
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: ?Order} */
    private function counter(array $payload): array
    {
        $before = (int) Order::max('id');

        $response = $this->actingAs($this->staff(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', $payload);

        return [$response, Order::where('id', '>', $before)->orderByDesc('id')->first()];
    }

    // ══════════ 1. the existing rule and target, exactly once ══════════

    /**
     * One ID row gets the EXISTING discount: Order::pwdSeniorDiscountFor() of
     * the whole order subtotal. Two units are ordered so that "20% of the
     * subtotal" and "20% of one item" are different numbers — the target
     * stays the subtotal, as it was before this change.
     */
    public function test_one_id_row_gets_exactly_the_existing_discount_on_the_whole_subtotal(): void
    {
        [$response, $order, $subtotal] = $this->place($this->seniorWith($this->rows(1)), 2);

        $response->assertSessionDoesntHaveErrors();
        $this->assertNotNull($order);

        $this->assertSame($subtotal, (float) $order->subtotal);
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertSame(round($subtotal * 0.20, 2), (float) $order->discount_amount, 'the existing rule is 20% of the subtotal');
        $this->assertNotSame(
            round(((float) $this->item()->price) * 0.20, 2),
            (float) $order->discount_amount,
            'the target is the whole subtotal, not a single item'
        );
        $this->assertSame(round($subtotal - (float) $order->discount_amount, 2), (float) $order->total);
        $this->assertSame('senior', $order->discount_type);
        $this->assertCount(1, $this->storedRows($order));
    }

    public static function rowCounts(): array
    {
        return [
            'senior, 1 ID'  => ['senior', 1],
            'senior, 2 IDs' => ['senior', 2],
            'senior, 5 IDs' => ['senior', 5],
            'senior, 12 IDs' => ['senior', 12],
            'pwd, 1 ID'     => ['pwd', 1],
            'pwd, 2 IDs'    => ['pwd', 2],
            'pwd, 5 IDs'    => ['pwd', 5],
        ];
    }

    /**
     * THE CORE REGRESSION TEST. However many IDs are listed, the discount is
     * the same single Order::pwdSeniorDiscountFor($subtotal) — never 2x, 5x.
     *
     * @dataProvider rowCounts
     */
    public function test_the_discount_is_identical_however_many_ids_are_listed(string $type, int $count): void
    {
        $fields = $type === 'pwd' ? $this->pwdWith($this->rows($count)) : $this->seniorWith($this->rows($count));

        [$response, $order, $subtotal] = $this->place($fields);

        $response->assertSessionDoesntHaveErrors();
        $this->assertNotNull($order, "a {$count}-ID order must be accepted");

        $single = Order::pwdSeniorDiscountFor($subtotal);

        $this->assertSame($single, (float) $order->discount_amount, "{$count} IDs must earn exactly ONE discount");
        $this->assertSame(round($subtotal - $single, 2), (float) $order->total);
        $this->assertCount($count, $this->storedRows($order), 'every listed ID is recorded');
    }

    /** Side by side: a 1-ID order and a 5-ID order for the same cart. */
    public function test_one_id_and_five_ids_produce_the_same_money(): void
    {
        [, $one] = $this->place($this->seniorWith($this->rows(1)));
        [, $five] = $this->place($this->seniorWith($this->rows(5, 'OSCA')));

        $this->assertNotNull($one);
        $this->assertNotNull($five);

        $this->assertSame((string) $one->subtotal, (string) $five->subtotal);
        $this->assertSame((string) $one->discount_amount, (string) $five->discount_amount);
        $this->assertSame((string) $one->total, (string) $five->total);
    }

    // ══════════ 2. zero rows ══════════

    public function test_zero_ids_with_the_discount_selected_is_refused_and_creates_nothing(): void
    {
        [$response, $order] = $this->place(['discount_type' => 'senior']);

        $response->assertSessionHasErrors('discount_type');
        $this->assertNull($order, 'no ID, no discounted order');
    }

    public function test_only_blank_added_rows_count_as_zero_ids(): void
    {
        [$response, $order] = $this->place($this->seniorWith([
            ['id_number' => '', 'full_name' => ''],
            ['id_number' => '   ', 'full_name' => ''],
        ]));

        $response->assertSessionHasErrors('discount_type');
        $this->assertNull($order);
    }

    /** No PWD/Senior selected: full price, and posted rows are not recorded. */
    public function test_rows_without_a_discount_type_give_no_discount_and_are_not_recorded(): void
    {
        [$response, $order, $subtotal] = $this->place(['discount_beneficiaries' => $this->rows(3)]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertNotNull($order);
        $this->assertSame(0.0, (float) $order->discount_amount);
        $this->assertSame($subtotal, (float) $order->total);
        $this->assertNull($order->discount_type);
        $this->assertNull($order->discount_beneficiary_name);
        $this->assertSame([], $this->storedRows($order));
    }

    // ══════════ 3. removing a row leaves nothing behind (server half) ══════════

    /**
     * A row the customer trashed is removed from the DOM, so the browser posts
     * indices 0 and 2 with 1 missing. Exactly the posted rows are stored, in
     * order, re-numbered 0..n-1, and the discount is unchanged.
     */
    public function test_a_removed_row_leaves_nothing_behind(): void
    {
        // An earlier order with a different group, to prove nothing carries over.
        [, $earlier] = $this->place($this->seniorWith($this->rows(3, 'OLD')));
        $this->assertNotNull($earlier);

        [$response, $order, $subtotal] = $this->place($this->seniorWith([
            0 => ['id_number' => 'SC-1', 'full_name' => 'Ana Cruz'],
            2 => ['id_number' => 'SC-3', 'full_name' => 'Cy Cruz'],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(
            [[0, 'SC-1', 'Ana Cruz'], [1, 'SC-3', 'Cy Cruz']],
            $this->storedRows($order)
        );
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertSame(3, count($this->storedRows($earlier)), 'the earlier order keeps its own rows only');
    }

    public function test_a_blank_trailing_row_is_skipped_not_stored(): void
    {
        [, $order] = $this->place($this->seniorWith([
            ['id_number' => 'SC-1', 'full_name' => 'Ana Cruz'],
            ['id_number' => '', 'full_name' => ''],
        ]));

        $this->assertNotNull($order);
        $this->assertSame([[0, 'SC-1', 'Ana Cruz']], $this->storedRows($order));
    }

    public function test_a_half_filled_row_is_refused_with_a_clear_message(): void
    {
        [$response, $order] = $this->place($this->seniorWith([
            ['id_number' => 'SC-1', 'full_name' => 'Ana Cruz'],
            ['id_number' => 'SC-2', 'full_name' => ''],
        ]));

        $response->assertSessionHasErrors(['discount_type' => DiscountBeneficiaries::ERROR_INCOMPLETE_ROW]);
        $this->assertNull($order);
    }

    // ══════════ 4. every ID is persisted and retrievable ══════════

    public function test_every_listed_id_is_saved_in_order_and_row_one_is_mirrored(): void
    {
        $rows = [
            ['id_number' => 'SC-1', 'full_name' => 'Ana Cruz'],
            ['id_number' => 'PWD-22/7', 'full_name' => "Ben O'Neil"],
            ['id_number' => 'OSCA 333', 'full_name' => 'Cy Dela-Cruz'],
        ];

        [, $order] = $this->place($this->seniorWith($rows));

        $this->assertSame(
            [[0, 'SC-1', 'Ana Cruz'], [1, 'PWD-22/7', "Ben O'Neil"], [2, 'OSCA 333', 'Cy Dela-Cruz']],
            $this->storedRows($order)
        );

        // The two legacy columns every older screen and report reads.
        $this->assertSame('Ana Cruz', $order->discount_beneficiary_name);
        $this->assertSame('SC-1', $order->discount_beneficiary_card_number);

        $this->assertSame(
            array_map(fn ($r) => ['full_name' => $r['full_name'], 'id_number' => $r['id_number']], $rows),
            $order->fresh()->discountBeneficiaryList()
        );
    }

    public function test_the_receipt_lists_every_id(): void
    {
        [, $order] = $this->place($this->seniorWith([
            ['id_number' => 'SC-1', 'full_name' => 'Ana Cruz'],
            ['id_number' => 'SC-2', 'full_name' => 'Ben Cruz'],
            ['id_number' => 'SC-3', 'full_name' => 'Cy Cruz'],
        ]));

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/receipt/' . $order->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-receipt-discount-ids', $html);
        $this->assertStringContainsString('IDs listed (3)', $html);

        foreach (['Ana Cruz', 'Ben Cruz', 'Cy Cruz', 'ID SC-1', 'ID SC-2', 'ID SC-3'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // Still exactly one discount line.
        $this->assertSame(1, substr_count($html, 'Senior Citizen Discount'));
    }

    /** The staff approval modal gets the whole list, not just row one. */
    public function test_the_order_board_modal_carries_every_id(): void
    {
        [, $order] = $this->place($this->seniorWith([
            ['id_number' => 'SC-1', 'full_name' => 'Ana Cruz'],
            ['id_number' => 'SC-2', 'full_name' => 'Ben Cruz'],
        ]));

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->assertOk()->getContent();

        $pattern = '/data-order-id="' . $order->id . '"[^>]*?data-beneficiaries="([^"]*)"/s';
        $this->assertMatchesRegularExpression($pattern, $html);
        preg_match($pattern, $html, $m);

        $this->assertSame(
            [['full_name' => 'Ana Cruz', 'id_number' => 'SC-1'], ['full_name' => 'Ben Cruz', 'id_number' => 'SC-2']],
            json_decode(html_entity_decode($m[1], ENT_QUOTES), true)
        );

        $this->assertStringContainsString('id="pcDiscountIdList"', $html);
        $this->assertStringContainsString('Check each physical ID against the list before approving.', $html);
    }

    /**
     * Bug fixed in passing: the modal's Expiration row only ever read the
     * (unused) saved-card table, so a PWD order's validated expiration always
     * showed "Not provided" to the staff approving it.
     */
    public function test_the_order_board_shows_the_pwd_expiration_typed_at_checkout(): void
    {
        $expiry = now()->addYears(2)->startOfDay();

        [, $order] = $this->place($this->pwdWith($this->rows(2, 'PWD'), $expiry->format('n/j/Y')));
        $this->assertNotNull($order);

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->getContent();

        $pattern = '/data-order-id="' . $order->id . '"[^>]*?data-expiration="([^"]*)"/s';
        preg_match($pattern, $html, $m);

        $this->assertSame($expiry->format('M d, Y'), $m[1] ?? null);
    }

    /** Orders from before this change have only the legacy pair — still listed. */
    public function test_an_older_order_without_rows_still_lists_its_one_beneficiary(): void
    {
        [, $order] = $this->place($this->seniorWith([['id_number' => 'SC-9', 'full_name' => 'Old Timer']]));

        DB::table('order_discount_beneficiaries')->where('order_id', $order->id)->delete();

        $this->assertSame(
            [['full_name' => 'Old Timer', 'id_number' => 'SC-9']],
            Order::find($order->id)->discountBeneficiaryList()
        );
    }

    public function test_rows_are_deleted_with_their_order(): void
    {
        [, $order] = $this->place($this->seniorWith($this->rows(2)));
        $this->assertCount(2, $this->storedRows($order));

        DB::table('order_items')->where('order_id', $order->id)->delete();
        DB::table('orders')->where('id', $order->id)->delete();

        $this->assertSame(0, DB::table('order_discount_beneficiaries')->where('order_id', $order->id)->count());
    }

    // ══════════ 5. crafted requests cannot raise the discount ══════════

    /** Ten copies of one ID (case and spacing varied) is refused, not rewarded. */
    public function test_duplicate_ids_are_refused(): void
    {
        $rows = [];
        foreach (['SC-1', 'sc-1', 'SC-1 ', ' Sc-1', 'SC-1', 'SC-1', 'SC-1', 'SC-1', 'SC-1', 'SC-1'] as $id) {
            $rows[] = ['id_number' => $id, 'full_name' => 'Ana Cruz'];
        }

        [$response, $order] = $this->place($this->seniorWith($rows));

        $response->assertSessionHasErrors('discount_type');
        $this->assertStringContainsString('listed more than once', session('errors')->first('discount_type'));
        $this->assertNull($order);
    }

    /** The first-row fields and a repeatable row naming the same ID. */
    public function test_a_duplicate_across_the_single_and_repeatable_fields_is_refused(): void
    {
        [$response, $order] = $this->place([
            'discount_type'             => 'senior',
            'discount_beneficiary_name' => 'Ana Cruz',
            'discount_beneficiary_id'   => 'SC-1',
            'discount_beneficiaries'    => [['id_number' => 'sc-1', 'full_name' => 'Ana Cruz']],
        ]);

        $response->assertSessionHasErrors('discount_type');
        $this->assertNull($order);
    }

    public static function malformedRequests(): array
    {
        return [
            'extra key smuggled into a row'  => [['discount_beneficiaries' => [['id_number' => 'SC-1', 'full_name' => 'Ana Cruz', 'discount' => '99999']]]],
            'row that is a string'           => [['discount_beneficiaries' => ['SC-1 Ana Cruz']]],
            'list that is a string'          => [['discount_beneficiaries' => 'SC-1,Ana Cruz']],
            'id_number that is an array'     => [['discount_beneficiaries' => [['id_number' => ['SC-1', 'SC-2'], 'full_name' => 'Ana Cruz']]]],
            'full_name that is an array'     => [['discount_beneficiaries' => [['id_number' => 'SC-1', 'full_name' => ['Ana', 'Ben']]]]],
            'markup in a name'               => [['discount_beneficiaries' => [['id_number' => 'SC-1', 'full_name' => '<script>x</script>']]]],
            'markup in an ID'                => [['discount_beneficiaries' => [['id_number' => '<b>1</b>', 'full_name' => 'Ana Cruz']]]],
            'over-long ID'                   => [['discount_beneficiaries' => [['id_number' => str_repeat('9', 101), 'full_name' => 'Ana Cruz']]]],
            'one-letter name'                => [['discount_beneficiaries' => [['id_number' => 'SC-1', 'full_name' => 'A']]]],
            'legacy alias with markup'       => [['discount_beneficiary_card_number' => '<b>1</b>', 'discount_beneficiary_name' => 'Ana Cruz']],
            'legacy alias that is an array'  => [['discount_beneficiary_card_number' => ['1', '2'], 'discount_beneficiary_name' => 'Ana Cruz']],
        ];
    }

    /**
     * Refused with a normal redirect-and-error — never a 500, never an order.
     * The last two are hardening: discount_beneficiary_card_number was read
     * as an ID alias but had no rule, so it bypassed the ID format and length
     * checks, and an array there reached (string) casting.
     *
     * @dataProvider malformedRequests
     */
    public function test_malformed_rows_are_refused_cleanly(array $fields): void
    {
        [$response, $order] = $this->place(array_merge(['discount_type' => 'senior'], $fields));

        $response->assertStatus(302);
        $response->assertSessionHasErrors();
        $this->assertNull($order);
    }

    /** 50 IDs (the technical ceiling) is accepted — and still ONE discount. */
    public function test_fifty_ids_are_accepted_with_one_discount(): void
    {
        [$response, $order, $subtotal] = $this->place($this->seniorWith($this->rows(DiscountBeneficiaries::MAX_ROWS)));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertCount(DiscountBeneficiaries::MAX_ROWS, $this->storedRows($order));
    }

    public function test_more_than_the_ceiling_is_refused(): void
    {
        [$response, $order] = $this->place($this->seniorWith($this->rows(DiscountBeneficiaries::MAX_ROWS + 1)));

        $response->assertSessionHasErrors();
        $this->assertNull($order);
    }

    /** The ceiling covers the single-row fields plus the list together. */
    public function test_the_ceiling_counts_the_first_row_fields_too(): void
    {
        [$response, $order] = $this->place(array_merge($this->seniorWith($this->rows(DiscountBeneficiaries::MAX_ROWS)), [
            'discount_beneficiary_name' => 'Extra Person',
            'discount_beneficiary_id'   => 'EXTRA-1',
        ]));

        $response->assertSessionHasErrors(['discount_type' => DiscountBeneficiaries::ERROR_TOO_MANY]);
        $this->assertNull($order);
    }

    /** Whatever money the browser claims, the server prices it itself. */
    public function test_client_submitted_discount_figures_are_ignored(): void
    {
        [$response, $order, $subtotal] = $this->place(array_merge($this->seniorWith($this->rows(4)), [
            'discount_amount' => '99999',
            'total'           => '0',
            'subtotal'        => '1',
            'discount_rate'   => '1',
            'row_count'       => '4',
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($subtotal, (float) $order->subtotal);
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertSame(round($subtotal - Order::pwdSeniorDiscountFor($subtotal), 2), (float) $order->total);
    }

    // ══════════ 6. vouchers: exactly as before ══════════

    /**
     * The sharpest check that rows never multiply: a voucher worth MORE than
     * one discount but LESS than three must still beat a three-ID card. If
     * rows multiplied, the card would win.
     */
    public function test_a_voucher_between_one_and_three_discounts_beats_a_three_id_card(): void
    {
        $subtotal = round((float) $this->item()->price * 2, 2);
        $card = Order::pwdSeniorDiscountFor($subtotal);
        $voucher = $this->fixedVoucher(round($card * 1.5, 2));

        [$response, $order] = $this->place(array_merge($this->seniorWith($this->rows(3)), [
            'voucher_code_confirmed' => $voucher->code,
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('voucher', $order->discount_type, 'the card is worth one discount, not three');
        $this->assertEqualsWithDelta($card * 1.5, (float) $order->discount_amount, 0.01);
        $this->assertSame(1, (int) $voucher->fresh()->used_count);

        // A card that lost leaves no trace — no rows, no mirrored pair.
        $this->assertSame([], $this->storedRows($order));
        $this->assertNull($order->discount_beneficiary_name);
        $this->assertNull($order->discount_beneficiary_card_number);
        $this->assertSame('approved', $order->discount_status);
    }

    /** A tie still goes to the card, which is still one discount, voucher unspent. */
    public function test_a_tie_still_goes_to_the_card_with_many_ids_and_the_voucher_is_unspent(): void
    {
        $subtotal = round((float) $this->item()->price * 2, 2);
        $card = Order::pwdSeniorDiscountFor($subtotal);
        $voucher = $this->fixedVoucher($card);

        [$response, $order] = $this->place(array_merge($this->seniorWith($this->rows(4)), [
            'voucher_code_confirmed' => $voucher->code,
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('senior', $order->discount_type);
        $this->assertSame($card, (float) $order->discount_amount);
        $this->assertNull($order->voucher_id);
        $this->assertSame(0, (int) $voucher->fresh()->used_count, 'the losing voucher is kept for another day');
        $this->assertCount(4, $this->storedRows($order));
    }

    // ══════════ 7. eligibility checks are unchanged ══════════

    public function test_an_expired_pwd_id_is_refused_however_many_ids_are_listed(): void
    {
        [$response, $order] = $this->place($this->pwdWith($this->rows(3, 'PWD'), '1/1/1940'));

        $response->assertSessionHasErrors(['discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRED]);
        $this->assertNull($order);
    }

    public function test_a_blank_pwd_expiration_is_refused_with_the_shared_message(): void
    {
        [$response, $order] = $this->place($this->pwdWith($this->rows(2, 'PWD'), ''));

        $response->assertSessionHasErrors(['discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRATION_MISSING]);
        $this->assertNull($order);
    }

    public function test_senior_ids_still_need_no_expiration(): void
    {
        [$response, $order] = $this->place($this->seniorWith($this->rows(2)));

        $response->assertSessionDoesntHaveErrors();
        $this->assertNull($order->discount_beneficiary_expiration);
    }

    /** Online PWD/Senior still waits for staff; approving keeps the one discount and every row. */
    public function test_the_staff_approval_step_is_unchanged(): void
    {
        [, $order, $subtotal] = $this->place($this->seniorWith($this->rows(3)));

        $this->assertSame('pending', $order->discount_status);

        $this->actingAs($this->staff(), 'admin')
            ->from('/admin/home')
            ->put(route('admin.orders.discount.approve', $order->id))
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('approved', $order->discount_status);
        $this->assertSame(Order::pwdSeniorDiscountFor($subtotal), (float) $order->discount_amount);
        $this->assertCount(3, $this->storedRows($order));
    }

    // ══════════ 8. no ID photo ══════════

    /** A photo is no longer required... */
    public function test_a_discount_needs_no_id_photo_any_more(): void
    {
        [$response, $order] = $this->place($this->seniorWith($this->rows(1)));

        $response->assertSessionDoesntHaveErrors();
        $this->assertNotNull($order);
        $this->assertNull($order->discount_id_image);
    }

    /** ...and one that is still posted is never stored anywhere. */
    public function test_a_posted_id_photo_is_ignored_and_never_stored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'idimg') . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC'
        ));

        [$response, $order] = $this->place(array_merge($this->seniorWith($this->rows(1)), [
            'discount_beneficiary_image' => new UploadedFile($path, 'id.png', 'image/png', null, true),
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertNull($order->discount_id_image);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'no ID document may be written to the local disk');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'no ID document may be written to the public disk');
    }

    // ══════════ 9. the cart page ══════════

    public function test_the_cart_renders_the_repeatable_id_rows_and_no_photo_field(): void
    {
        $item = $this->item();

        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($item, 2), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        // The reference layout: rows from a template, ID Number left and Full
        // name right, a clear (X) per field, a trash can per row, and a
        // dimmed "+ Add another ID" below.
        $this->assertStringContainsString('id="discountIdRows"', $html);
        $this->assertStringContainsString('<template id="discountIdRowTemplate">', $html);
        $this->assertMatchesRegularExpression('/>ID Number<.*?data-field="id_number".*?>Full name<.*?data-field="full_name"/s', $html);
        $this->assertSame(2, substr_count($html, '<button type="button" data-clear-field'), 'one clear (X) per field');
        $this->assertStringContainsString('data-remove-row', $html);
        $this->assertStringContainsString('aria-label="Remove this ID"', $html);
        $this->assertStringContainsString('Add another ID', $html);

        // Caught in headless Chrome: with id="addDiscountIdRow" the inline
        // onclick resolved that name through the enclosing <form> (which
        // exposes controls by id) and called the BUTTON — "not a function",
        // so "+ Add another ID" did nothing. The id must differ from it.
        $this->assertStringContainsString('onclick="addDiscountIdRow(true)"', $html);
        $this->assertStringNotContainsString('id="addDiscountIdRow"', $html);
        $this->assertStringContainsString("'discount_beneficiaries[' + index + ']['", $html);

        // One row per person — never worded "ID(s)" on the row itself.
        $this->assertStringNotContainsString('ID Number(s)', $html);

        // The Place Order button carries the running total.
        $this->assertStringContainsString('id="placeOrderTotal"', $html);

        // No photo field and no single-row name/ID inputs left behind.
        $this->assertStringNotContainsString('name="discount_beneficiary_image"', $html);
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringNotContainsString('name="discount_beneficiary_name"', $html);

        // The preview only discounts with at least one complete row, and the
        // row pre-check uses the server's own patterns and messages.
        $this->assertStringContainsString('if (completeDiscountIdRowCount() === 0) {', $html);
        $this->assertStringContainsString(json_encode(DiscountBeneficiaries::ERROR_INCOMPLETE_ROW), $html);
        $this->assertStringContainsString('maxRows: ' . DiscountBeneficiaries::MAX_ROWS, $html);
    }

    // ══════════ 10. the counter ══════════

    public function test_the_counter_records_every_id_but_gives_one_discount(): void
    {
        [$response, $order] = $this->counter($this->counterPayload([
            'discount_type'             => 'senior',
            'discount_beneficiary_name' => 'Ana Cruz',
            'discount_beneficiary_id'   => 'SC-1',
            'discount_beneficiaries'    => [
                3 => ['id_number' => 'SC-2', 'full_name' => 'Ben Cruz'],
                7 => ['id_number' => 'SC-3', 'full_name' => 'Cy Cruz'],
            ],
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);

        $this->assertSame(Order::pwdSeniorDiscountFor((float) $order->subtotal), (float) $order->discount_amount);
        $this->assertSame('approved', $order->discount_status, 'staff verified the IDs in person');
        $this->assertSame(
            [[0, 'SC-1', 'Ana Cruz'], [1, 'SC-2', 'Ben Cruz'], [2, 'SC-3', 'Cy Cruz']],
            $this->storedRows($order)
        );
        $this->assertSame('Ana Cruz', $order->discount_beneficiary_name);
    }

    public function test_the_counter_gives_the_same_discount_for_one_or_many_ids(): void
    {
        [, $one] = $this->counter($this->counterPayload([
            'discount_type' => 'pwd', 'discount_beneficiary_name' => 'Ana Cruz', 'discount_beneficiary_id' => 'P-1',
        ]));
        [, $many] = $this->counter($this->counterPayload([
            'discount_type' => 'pwd', 'discount_beneficiary_name' => 'Ana Cruz', 'discount_beneficiary_id' => 'P-1',
            'discount_beneficiaries' => $this->rows(6, 'PWD'),
        ]));

        $this->assertNotNull($one);
        $this->assertNotNull($many);
        $this->assertSame((string) $one->discount_amount, (string) $many->discount_amount);
        $this->assertSame((string) $one->total, (string) $many->total);
        $this->assertCount(7, $this->storedRows($many));
    }

    public function test_the_counter_refuses_a_duplicate_and_brings_the_rows_back(): void
    {
        [$response, $order] = $this->counter($this->counterPayload([
            'discount_type'             => 'senior',
            'discount_beneficiary_name' => 'Ana Cruz',
            'discount_beneficiary_id'   => 'SC-1',
            'discount_beneficiaries'    => [
                ['id_number' => 'SC-2', 'full_name' => 'Ben Cruz'],
                ['id_number' => 'SC-1', 'full_name' => 'Ana Cruz'],
            ],
        ]));

        $response->assertSessionHasErrors('discount_type');
        $this->assertNull($order);

        // The bounced modal restores every extra row staff had typed.
        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->getContent();
        $this->assertStringContainsString('listed more than once', $html);
        $this->assertStringContainsString('Ben Cruz', $html);
        $this->assertStringContainsString('addManualDiscountIdRow(', $html);
    }

    public function test_the_counter_extra_id_controls_ship_disabled(): void
    {
        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->getContent();

        $this->assertMatchesRegularExpression('/<button\b[^>]*\bid="manualDiscountAddRow"[^>]*\bdisabled\b/s', $html);
        $this->assertStringContainsString('id="manualDiscountExtraRows"', $html);

        // The counter preview reads the server's rate, not a copied literal.
        $this->assertStringContainsString(
            'subtotal * ' . json_encode(Order::PWD_SENIOR_DISCOUNT_RATE) . ', subtotal',
            $html
        );
    }
}
