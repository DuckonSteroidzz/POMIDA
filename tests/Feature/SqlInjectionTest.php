<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQL injection.
 *
 * RESULT: CLEAN. No injection path was found. This file exists to record the
 * evidence for that and to make a future regression noisy, not because a
 * vulnerability was fixed.
 *
 * WHAT WAS AUDITED
 * ----------------
 * Every raw SQL construct in app/, database/ and routes/ — DB::raw, whereRaw,
 * selectRaw, orderByRaw, havingRaw, groupByRaw, DB::select, DB::statement,
 * DB::unprepared. 25 occurrences in total:
 *
 *   23  constant literal SQL with no variable content whatsoever, e.g.
 *       DB::raw('SUM(quantity) as total_qty'), whereRaw('1 = 0'),
 *       orderByRaw('LENGTH(table_number), table_number'), and the DDL in two
 *       migrations. Nothing user-supplied can reach any of them because
 *       nothing at all is substituted into them.
 *
 *    2  receive user input, and both use a `?` placeholder with a bindings
 *       array:
 *         HandlesPasswordReset::resolveResettableUser()
 *             whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
 *         TableOccupancy (dine-in table matching)
 *             whereRaw('UPPER(TRIM(table_number)) = ?', [...])
 *
 * THE WORKED EXAMPLE — is that binding real, or does it only look real?
 * ---------------------------------------------------------------------
 * Checked by capturing what Laravel actually sends, via DB::listen, for four
 * different inputs to resolveResettableUser():
 *
 *   input: pedro@gmail.com
 *   input: x' OR '1'='1
 *   input: x'; DROP TABLE users; --
 *   input: x' UNION SELECT * FROM users --
 *
 *   SQL for ALL FOUR (byte-identical):
 *     select * from `users` where LOWER(email) = ? and `role` in (?)
 *       and `is_active` = ? limit 1
 *   BINDINGS: ["x'; drop table users; --", "customer", true]   (etc.)
 *
 * The payload never enters the SQL text. It appears only in the bindings
 * array, which PDO transmits separately as a prepared-statement parameter, so
 * the database never parses it as SQL. That is genuine parameterisation, not
 * cosmetic. The `users` table was still present afterwards.
 *
 * WHY THERE IS NO COLUMN-NAME INJECTION EITHER
 * ---------------------------------------------
 * Binding does NOT protect a column name or a sort direction, so those were
 * checked separately:
 *
 *   - no orderBy($variable) anywhere; every orderBy names a literal column
 *   - no request value is used as a column name or sort direction
 *   - every raw SQL string in the codebase is SINGLE-quoted, so PHP string
 *     interpolation cannot occur inside one
 *   - zero occurrences of string concatenation building a raw SQL string
 *
 * The last two are what test_no_raw_sql_string_is_built_from_a_variable()
 * pins, because they are the structural property that keeps this clean.
 */
class SqlInjectionTest extends TestCase
{
    use DatabaseTransactions;

