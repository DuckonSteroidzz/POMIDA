<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\StoreContact;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * StoreContact::forBranch() ran up to 8 uncached Setting::get() queries per
 * call (2 per social-URL key: branch-specific, then global fallback). Called
 * from three different controller call sites, a page that hits more than one
 * of them in the same request paid for the same branch's settings more than
 * once. Now memoized per request.
 */
class StoreContactMemoizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The memoization cache is a static array, so it survives across
        // tests within the same PHPUnit process. Start each test clean —
        // production gets this for free from the RequestHandled listener.
        StoreContact::clearCache();
    }

    public function test_repeat_calls_for_the_same_branch_in_one_request_do_not_requery(): void
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        StoreContact::forBranch(2);
        $afterFirstCall = count($queries);
        $this->assertGreaterThan(0, $afterFirstCall, 'the first call should hit the database');

        StoreContact::forBranch(2);
        StoreContact::forBranch(2);
        StoreContact::forBranch(2);

        $this->assertSame(
            $afterFirstCall,
            count($queries),
            'repeat forBranch() calls for the same branch within one request must be served from memory, not re-queried'
        );
    }

    public function test_a_settings_update_is_not_stale_on_the_next_request(): void
    {
        Setting::updateOrCreate(
            ['key' => 'facebook_url', 'branch_id' => 2],
            ['value' => 'https://facebook.com/before-update']
        );

        $before = StoreContact::forBranch(2);
        $this->assertSame('https://facebook.com/before-update', $before['facebook_url']);

        Setting::updateOrCreate(
            ['key' => 'facebook_url', 'branch_id' => 2],
            ['value' => 'https://facebook.com/after-update']
        );

        // The memoized value from the call above would still be stale here —
        // this is exactly the boundary the per-request cache must respect.
        // In production that boundary is a fresh HTTP request; the app clears
        // it via the RequestHandled listener registered in AppServiceProvider.
        StoreContact::clearCache();

        $after = StoreContact::forBranch(2);
        $this->assertSame('https://facebook.com/after-update', $after['facebook_url']);
    }

    public function test_cache_is_cleared_automatically_between_real_http_requests(): void
    {
        Setting::updateOrCreate(
            ['key' => 'facebook_url', 'branch_id' => 1],
            ['value' => 'https://facebook.com/first']
        );

        $html = $this->withSession(['order_type' => 'dine_in', 'table_number' => '77', 'branch_id' => 1])
            ->get('/customer/more')->assertOk()->getContent();
        $this->assertStringContainsString('https://facebook.com/first', $html);

        Setting::updateOrCreate(
            ['key' => 'facebook_url', 'branch_id' => 1],
            ['value' => 'https://facebook.com/second']
        );

        $html = $this->withSession(['order_type' => 'dine_in', 'table_number' => '77', 'branch_id' => 1])
            ->get('/customer/more')->assertOk()->getContent();
        $this->assertStringContainsString('https://facebook.com/second', $html);
        $this->assertStringNotContainsString('https://facebook.com/first', $html);
    }
}
