<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RuntimeException;
use Tests\TestCase;

/**
 * Mechanical guard against the exact trap that put a stray row in the live
 * pomida_db on 2026-09-29: `php artisan tinker --env=testing` silently kept
 * .env's DB_DATABASE (pomida_db) because tinker never reads phpunit.xml.
 * Tests\TestCase::guardTestingDatabase() now runs in every feature test's
 * setUp() and refuses to proceed unless the resolved database is exactly
 * pomida_db_testing.
 *
 * Plain PHPUnit\Framework\TestCase on purpose (matches ExampleTest) — the
 * guard is checked as a pure function of the resolved name, not by actually
 * connecting to the wrong database.
 */
class TestCaseDatabaseGuardTest extends PHPUnitTestCase
{
    public function test_guard_passes_for_the_real_testing_database(): void
    {
        TestCase::guardTestingDatabase('pomida_db_testing');
        $this->addToAssertionCount(1);
    }

    public function test_guard_aborts_for_the_live_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pomida_db_testing/');

        TestCase::guardTestingDatabase('pomida_db');
    }

    public function test_guard_aborts_when_the_database_cannot_be_resolved_at_all(): void
    {
        $this->expectException(RuntimeException::class);

        TestCase::guardTestingDatabase(null);
    }
}