    /** Payloads that would break out of a naively-interpolated string. */
    private const PAYLOADS = [
        "x' OR '1'='1",
        "x'; DROP TABLE users; --",
        "x' UNION SELECT * FROM users --",
        "' OR 1=1 --",
        "admin'--",
        "x\\' OR \\'1\\'=\\'1",
        "1; DELETE FROM orders WHERE 1=1; --",
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════
    // The worked example, asserted rather than described
    // ══════════════════════════════════════════════════════════════════

    /**
     * The SQL text emitted for a malicious email must be identical to the SQL
     * text emitted for an ordinary one. If a payload ever changes the query
     * string itself, it is being interpolated rather than bound.
     */
    public function test_the_password_reset_lookup_binds_rather_than_interpolates(): void
    {
        $controller = app(\App\Http\Controllers\Customer\AuthController::class);
        $method = new \ReflectionMethod($controller, 'resolveResettableUser');
        $method->setAccessible(true);

        $captured = [];
        DB::listen(function ($query) use (&$captured) {
            $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $method->invoke($controller, 'ordinary@example.com');
        $baseline = $captured[0]['sql'] ?? null;
        $this->assertNotNull($baseline, 'no query was captured — the probe is not measuring anything');

        foreach (self::PAYLOADS as $payload) {
            $captured = [];
            $method->invoke($controller, $payload);

            $this->assertNotEmpty($captured, "no query ran for payload: {$payload}");
            $this->assertSame(
                $baseline,
                $captured[0]['sql'],
                "SQL INJECTION: the payload changed the SQL text, so it is being interpolated: {$payload}"
            );

            // And the payload must be present as a BINDING — proving it really
            // was carried, not silently dropped, which would make the
            // assertion above true for the wrong reason.
            $this->assertContains(
                strtolower(trim($payload)),
                array_map(fn ($b) => is_string($b) ? strtolower($b) : $b, $captured[0]['bindings']),
                "the payload never reached the query at all, so this proves nothing: {$payload}"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Through the real HTTP endpoints
    // ══════════════════════════════════════════════════════════════════

    /**
     * The reachable front door to that lookup. The users table must survive,
     * and no account may be resolved by a tautology.
     */
    public function test_the_forgot_password_endpoint_resists_injection(): void
    {
        $usersBefore = User::count();
        $this->assertGreaterThan(0, $usersBefore, 'CONTROL: there must be users to destroy');

        foreach (self::PAYLOADS as $payload) {
            $response = $this->post('/customer/forgot-password', ['email' => $payload]);

            $this->assertNotSame(500, $response->getStatusCode(), "payload caused a server error: {$payload}");

            // A tautology must not resolve an account, which would show up as
            // the flow advancing to the verification step.
            $this->assertNull(
                session('customer_password_reset.email'),
                "SQL INJECTION: a payload resolved an account: {$payload}"
            );
        }

        $this->assertSame($usersBefore, User::count(), 'SQL INJECTION: the users table was modified');
        $this->assertTrue(\Schema::hasTable('users'), 'SQL INJECTION: the users table was dropped');
    }

    public function test_the_admin_forgot_password_endpoint_resists_injection(): void
    {
        $usersBefore = User::count();

        foreach (self::PAYLOADS as $payload) {
            $response = $this->post('/admin/forgot-password', ['email' => $payload]);

            $this->assertNotSame(500, $response->getStatusCode(), "payload caused a server error: {$payload}");
            $this->assertNull(
                session('admin_password_reset.email'),
                "SQL INJECTION: a payload resolved an admin account: {$payload}"
            );
        }

        $this->assertSame($usersBefore, User::count());
        $this->assertTrue(\Schema::hasTable('users'));
    }

    /**
     * The login forms are the other place an email string is taken from a
     * stranger. These go through Eloquent rather than whereRaw, but they are
     * the highest-value target, so they are exercised too.
     */
    public function test_the_login_endpoints_resist_injection(): void
    {
        $usersBefore = User::count();

        foreach (self::PAYLOADS as $payload) {
            $this->post('/customer/login', ['email' => $payload, 'password' => $payload]);
            $this->assertFalse(
                \Illuminate\Support\Facades\Auth::guard('customer')->check(),
                "SQL INJECTION: a payload authenticated a customer session: {$payload}"
            );

            $this->post('/admin/login', ['email' => $payload, 'password' => $payload]);
            $this->assertFalse(
                \Illuminate\Support\Facades\Auth::guard('admin')->check(),
                "SQL INJECTION: a payload authenticated an admin session: {$payload}"
            );
        }

        $this->assertSame($usersBefore, User::count());
    }

    /**
     * The other whereRaw — dine-in table matching. table_number arrives from a
     * scanned QR code and the session, both attacker-influenced.
     */
    public function test_the_table_number_lookup_resists_injection(): void
    {
        $ordersBefore = Order::count();
        $this->assertGreaterThan(0, $ordersBefore, 'CONTROL: there must be orders to destroy');

        foreach (self::PAYLOADS as $payload) {
            $response = $this->withSession([
                'branch_id'    => 1,
                'order_type'   => 'dine_in',
                'table_number' => $payload,
            ])->get('/customer/orders');

            $this->assertNotSame(500, $response->getStatusCode(), "payload caused a server error: {$payload}");
        }

        $this->assertSame($ordersBefore, Order::count(), 'SQL INJECTION: the orders table was modified');
        $this->assertTrue(\Schema::hasTable('orders'));
    }

    // ══════════════════════════════════════════════════════════════════
    // The structural property that keeps this clean
    // ══════════════════════════════════════════════════════════════════

    /**
     * No raw SQL string may be built from a variable — by concatenation, by
     * interpolation, or by being double-quoted (which permits interpolation
     * even where none is written today).
     *
     * This is the guard that matters. Every injection test above passes
     * against today's code, and would keep passing right up until someone
     * writes whereRaw("... {$sort}") somewhere new. This test is what would
     * notice that.
     */
    public function test_no_raw_sql_string_is_built_from_a_variable(): void
    {
        $rawFunctions = 'whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|whereRaw|DB::raw|DB::select|DB::statement|DB::unprepared';
        $offenders = [];

        foreach ([app_path(), base_path('routes'), base_path('database')] as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $source = file_get_contents($file->getPathname());

                // A double-quoted raw string: interpolation is possible even
                // if nothing is interpolated right now.
                if (preg_match('/(' . $rawFunctions . ')\s*\(\s*"/', $source)) {
                    $offenders[] = "{$relative} — raw SQL in a double-quoted string";
                }

                // Concatenation building the SQL text.
                if (preg_match('/(' . $rawFunctions . ')\s*\(\s*\'[^\']*\'\s*\./', $source)) {
                    $offenders[] = "{$relative} — raw SQL built by concatenation";
                }

                // A bare variable as the whole SQL argument.
                if (preg_match('/(' . $rawFunctions . ')\s*\(\s*\$/', $source)) {
                    $offenders[] = "{$relative} — a variable used as the raw SQL string";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "raw SQL is being built from variables, which parameter binding does not protect:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * Column names and sort directions cannot be bound, so they must never
     * come from a request. Pinned as an allowlist check: no orderBy() call
     * anywhere may take a variable.
     */
    public function test_no_order_by_takes_a_variable_column_or_direction(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            // orderBy($col) or orderBy('x', $dir) — either half from a variable.
            if (preg_match('/->orderBy\s*\(\s*\$/', $source)
                || preg_match('/->orderBy\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*\$/', $source)) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these files pass a variable to orderBy(); a column name or direction cannot be "
            . "parameterised and needs an explicit allowlist:\n  " . implode("\n  ", $offenders)
        );
    }
}
