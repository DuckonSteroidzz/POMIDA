<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Setting;
use App\Support\StoreContact;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression guard for the StoreContact fallback-key mismatch (Sept 2026,
 * lower-severity bundle item #1).
 *
 * StoreContact::forBranch() fell through to Setting::get('business_contact'),
 * ('business_email') and ('business_address') when a branch had no
 * contact_number / email / address of its own. Nothing seeds those keys —
 * SettingsSeeder only ever writes contact_phone / contact_email /
 * contact_address — so the fallback silently missed the settings table
 * entirely and always returned the hardcoded literal default, even after an
 * admin edited the real setting.
 *
 * These tests seed the CORRECT keys with values that are byte-distinct from
 * both any real branch data and the hardcoded literal defaults, so a
 * regression back to the old wrong keys is caught by getting the literal
 * default (or the wrong branch's data) instead.
 */
class StoreContactFallbackKeysTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContact::clearCache();
    }

    /**
     * contact_number and email are nullable columns, so a branch can
     * legitimately have neither set — the realistic case the fallback exists
     * for (e.g. a newly created branch before its own contact info is filled
     * in). address is NOT NULL at the schema level, so it always carries a
     * real value here; the address key is covered separately below by the
     * "no branch resolves at all" case, which is the only way that fallback
     * can actually fire.
     */
    private function branchWithNoOwnContactOrEmail(): Branch
    {
        return Branch::create([
            'name'           => 'SCFK No-Contact Branch ' . uniqid(),
            'code'           => 'SCFK' . substr((string) uniqid(), -6),
            'address'        => 'placeholder address, not under test',
            'contact_number' => null,
            'email'          => null,
            'is_active'      => true,
            'is_main_branch' => false,
        ]);
    }

    public function test_fallback_reads_the_actually_seeded_contact_phone_key(): void
    {
        $branch = $this->branchWithNoOwnContactOrEmail();

        Setting::updateOrCreate(
            ['key' => 'contact_phone', 'branch_id' => null],
            ['value' => '0999 555 1234']
        );

        $contact = StoreContact::forBranch($branch->id);

        $this->assertSame(
            '0999 555 1234',
            $contact['contact_number'],
            'the fallback must read the seeded contact_phone key, not the nonexistent business_contact key'
        );
    }

    public function test_fallback_reads_the_actually_seeded_contact_email_key(): void
    {
        $branch = $this->branchWithNoOwnContactOrEmail();

        Setting::updateOrCreate(
            ['key' => 'contact_email', 'branch_id' => null],
            ['value' => 'scfk-test@example.test']
        );

        $contact = StoreContact::forBranch($branch->id);

        $this->assertSame(
            'scfk-test@example.test',
            $contact['email'],
            'the fallback must read the seeded contact_email key, not the nonexistent business_email key'
        );
    }

    /**
     * address is NOT NULL on the branches table, so $branch?->address is
     * never null for any branch that resolves — that fallback can only ever
     * fire when NO branch resolves at all (Branch::find() misses, there is
     * no main branch, and there is no active branch either). Every existing
     * active branch is closed for the duration of this one test's own
     * transaction (mirrors the established pattern in ClosedBranchPickupTest
     * / AdminDeleteGuardsTest etc.) and rolled back automatically afterwards
     * — nothing here is a permanent change.
     */
    public function test_fallback_reads_the_actually_seeded_contact_address_key_when_no_branch_resolves(): void
    {
        Branch::query()->update(['is_active' => false, 'is_main_branch' => false]);

        Setting::updateOrCreate(
            ['key' => 'contact_address', 'branch_id' => null],
            ['value' => '123 SCFK Test Street']
        );

        $contact = StoreContact::forBranch(999999);

        $this->assertSame(
            '123 SCFK Test Street',
            $contact['address'],
            'the fallback must read the seeded contact_address key, not the nonexistent business_address key'
        );
    }

    /**
     * The negative half of the guard: if the wrong keys ever come back, the
     * setting values above are never read at all, and forBranch() quietly
     * returns the hardcoded literal defaults instead.
     */
    public function test_the_old_wrong_fallback_keys_are_never_written_or_read(): void
    {
        $branch = $this->branchWithNoOwnContactOrEmail();

        Setting::updateOrCreate(['key' => 'contact_phone', 'branch_id' => null], ['value' => '0999 555 1234']);
        Setting::updateOrCreate(['key' => 'contact_email', 'branch_id' => null], ['value' => 'scfk-test@example.test']);

        $contact = StoreContact::forBranch($branch->id);

        $this->assertNotSame('0917 120 3627', $contact['contact_number']);
        $this->assertNotSame('peachycakesdelicafe@gmail.com', $contact['email']);

        $this->assertNull(
            Setting::where('key', 'business_contact')->whereNull('branch_id')->first(),
            'business_contact must never be a key StoreContact relies on'
        );
        $this->assertNull(
            Setting::where('key', 'business_email')->whereNull('branch_id')->first(),
            'business_email must never be a key StoreContact relies on'
        );
        $this->assertNull(
            Setting::where('key', 'business_address')->whereNull('branch_id')->first(),
            'business_address must never be a key StoreContact relies on'
        );
    }
}
