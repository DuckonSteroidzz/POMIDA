<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderDiscountBeneficiary;
use Illuminate\Http\Request;

/**
 * DiscountBeneficiaries — the PWD / Senior Citizen IDs listed on one order.
 *
 * ONE DISCOUNT, HOWEVER MANY PEOPLE (September 2026)
 * --------------------------------------------------
 * A group order can include more than one PWD or Senior Citizen. Each of them
 * is now recorded by ID number and full name — one row per person — for the
 * compliance record and the receipt. Before this, an order had room for
 * exactly one; a second ID posted to checkout was silently dropped.
 *
 * The rows are a RECORD, never a multiplier. The discount is still
 * Order::pwdSeniorDiscountFor($subtotal), computed exactly once per order by
 * the caller, and nothing in this class returns, accepts or knows about money.
 * A row count never reaches the pricing code at all, which is what makes "five
 * IDs = five discounts" structurally impossible rather than merely checked
 * for. Zero rows is the only count that matters to pricing: with a PWD/Senior
 * discount selected, the caller refuses the order.
 *
 * WHERE ROWS COME FROM
 * --------------------
 * Two request shapes, read as ONE list, in this order:
 *
 *   1. The original single-row fields — discount_beneficiary_id (or its old
 *      alias discount_beneficiary_card_number) + discount_beneficiary_name.
 *      Every client and test before this change posts these, and the counter
 *      form still uses them for its first row.
 *   2. discount_beneficiaries[n][id_number] / [full_name] — the repeatable
 *      rows the cart's "+ Add another ID" builds (and the counter's extras).
 *
 * A completely blank row (an added row never filled in) is skipped. A
 * half-filled row, or the same ID number listed twice, refuses the order with
 * a sentence the customer or staff can act on — the list is a legal record,
 * so it is corrected rather than guessed at.
 *
 * Used by OrderController::placeOrder() and AdminController::storeManualOrder()
 * so the two doors into an order can never disagree about what a valid list is.
 */
final class DiscountBeneficiaries
{
    /**
     * A technical ceiling against a crafted request, NOT a party-size rule —
     * nothing in the business logic limits how many PWD/Senior diners a group
     * may include, and no real table comes near this. It stops one POST from
     * writing hundreds of rows. PHP's own max_input_vars (1000 → ~500 rows)
     * would otherwise be the only bound.
     */
    public const MAX_ROWS = 50;

    /** The rules both order paths already used for the single-row fields. */
    public const NAME_REGEX = "/^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ .'-]{1,99}$/u";

    public const ID_REGEX = '/^[A-Za-z0-9\-\/ ]+$/';

    public const ERROR_INCOMPLETE_ROW = 'Please enter both the ID number and the full name for every ID you list, or remove the unfinished row.';

    public const ERROR_TOO_MANY = 'An order can list up to ' . self::MAX_ROWS . ' IDs.';

    /**
     * Validation rules for every field that can carry a row. Merged into each
     * caller's own $request->validate() so a malformed row is refused before
     * anything else runs.
     */
    public static function rules(): array
    {
        $name = ['nullable', 'string', 'max:100', 'regex:' . self::NAME_REGEX];
        $id = ['nullable', 'string', 'max:100', 'regex:' . self::ID_REGEX];

        return [
            'discount_beneficiary_name' => $name,
            'discount_beneficiary_id' => $id,

            /*
             * Accepted as an alias for discount_beneficiary_id long before
             * this change, but it had NO rule of its own: a request could use
             * it to store an ID number past the format rule and the 100-char
             * cap, and an array posted here reached (string) casting and threw
             * a 500. Same rule as its twin now.
             */
            'discount_beneficiary_card_number' => $id,

            'discount_beneficiaries' => 'nullable|array|max:' . self::MAX_ROWS,

            // Only these two keys: a row cannot smuggle in anything else.
            'discount_beneficiaries.*' => 'array:id_number,full_name',
            'discount_beneficiaries.*.id_number' => $id,
            'discount_beneficiaries.*.full_name' => $name,
        ];
    }

