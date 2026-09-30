<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'id_number',
        'full_name',
        'id_image',
        'expiration_date',
        'is_verified',
        'verified_at',
        'verified_by',
        'is_active',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'expiration_date' => 'date',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Compatibility accessors used by the customer/admin views.
     * The model stores full_name/id_number, while older views may use name/card_number.
     */
    public function getNameAttribute($value)
    {
        return $this->attributes['full_name'] ?? null;
    }

    public function getCardNumberAttribute($value)
    {
        return $this->attributes['id_number'] ?? null;
    }

    public function getStatusAttribute($value)
    {
        if (array_key_exists('verification_status', $this->attributes)) {
            return $this->attributes['verification_status'];
        }

        return !empty($this->attributes['is_verified']) ? 'verified' : 'pending';
    }

    /*
    |--------------------------------------------------------------------------
    | SINGLE SOURCE OF TRUTH FOR THE CARD EXPIRATION RULE
    |--------------------------------------------------------------------------
    |
    | "Is this PWD/Senior card still usable?" used to be decided in four
    | independent places, each with its own wording and its own idea of what
    | counts as expired:
    |
    |   - the cart page's date input (a `min` attribute),
    |   - the cart page's JS pre-flight check in openOrderConfirmation(),
    |   - a Laravel validation rule (after_or_equal:today) on placeOrder(),
    |   - two hand-written Carbon comparisons inside placeOrder() itself, one
    |     for the saved-card branch and one for the transaction branch.
    |
    | That drift is what let the cart show "This discount card has already
    | expired." and a live -20% discount at the same time: the JS that printed
    | the message and the JS that applied the discount did not share a rule.
    |
    | Everything now calls this method, and the cart page renders its messages
    | from the constants below, so the preview and the charge cannot disagree.
    |
    | BATCH 2 (2026-09-29): checkout no longer asks for or checks an
    | expiration date at all, for PWD or Senior Citizen — not on the cart, not
    | in OrderController::placeOrder() (typed or saved-card path). Nothing in
    | the ordering flow calls the helpers below any more. They are left as
    | they were, pure and self-tested, because orders and cards placed before
    | that change still carry a stored date that these describe.
    |
    */

    public const ERROR_EXPIRATION_MISSING = 'Please enter the discount card expiration date.';
    public const ERROR_EXPIRATION_INVALID = 'That discount card expiration date is not a valid date.';
    public const ERROR_EXPIRED = 'This discount card has already expired.';

    /*
    |--------------------------------------------------------------------------
    | WHETHER AN EXPIRATION DATE IS EVEN REQUIRED (September 2026)
    |--------------------------------------------------------------------------
    |
    | Philippine Senior Citizen IDs (RA 9994, as amended by RA 10645) carry no
    | expiration at all — they are valid for life. The PWD ID (RA 10754 and its
    | implementing rules) DOES expire and is renewed, typically every few
    | years. The checkout form used to demand a valid expiration for both
    | types alike, which incorrectly blocked a Senior Citizen with a lifetime
    | ID from ever completing a discounted order.
    |
    | requiresExpiration() is the one place that decides this, so
    | expirationErrorFor() below and every caller of it (the saved-card branch
    | and the fresh-transaction branch in OrderController::placeOrder(), and
    | this same rule's JS twin on the cart page) all agree. Only 'pwd' is
    | exempted from "required"; anything else — 'senior', null, or an
    | unrecognised value — keeps the original, stricter behaviour, so a typo
    | or a future third type never silently becomes optional by accident.
    */

    /**
     * True when the given discount type must supply a valid, unexpired
     * expiration date to be honoured. False only for Senior Citizen, whose ID
     * does not expire under Philippine law.
     */
    public static function requiresExpiration(?string $discountType): bool
    {
        return strtolower((string) $discountType) !== 'senior';
    }

    /**
     * The placeholder shown on the cart page's typed expiration field, and
     * the human-readable name for the two shapes normalizeTypedExpiration()
     * accepts.
     */
    public const TYPED_EXPIRATION_PLACEHOLDER = 'M/D/Y';

    /*
    |--------------------------------------------------------------------------
    | TYPED EXPIRATION DATE (September 2026 UX pass)
    |--------------------------------------------------------------------------
    |
    | The cart page used to ask for this date with three <select> dropdowns
    | (day/month/year) — themselves a replacement for an earlier native
    | calendar picker that older PWD/Senior customers found hard to use.
    | Three dropdowns turned out to still be several taps slower than typing
    | a date outright, so the field is now a single text input.
    |
    | ACCEPTED FORMATS: M/D/Y and MM/DD/YYYY only — e.g. "1/5/2027" or
    | "01/05/2027" (both mean January 5, 2027). Deliberately refused rather
    | than guessed at:
    |
    |   - dash-separated ("1-5-2027")   — PHP would read the dashes as
    |     day-month-year, silently reversing what was typed for slash-order.
    |   - year-first ("2027/1/5")       — not a shape a customer would type
    |     for a Philippine ID's expiry, and easy to confuse with the day.
    |   - 2-digit years ("5/1/27")      — "27" could mean 1927 or 2027; an ID
    |     expiry decades in the wrong direction is exactly the kind of
    |     mistake this field must not paper over.
    |
    | normalizeTypedExpiration() is the only place that turns typed text into
    | the Y-m-d value the rest of the app already stores and compares
    | (Order::placeOrder(), expirationErrorFor() above, and the orders table
    | itself all keep working with Y-m-d — only the customer-facing input
    | format changed).
    */

    /**
     * Turns a customer-typed "M/D/Y" or "MM/DD/YYYY" string into the Y-m-d
     * value the rest of the app stores and compares, or null when the text
     * is not one of those two shapes or does not name a real calendar date.
     *
     * Deliberately does NOT hand the raw string to Carbon::createFromFormat()
     * (or Carbon::parse()) and trust whatever comes back: PHP's date parser
     * silently rolls an impossible combination like 2/30/2027 forward into
     * 3/2/2027 instead of rejecting it — the exact bug class
     * expirationErrorFor() above already guards against for the stored
     * Y-m-d format. Pulling the month/day/year apart with a regex first and
     * checking them against the real calendar with checkdate() has no
     * parsing step left that could roll anything over: either the three
     * numbers name a real day on the calendar, or they do not, and there is
     * nothing in between for a parser to guess at.
     */
    public static function normalizeTypedExpiration(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return null;
        }

        // Exactly M/D/Y or MM/DD/YYYY: 1-2 digit month, 1-2 digit day, a
        // literal 4-digit year, slash-separated. Anything else — dashes,
        // year-first, a 2-digit year, extra whitespace, stray text — fails
        // the shape check here and is never handed to checkdate() at all.
        if (! preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $typed, $parts)) {
            return null;
        }

        [, $month, $day, $year] = $parts;
        $month = (int) $month;
        $day = (int) $day;
        $year = (int) $year;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * The reverse of normalizeTypedExpiration(): formats a stored Y-m-d (or
     * any date-ish value the model already accepts) back into the M/D/Y text
     * the cart's input shows, e.g. for pre-filling the field with a value
     * that came from the backend. Returns null for anything that is not a
     * real date, rather than guessing.
     */
    public static function formatForTypedInput($expiration): ?string
    {
        if ($expiration === null || $expiration === '') {
            return null;
        }

        try {
            $date = $expiration instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($expiration)
                : \Illuminate\Support\Carbon::parse((string) $expiration);
        } catch (\Throwable $e) {
            return null;
        }

        return $date->format('n/j/Y');
    }

    /**
     * Why this expiration date makes the card unusable, or null when it is
     * fine. Accepts anything date-ish: a Y-m-d string from the cart form, a
     * Carbon instance from the saved-card column, or null.
     *
     * $discountType is optional and defaults to the original, stricter
     * behaviour (expiration required) so any caller that does not pass it
     * keeps working exactly as before. Pass 'senior' to exempt a Senior
     * Citizen ID from needing one at all — see requiresExpiration() above.
     */
    public static function expirationErrorFor($expiration, ?string $discountType = null): ?string
    {
        if (! self::requiresExpiration($discountType)) {
            return null;
        }

        if ($expiration === null || $expiration === '') {
            return self::ERROR_EXPIRATION_MISSING;
        }

        // PHP's date parser silently rolls an impossible date like
        // 2026-02-30 forward into 2026-03-02 instead of rejecting it, so a
        // day/month/year combination has to be checked against the real
        // calendar before it ever reaches Carbon::parse() below.
        if (
            is_string($expiration)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expiration, $matches)
            && ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            return self::ERROR_EXPIRATION_INVALID;
        }

        try {
            $date = $expiration instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($expiration)
                : \Illuminate\Support\Carbon::parse((string) $expiration);
        } catch (\Throwable $e) {
            return self::ERROR_EXPIRATION_INVALID;
        }

        return $date->startOfDay()->lt(today())
            ? self::ERROR_EXPIRED
            : null;
    }

    public function isExpired(): bool
    {
        return self::expirationErrorFor($this->expiration_date, $this->type) !== null;
    }

    /**
     * RELATIONSHIPS
     */

    // Belongs to a user (yung may-ari ng card)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Belongs to a verifier (admin/staff na nag-verify)
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}