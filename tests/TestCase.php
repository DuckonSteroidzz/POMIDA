<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const REQUIRED_DATABASE = 'pomida_db_testing';

    /**
     * `php artisan tinker --env=testing` (and any other non-PHPUnit artisan
     * command) does NOT read phpunit.xml's <env> block — that file only
     * feeds PHPUnit itself. Without an .env.testing file, --env=testing is
     * then a no-op and DB_DATABASE quietly stays whatever .env says (the
     * live pomida_db). This put a stray row in pomida_db on 2026-09-29. Every
     * test now refuses to run at all unless connected to the real testing
     * database, so that mistake can never write through a test again.
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::guardTestingDatabase(DB::selectOne('select database() as d')->d);
    }

    /** Pure on purpose — see TestCaseDatabaseGuardTest, which checks it without a live DB connection. */
    public static function guardTestingDatabase(?string $resolved): void
    {
        if ($resolved !== self::REQUIRED_DATABASE) {
            throw new RuntimeException(
                'Refusing to run: resolved database is "' . ($resolved ?? 'null') . '", not "'
                . self::REQUIRED_DATABASE . '". artisan/tinker commands do not read phpunit.xml — '
                . 'set DB_DATABASE=' . self::REQUIRED_DATABASE . ' explicitly.'
            );
        }
    }
}
