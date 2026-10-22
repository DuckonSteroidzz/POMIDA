<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Carbon;

/**
 * The shop's calendar day: where "today" starts and ends for customer rules,
 * and the shop's wall-clock time for anything printed on a page.
 *
 * WHY THIS EXISTS
 * ---------------
 * Laravel stores every timestamp as a wall-clock time in APP_TIMEZONE, and its
 * today(), isToday() and whereDate() draw the day boundary at midnight in that
 * zone. This machine runs APP_TIMEZONE=UTC, where that midnight is 8:00 AM in
 * Manila. Before this class, the daily spin cap, the "7 spins per order placed
 * today" count and the voucher dates all rolled over at breakfast time instead
 * of midnight: an order placed at 7:30 AM was "yesterday's" order by 8:01 AM,
 * and a voucher whose last day was today stopped working at 8:00 AM.
 *
 * EITHER APP ZONE WORKS
 * ---------------------
 * The live server may run APP_TIMEZONE=UTC or Asia/Manila (.env.example ships
 * Asia/Manila). Nothing here assumes stored values are UTC: the shop day is
 * converted INTO the app zone for queries (bounds()), and stored values are
 * converted OUT of it for dates and display (dateOf(), local()). Under
 * Asia/Manila every conversion is a no-op. ShopDayTimezoneParityTest runs the
 * boundary cases under both settings.
 *
 * WHAT IT DOES NOT CHANGE
 * -----------------------
 * Nothing is stored differently. This class only says which stored instants a
 * shop day covers and which shop day (or shop time) a stored instant falls on.
 * Date-only columns (valid_from, and an admin's date-only expires_at) are
 * compared as shop dates and never shifted.
 *
 * One time zone, one place: config app.shop_timezone (default Asia/Manila).
 * The streak calendar reads it through here as well.
 */
final class ShopDay
{
    public static function timezone(): string
    {
        return (string) (config('app.shop_timezone') ?: 'Asia/Manila');
    }

    /**
     * The zone stored timestamps are written in. Laravel applies APP_TIMEZONE
     * at boot as PHP's default zone, which is what Eloquent writes and reads
     * datetime columns in.
     */
    public static function appTimezone(): string
    {
        return (string) (config('app.timezone') ?: date_default_timezone_get());
    }

    /** Midnight at the start of the current shop day, in the shop's zone. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /** The current shop date, 'Y-m-d'. What a date-only column is compared with. */
    public static function todayString(): string
    {
        return self::today()->format('Y-m-d');
    }

    /**
     * The current shop day as a half-open range [start, nextStart) in the APP
     * zone, ready to query stored timestamps with. The query builder formats a
     * Carbon without converting its zone, so both ends are converted here.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function bounds(): array
    {
        $appZone = self::appTimezone();
        $start   = self::today();

        return [
            Carbon::instance($start)->setTimezone($appZone),
            Carbon::instance($start->addDay())->setTimezone($appZone),
        ];
    }

    /**
     * Limit a query to rows whose $column falls on the current shop day.
     * The replacement for whereDate($column, today()).
     */
    public static function whereToday(BuilderContract $query, string $column): BuilderContract
    {
        [$start, $end] = self::bounds();

        return $query->where($column, '>=', $start)->where($column, '<', $end);
    }

    /** The shop date ('Y-m-d') a stored instant falls on. */
    public static function dateOf(DateTimeInterface $stamp): string
    {
        return CarbonImmutable::instance($stamp)->setTimezone(self::timezone())->format('Y-m-d');
    }

    /** Whether a stored instant falls on the current shop day. */
    public static function isToday(?DateTimeInterface $stamp): bool
    {
        return $stamp !== null && self::dateOf($stamp) === self::todayString();
    }

    /** Whether a stored instant falls on the shop day before today. */
    public static function isYesterday(?DateTimeInterface $stamp): bool
    {
        return $stamp !== null && self::dateOf($stamp) === self::today()->subDay()->format('Y-m-d');
    }

