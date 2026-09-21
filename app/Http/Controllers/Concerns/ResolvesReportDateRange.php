<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;

/**
 * The admin reports' ONE rule for a user-supplied custom date range.
 *
 * Four endpoints accept `date_from` / `date_to` straight off the querystring —
 * admin.analytics, admin.analytics.print, admin.summary and
 * admin.export.orders. Every one of them used to hand the raw string to
 * Carbon::parse(), which has three separate failure modes that all reached
 * the user:
 *
 *  1. UNPARSEABLE ("banana", "2026-13-45") threw InvalidFormatException, so
 *     all four endpoints answered 500 to a typo. This is the bug the pass was
 *     opened for.
 *
 *  2. "0000-00-00" did NOT throw. Carbon parses it as year -1 (-0001-11-30),
 *     which is a legal Carbon instance, so the request succeeded and then
 *     spanned 740,277 days. AnalyticsService::dailySalesSeriesForRange() used
 *     to run one query PER DAY of the range, so that single querystring was an
 *     authenticated denial-of-service rather than an error page. The grouped
 *     query in that service closed the amplification; this parser closes the
 *     door itself, which is the half that also protects the zero-filled PHP
 *     array behind it.
 *
 *  3. INVERTED (from > to) did not throw either, and produced silently wrong
 *     figures. Carbon 3's diffInDays() is SIGNED, so resolveSummaryPeriod()'s
 *     `$days = $start->diffInDays($end) + 1` went negative and the subsequent
 *     subDays($days - 1) ADDED days — the "previous period" the % change badge
 *     compares against landed in the future.
 *
 * What this trait does about each:
 *
 *  - Unparseable / impossible / out-of-bounds -> strictDate() returns null,
 *    normaliseCustomRange() returns null, and the CALLER falls back to its own
 *    default preset (Today for Summary/CSV, Last 30 Days for Analytics). A
 *    plain-language notice is handed back so the screen can say so rather than
 *    silently showing a different period than the one that was asked for.
 *
 *  - Inverted -> the two bounds are SWAPPED rather than refused. "Sep 20 to
 *    Sep 01" is an unambiguous user slip with exactly one sensible reading,
 *    and refusing it would send someone back to re-type dates they already
 *    typed correctly, just in the other order. The notice still says it
 *    happened, so nobody reads a swapped range as the one they entered.
 *
 * Why a strict format check rather than a try/catch around Carbon::parse():
 * catching the exception alone fixes only failure mode 1. "0000-00-00" and
 * "2026-02-31" never throw — Carbon rolls them to year -1 and to Mar 03
 * respectively — so a try/catch would still let both through. The round-trip
 * comparison below is what rejects a date that PARSES but is not the date that
 * was written.
 */
trait ResolvesReportDateRange
{
    /**
     * Widest year either bound may name.
     *
     * Not arbitrary defensiveness: the business opened well inside this window
     * and a report cannot be ABOUT a year outside it, so anything beyond these
     * is a typo or a probe. Bounding the year is also what keeps the zero-fill
     * loop in dailySalesSeriesForRange() to an array a browser can chart, now
     * that the query behind it no longer grows with the range.
     */
    private const REPORT_MIN_YEAR = 2000;
    private const REPORT_MAX_YEAR = 2100;

    /**
     * Parse exactly one `YYYY-MM-DD` day, or return null.
     *
     * Deliberately stricter than Carbon::parse(). Three gates, each catching
     * something the previous one lets through:
     *
     *   - the regex rejects anything that is not the shape an <input type=date>
     *     submits ("banana", "2026-09", "2026-9-20", "20260920");
     *   - the round-trip comparison rejects strings that parse to a DIFFERENT
     *     day than they spell, which is the only thing that catches
     *     "0000-00-00" (-> year -1) and "2026-02-31" (-> Mar 03);
     *   - the year bounds reject a technically-valid but absurd year.
     */
    private function strictDate(?string $value): ?Carbon
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            // '!' zeroes the time fields, so the result is midnight of that day
            // rather than midnight-plus-the-current-clock.
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$date instanceof Carbon) {
            return null;
        }

        // The gate that matters: createFromFormat() does not fail on an
        // impossible day, it rolls it forward. If the parsed day does not spell
        // itself back out identically, the input was not a real date.
        if ($date->format('Y-m-d') !== $value) {
            return null;
        }

        if ($date->year < self::REPORT_MIN_YEAR || $date->year > self::REPORT_MAX_YEAR) {
            return null;
        }

        return $date;
    }

    /**
     * Turn a raw `date_from` / `date_to` pair into trustworthy day boundaries.
     *
     * @param  string|null  $notice  set to plain-language text when the input
     *                               had to be corrected or was discarded, so
     *                               the caller can tell the user instead of
     *                               quietly reporting on a different period.
     * @return array{0: Carbon, 1: Carbon}|null  null = caller falls back to its
     *                                           own default preset.
     */
    private function normaliseCustomRange(?string $from, ?string $to, ?string &$notice = null): ?array
    {
        // Incomplete was ALREADY handled correctly at both call sites before
        // this pass — showSummary() and resolveAnalyticsPeriod() each fell back
        // when a bound was missing. Handled here too so the rule lives in one
        // place, and silently, because an empty second box is someone still
        // filling the form in, not a mistake worth a message.
        if (($from === null || $from === '') || ($to === null || $to === '')) {
            return null;
        }

        $start = $this->strictDate($from);
        $end   = $this->strictDate($to);

        if ($start === null || $end === null) {
            $notice = 'That date range was not valid, so the default period is shown instead.';
            return null;
        }

        if ($start->gt($end)) {
            // Swap, don't refuse — see the class docblock.
            [$start, $end] = [$end, $start];
            $notice = 'The start date was after the end date, so the two were swapped.';
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }
}
