<?php

namespace App\Support;

use App\Models\AdminSignIn;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "Recent Sign-ins" card on Account Settings (Defect Report 5, item 3 —
 * Oct 2026): recording a portal sign-in, and the words the card shows for it.
 *
 * RECORDING
 * ---------
 * AdminAuthController::login() calls record() once the sign-in has succeeded
 * (after the session id is regenerated and SingleSession has claimed it). The
 * row's id and time are also put in the session, which is how the card knows
 * which row is "This device" and how the profile banner knows when this
 * sign-in happened. A password change regenerates the session id but keeps
 * its data, so both survive it.
 *
 * Recording must never stop anyone signing in: if the table is missing (a
 * deploy that skipped the migration) or the insert fails, the error is
 * reported and the login carries on.
 *
 * Only the newest KEEP_PER_USER rows per user are kept.
 *
 * WHAT IS NOT RECORDED
 * --------------------
 * A location. Nothing in the app can say where an IP address really is, so
 * the card shows the IP masked (112.204.x.x) instead of a guessed city.
 */
final class SignInLog
{
    public const KEEP_PER_USER = 20;

    /** Session key: ['id' => row id or null, 'at' => ISO-8601 time]. */
    public const SESSION_KEY = 'admin_sign_in';

    public static function record(Request $request, User $user): ?AdminSignIn
    {
        $now = now();

        try {
            $agent = self::parseUserAgent($request->userAgent());

            $row = AdminSignIn::create([
                'user_id' => $user->id,
                'signed_in_at' => $now,
                'browser' => $agent['browser'],
                'os' => $agent['os'],
                'device_type' => $agent['device'],
                'ip_address' => $request->ip(),
            ]);

            self::prune($user->id);
        } catch (\Throwable $e) {
            report($e);
            $row = null;
        }

        $request->session()->put(self::SESSION_KEY, [
            'id' => $row?->id,
            'at' => $now->toIso8601String(),
        ]);

        return $row;
    }

    /** Delete all but the newest KEEP_PER_USER sign-ins of one user. */
    public static function prune(int $userId): void
    {
        $keep = AdminSignIn::where('user_id', $userId)
            ->orderByDesc('signed_in_at')
            ->orderByDesc('id')
            ->limit(self::KEEP_PER_USER)
            ->pluck('id');

        AdminSignIn::where('user_id', $userId)->whereNotIn('id', $keep)->delete();
    }

    /**
     * The user's own sign-ins, newest first. Empty (never an error) when the
     * table is not there yet.
     *
     * @return Collection<int, AdminSignIn>
     */
    public static function recentFor(User $user): Collection
    {
        try {
            return AdminSignIn::where('user_id', $user->id)
                ->orderByDesc('signed_in_at')
                ->orderByDesc('id')
                ->limit(self::KEEP_PER_USER)
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /** The row id of this browser's sign-in, when it was recorded. */
    public static function currentId(Request $request): ?int
    {
        $id = $request->session()->get(self::SESSION_KEY . '.id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** When this browser signed in, or null for a session older than the log. */
    public static function currentSignedInAt(Request $request): ?Carbon
    {
        $at = $request->session()->get(self::SESSION_KEY . '.at');

        if (! is_string($at) || $at === '') {
            return null;
        }

        try {
            return Carbon::parse($at);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Browser, operating system and device type from a User-Agent string.
     * A small, ordered set of checks: Edge, Opera and Samsung Internet all
     * say "Chrome" too, and every iPhone says "Mac OS X", so the more specific
     * names are tested first.
     *
     * @return array{browser: string, os: string, device: string}
     */
    public static function parseUserAgent(?string $ua): array
    {
        $ua = (string) $ua;

        $browser = match (true) {
            (bool) preg_match('/\bEdg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/\bOPR\/|\bOpera\b/', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser\//', $ua) => 'Samsung Internet',
            (bool) preg_match('/\bFirefox\/|\bFxiOS\//', $ua) => 'Firefox',
            (bool) preg_match('/\bCriOS\/|Chrome\/|Chromium\//', $ua) => 'Chrome',
            (bool) preg_match('/\bSafari\//', $ua) && str_contains($ua, 'Version/') => 'Safari',
            default => 'Unknown browser',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            (bool) preg_match('/iPhone|iPod/', $ua) => 'iOS',
            str_contains($ua, 'iPad') => 'iPadOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            (bool) preg_match('/Macintosh|Mac OS X/', $ua) => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        $device = match (true) {
            (bool) preg_match('/iPad|Tablet/i', $ua) => 'tablet',
            str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile') => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod/', $ua) => 'mobile',
            default => 'desktop',
        };

        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }

    /** 112.204.31.7 -> 112.204.x.x; an IPv6 address keeps its first two groups. */
    public static function maskIp(?string $ip): string
    {
        $ip = trim((string) $ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0] . '.' . $parts[1] . '.x.x';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $groups = array_values(array_filter(explode(':', $ip), fn ($g) => $g !== ''));

            return implode(':', array_slice($groups, 0, 2)) . ':x:x';
        }

        return 'IP unknown';
    }

    /**
     * When a sign-in happened, in shop (Manila) time: "Just now",
     * "2 minutes ago", "Today, 9:15 AM", "Yesterday, 8:14 PM",
     * "Oct 24, 10:02 AM", or with the year once it is not this year.
     */
    public static function whenLabel(CarbonInterface $at, ?CarbonInterface $now = null): string
    {
        $tz = ShopDay::timezone();
        $now = ($now ?? now())->copy()->setTimezone($tz);
        $local = $at->copy()->setTimezone($tz);

        $seconds = $now->getTimestamp() - $local->getTimestamp();

        if ($seconds < 60) {
            return 'Just now';
        }

        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);

            return $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes') . ' ago';
        }

        if ($local->isSameDay($now)) {
            return 'Today, ' . $local->format('g:i A');
        }

        if ($local->isSameDay($now->copy()->subDay())) {
            return 'Yesterday, ' . $local->format('g:i A');
        }

        return $local->format($local->year === $now->year ? 'M j, g:i A' : 'M j, Y, g:i A');
    }

    /** The banner's "Signed in …" words: "just now", "5 minutes ago", "2 hours ago". */
    public static function signedInAgo(CarbonInterface $at, ?CarbonInterface $now = null): string
    {
        $now = $now ?? now();

        if ($now->getTimestamp() - $at->getTimestamp() < 60) {
            return 'just now';
        }

        return $at->diffForHumans($now, CarbonInterface::DIFF_RELATIVE_TO_NOW);
    }
}