    /**
     * A stored timestamp as the shop's wall-clock time, FOR DISPLAY ONLY.
     *
     * A Carbon read by Eloquent already carries the app zone, so this is a
     * conversion, not a relabel; a raw string (a DB::table() row) is read in
     * the app zone first. Under APP_TIMEZONE=Asia/Manila it changes nothing.
     *
     * Never write the result back to a model: Eloquent stores a Carbon's wall
     * clock without converting it, so a shop-zone value would be saved 8 hours
     * off under UTC. And never use it on a date-only value (valid_from): a date
     * has no time of day to move.
     */
    public static function local(DateTimeInterface|string|null $stamp): ?CarbonImmutable
    {
        if ($stamp === null || $stamp === '') {
            return null;
        }

        $instant = $stamp instanceof DateTimeInterface
            ? CarbonImmutable::instance($stamp)
            : CarbonImmutable::parse($stamp, self::appTimezone());

        return $instant->setTimezone(self::timezone());
    }

    /**
     * local(), formatted. '' for an empty value, so a view can print it as is:
     * {{ \App\Support\ShopDay::format($order->created_at, 'M d, Y h:i A') }}
     */
    public static function format(DateTimeInterface|string|null $stamp, string $format): string
    {
        return self::local($stamp)?->format($format) ?? '';
    }

    /**
     * An expires_at for display, read by the same rule as hasExpired(): a
     * date-only value (the admin form) is shown as the date it was entered
     * as; a precise instant is shown as the shop date it falls on.
     */
    public static function formatExpiry(?DateTimeInterface $expiresAt, string $format = 'M d, Y'): string
    {
        if ($expiresAt === null) {
            return '';
        }

        return self::isDateOnly($expiresAt) ? $expiresAt->format($format) : self::format($expiresAt, $format);
    }

    /**
     * Whether a date-only "valid from" value has been reached: true from the
     * first moment of that date in the shop. The value is read as the date it
     * was written as (a 'date' cast holds it at midnight), not converted.
     */
    public static function hasStarted(DateTimeInterface|string|null $date): bool
    {
        if ($date === null || $date === '') {
            return true;
        }

        $day = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return self::todayString() >= $day;
    }

    /**
     * Whether an expiry has passed.
     *
     * The admin voucher form takes a DATE, which is stored as that date at
     * 00:00:00. A value at exactly midnight is therefore read as "valid
     * through the end of this date in the shop". Any other value is a precise
     * instant and expires at that instant.
     */
    public static function hasExpired(?DateTimeInterface $expiresAt): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        if (self::isDateOnly($expiresAt)) {
            return self::todayString() > $expiresAt->format('Y-m-d');
        }

        return Carbon::instance($expiresAt)->isPast();
    }

    /**
     * The SQL twin of ! hasExpired(): keep rows whose $column is empty or not
     * yet expired by the same rule. Wrapped in its own group so it can sit
     * next to other where clauses safely.
     */
    public static function whereNotExpired(BuilderContract $query, string $column): BuilderContract
    {
        return $query->where(function ($q) use ($column) {
            $q->whereNull($column)
                ->orWhere($column, '>', now())
                ->orWhere(function ($q) use ($column) {
                    $q->whereTime($column, '=', '00:00:00')
                        ->where($column, '>=', self::todayString() . ' 00:00:00');
                });
        });
    }

    /**
     * Whether a start has been reached — the mirror of hasExpired().
     *
     * A start at exactly midnight is a DATE (the admin form stores it that
     * way) and begins at 00:00 Manila on that date; read as an instant under
     * APP_TIMEZONE=UTC it would begin at 8:00 AM Manila. Any other value is a
     * precise instant and begins at that instant. No start means no limit.
     */
    public static function hasStartedAt(?DateTimeInterface $startsAt): bool
    {
        if ($startsAt === null) {
            return true;
        }

        if (self::isDateOnly($startsAt)) {
            return self::todayString() >= $startsAt->format('Y-m-d');
        }

        return ! Carbon::instance($startsAt)->isFuture();
    }

    /**
     * The SQL twin of hasStartedAt(): keep rows whose $column is empty or
     * already started by the same rule. Grouped so it can sit next to other
     * where clauses safely.
     */
    public static function whereStarted(BuilderContract $query, string $column): BuilderContract
    {
        return $query->where(function ($q) use ($column) {
            $q->whereNull($column)
                ->orWhere($column, '<=', now())
                ->orWhere(function ($q) use ($column) {
                    $q->whereTime($column, '=', '00:00:00')
                        ->where($column, '<=', self::todayString() . ' 00:00:00');
                });
        });
    }

    private static function isDateOnly(DateTimeInterface $value): bool
    {
        return $value->format('H:i:s') === '00:00:00';
    }
}