    /**
     * Readable refusals for the repeatable rows. Laravel's defaults would name
     * the field "discount_beneficiaries.2.full_name". The single-row fields
     * keep their existing wording.
     */
    public static function messages(): array
    {
        return [
            'discount_beneficiaries.array' => 'The list of IDs could not be read. Please try again.',
            'discount_beneficiaries.max' => self::ERROR_TOO_MANY,
            'discount_beneficiaries.*.array' => 'One of the listed IDs could not be read. Please remove that row and add it again.',
            'discount_beneficiaries.*.id_number.string' => 'An ID number must be text.',
            'discount_beneficiaries.*.id_number.max' => 'An ID number can be at most 100 characters.',
            'discount_beneficiaries.*.id_number.regex' => 'An ID number can only contain letters, numbers, spaces, dashes (-) and slashes (/).',
            'discount_beneficiaries.*.full_name.string' => 'A full name must be text.',
            'discount_beneficiaries.*.full_name.max' => 'A full name can be at most 100 characters.',
            'discount_beneficiaries.*.full_name.regex' => 'A full name must be at least 2 characters and can only contain letters, spaces, periods, apostrophes and hyphens.',
        ];
    }

    /**
     * NAME_REGEX or ID_REGEX as a JavaScript RegExp source (delimiters and
     * flags stripped), so a page's pre-check runs the server's own rule
     * instead of a hand-copied one that could drift.
     */
    public static function jsPattern(string $which): string
    {
        $regex = $which === 'name' ? self::NAME_REGEX : self::ID_REGEX;

        return (string) preg_replace('#^/(.*)/[a-z]*$#s', '$1', $regex);
    }

    /**
     * The request's ID rows, cleaned, in the order they were listed.
     *
     * Call only after rules() has passed: every value is then a string or
     * null, and every row an array.
     *
     * @return array{rows: list<array{id_number: string, full_name: string}>, error: ?string}
     */
    public static function fromRequest(Request $request): array
    {
        $candidates = [[
            'id_number' => $request->input('discount_beneficiary_id')
                ?? $request->input('discount_beneficiary_card_number'),
            'full_name' => $request->input('discount_beneficiary_name'),
        ]];

        foreach ((array) $request->input('discount_beneficiaries', []) as $row) {
            $candidates[] = is_array($row) ? $row : [];
        }

        $rows = [];
        $seen = [];

        foreach ($candidates as $row) {
            $idNumber = self::clean($row['id_number'] ?? null);
            $fullName = self::clean($row['full_name'] ?? null);

            // An "add another ID" row that was never filled in.
            if ($idNumber === '' && $fullName === '') {
                continue;
            }

            if ($idNumber === '' || $fullName === '') {
                return ['rows' => [], 'error' => self::ERROR_INCOMPLETE_ROW];
            }

            // Case- and spacing-insensitive: "sc 1" and "SC  1" are one ID.
            $key = strtoupper($idNumber);

            if (isset($seen[$key])) {
                return [
                    'rows' => [],
                    'error' => 'The ID number "' . $idNumber . '" is listed more than once. Each person\'s ID only needs to be listed once.',
                ];
            }

            $seen[$key] = true;
            $rows[] = ['id_number' => $idNumber, 'full_name' => $fullName];
        }

        // The single-row fields ride alongside the capped array, so the list
        // as a whole is capped here too.
        if (count($rows) > self::MAX_ROWS) {
            return ['rows' => [], 'error' => self::ERROR_TOO_MANY];
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Save the rows against an order, in listed order, in one INSERT. Call it
     * inside the order transaction so they roll back with the order.
     *
     * @param  list<array{id_number: string, full_name: string}>  $rows
     */
    public static function saveFor(Order $order, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $records = [];

        foreach (array_values($rows) as $position => $row) {
            $records[] = [
                'order_id' => $order->id,
                'position' => $position,
                'full_name' => $row['full_name'],
                'id_number' => $row['id_number'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        OrderDiscountBeneficiary::insert($records);
    }

    /** Trimmed, inner whitespace collapsed; '' for anything that is not text. */
    private static function clean($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
