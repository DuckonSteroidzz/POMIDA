<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The filters on the customer's Order History (Orders page, History tab):
 * the All / Today / 7 days / 30 days chips, a From–To date range, a status
 * (all, completed, cancelled) and how many orders to show per page.
 *
 * All of it comes from the query string, so every filtered view is a plain
 * GET link that survives a reload and the Back button, and none of it needs
 * JavaScript to work. Anything malformed falls back to the default rather
 * than erroring — it is a URL anyone can edit.
 *
 * DAYS ARE SHOP DAYS. "Today", "7 days" and a typed date are Asia/Manila days
 * (App\Support\ShopDay), matched against when the order was PLACED
 * (orders.created_at) — the same time the page prints on each card and groups
 * by ("Today", "Yesterday"). The shop day is converted into the app's storage
 * zone for the query, exactly as ShopDay::bounds() does, so an order placed at
 * 00:10 Manila is "today" even while the database holds it as yesterday in UTC.
 *
 * The page's own state — 'tab' and the page number — is not part of this
 * class: the controller owns pagination and the form owns 'tab'.
 */
final class OrderHistoryFilter
{
    public const PRESETS = ['all', 'today', '7', '30'];

    public const STATUSES = ['all', 'completed', 'cancelled'];

    public const PER_PAGE_OPTIONS = [6, 9, 12];

    public const DEFAULT_PER_PAGE = 9;

    private function __construct(
        /** One of PRESETS, or null while a custom From/To range is in use. */
        public readonly ?string $preset,
        /** Shop dates, 'Y-m-d'. Null = unbounded on that side. */
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $status,
        public readonly int $perPage,
    ) {
    }

    /**
     * $today is only for tests (a shop-zone midnight); the page passes null.
     */
    public static function fromRequest(Request $request, ?CarbonImmutable $today = null): self
    {
        $today ??= ShopDay::today();

        $status  = self::choice($request->query('status'), self::STATUSES, 'all');
        $perPage = (int) self::choice($request->query('per_page'), array_map('strval', self::PER_PAGE_OPTIONS), (string) self::DEFAULT_PER_PAGE);

        $from = self::date($request->query('from'));
        $to   = self::date($request->query('to'));

        $preset = self::choice($request->query('range'), self::PRESETS, 'all');

        $presetFrom = match ($preset) {
            'today' => $today->format('Y-m-d'),
            '7'     => $today->subDays(6)->format('Y-m-d'),
            '30'    => $today->subDays(29)->format('Y-m-d'),
            default => null,
        };
        $presetTo = $preset === 'all' ? null : $today->format('Y-m-d');

        if ($from !== null || $to !== null) {
            if ($from !== null && $to !== null && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            // The filter form always sends the dates it is showing. When they
            // are exactly the chip's own, the chip is still the selection (so
            // changing only the status keeps it lit); any other dates are a
            // typed range, and a typed range un-lights the chips.
            if ($preset !== 'all' && $from === $presetFrom && $to === $presetTo) {
                return new self($preset, $from, $to, $status, $perPage);
            }

            return new self(null, $from, $to, $status, $perPage);
        }

        return new self($preset, $presetFrom, $presetTo, $status, $perPage);
    }

    /** Keep only the orders this filter lets through. */
    public function apply(Builder $query): Builder
    {
        $appZone  = ShopDay::appTimezone();
        $shopZone = ShopDay::timezone();

        if ($this->from !== null) {
            $start = CarbonImmutable::parse($this->from, $shopZone)->startOfDay();
            $query->where('created_at', '>=', Carbon::instance($start)->setTimezone($appZone));
        }

        if ($this->to !== null) {
            $end = CarbonImmutable::parse($this->to, $shopZone)->startOfDay()->addDay();
            $query->where('created_at', '<', Carbon::instance($end)->setTimezone($appZone));
        }

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        return $query;
    }

    /** Whether anything other than the defaults is selected (shows "Reset"). */
    public function isFiltered(): bool
    {
        return $this->preset !== 'all' || $this->status !== 'all';
    }

    /**
     * The query string for a link or form that keeps the CURRENT selection and
     * changes some of it. Chips pass ['range' => 'today']; paging passes
     * nothing (the paginator adds 'page'). Defaults are left out so the URLs
     * stay short.
     *
     * @param  array<string, string|int|null>  $changes
     * @return array<string, string|int>
     */
    public function query(array $changes = []): array
    {
        $current = ['tab' => 'history'];

        if ($this->preset === null) {
            if ($this->from !== null) {
                $current['from'] = $this->from;
            }
            if ($this->to !== null) {
                $current['to'] = $this->to;
            }
        } elseif ($this->preset !== 'all') {
            $current['range'] = $this->preset;
        }

        if ($this->status !== 'all') {
            $current['status'] = $this->status;
        }

        if ($this->perPage !== self::DEFAULT_PER_PAGE) {
            $current['per_page'] = $this->perPage;
        }

        $merged = array_merge($current, $changes);

        // A chip replaces any typed range, and a typed range replaces the chip.
        if (array_key_exists('range', $changes)) {
            unset($merged['from'], $merged['to']);
        }

        return array_filter($merged, fn ($v) => $v !== null && $v !== '' && $v !== 'all');
    }

    /** @param  array<int, string>  $allowed */
    private static function choice(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /** A real 'Y-m-d' date, or null. */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
