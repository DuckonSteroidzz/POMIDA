<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Branch Management redesign (Defect Report 5, item 2 — Oct 2026), built to
 * docs/design-refs/peachy-branch.html with REAL branches.
 *
 * Pins the page's key elements, the Viewing bar (All Branches = everything; a
 * branch = just that branch, with its details opened), who can open the page
 * (owner only, unchanged), and the close-a-branch guards now that the edit form
 * has an "Accept orders from this branch" switch. The search / filter / paging
 * / menu behaviour runs in the browser and was verified with Playwright.
 */
class BranchManagementPageTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role, ?int $branchId = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'branch_id' => $branchId,
            'is_active' => true,
            'email' => 'bmp-' . $role . '-' . uniqid() . '@example.test',
        ]);
    }

    private function page(array $session = ['selected_branch_id' => 'all'], string $query = ''): string
    {
        return $this->actingAs($this->user('admin'), 'admin')
            ->withSession($session)
            ->get(route('admin.branches') . $query)
            ->assertOk()
            ->getContent();
    }

    /** The page's boot JSON (which branch opens on arrival). */
    private function boot(string $html): array
    {
        preg_match('#<script type="application/json" id="bmBoot">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? '{}', true);
    }

    /** @return int[] the branch ids rendered as rows */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-branch-row="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    public function test_the_page_has_the_designs_key_elements(): void
    {
        $html = $this->page();

        foreach ([
            'Branch Management', 'Search, filter and manage all branches', 'Add New Branch',
            'Total branches', '>Open<', '>Inactive<', 'Branch List',
            'placeholder="Search name, code, address, contact…"',
            'data-status="">All</button>', 'data-status="open">Open</button>', 'data-status="closed">Inactive</button>',
            'id="bmReset"', '<option value="15" selected>15</option>', '<option value="25">25</option>', '<option value="50">50</option>',
            'id="bmPrev"', 'id="bmNext"',
            'data-act="view"', 'data-act="edit"', 'data-act="toggle"',
            'Store Information', 'Social Media', 'GCash Payment',
            'GCash Payment Settings', 'Keep current QR', 'Accept orders from this branch', 'Save Store Information',
            "GCash is disabled — customers won't see it as a payment option for this branch.",
            'JPG, JPEG, PNG, or WEBP · Maximum 5 MB',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing: {$needle}");
        }

        // The three existing routes, and nothing else, are posted to.
        $this->assertStringContainsString('action="' . route('admin.branches.store') . '"', $html);
        $this->assertStringContainsString('"update_url":' . json_encode(route('admin.branches.update', Branch::first()->id)), $html);
        $this->assertStringContainsString('"toggle_url":' . json_encode(route('admin.branches.toggle', Branch::first()->id)), $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="gcash_qr"', $html);
        $this->assertStringContainsString('name="gcash_number"', $html);
    }

    public function test_the_gcash_number_field_has_the_agreed_placeholder_and_no_e_g(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('placeholder="09XX XXX XXXX"', $html);
        $this->assertDoesNotMatchRegularExpression('/e\.g\./i', $html, 'no "e.g." anywhere on the page');
    }

    public function test_every_real_branch_is_a_row_with_its_code_status_and_menu(): void
    {
        $html = $this->page();

        $this->assertEqualsCanonicalizing(Branch::pluck('id')->all(), $this->rowIds($html));

        foreach (Branch::all() as $branch) {
            $this->assertStringContainsString('<span class="bm-code">' . e($branch->code) . '</span>', $html);
            $this->assertStringContainsString('aria-label="Actions for ' . e($branch->name) . '"', $html);
        }

        $open = Branch::where('is_active', true)->count();
        $this->assertStringContainsString('data-stat="total">' . Branch::count() . '<', $html);
        $this->assertStringContainsString('data-stat="open">' . $open . '<', $html);
        $this->assertStringContainsString('data-stat="inactive">' . (Branch::count() - $open) . '<', $html);

        $main = Branch::where('is_main_branch', true)->first();
        if ($main) {
            $this->assertMatchesRegularExpression('/data-branch-row="' . $main->id . '".*?bm-badge-main">Main</s', $html);
        }
    }

    /**
     * Found in the browser: the hours are cast datetime:H:i, and taking a
     * substr of that value gave the DATE ("2026-"). The details panel read
     * "12:undefined AM" and the edit form's time inputs came up empty, so a
     * save would have wiped the branch's hours.
     */
    public function test_the_edit_form_is_fed_the_real_hours(): void
    {
        $b = Branch::create(['name' => 'Hours Branch', 'code' => 'HR' . substr(uniqid(), -6), 'address' => 'x', 'is_active' => true,
            'opening_time' => '08:00:00', 'closing_time' => '17:30:00']);

        $html = $this->page();
        preg_match('#<script type="application/json" id="bmData">(.*?)</script>#s', $html, $m);
        $row = collect(json_decode($m[1], true))->firstWhere('id', $b->id);

        $this->assertSame('08:00', $row['opening_time']);
        $this->assertSame('17:30', $row['closing_time']);
        $this->assertMatchesRegularExpression('/data-branch-row="' . $b->id . '".*?8:00 AM – 5:30 PM/s', $html);
    }

    public function test_branch_names_are_escaped(): void
    {
        $b = Branch::create(['name' => 'Evil <script>alert(1)</script>', 'code' => 'XSS' . substr(uniqid(), -5), 'address' => 'x', 'is_active' => true]);

        $html = $this->page();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertContains($b->id, $this->rowIds($html));
    }

    // ══════════ the Viewing bar ══════════

    public function test_the_viewing_bar_is_shown_on_this_page(): void
    {
        $this->assertStringContainsString('data-pc-branch-select', $this->page());
    }

    public function test_all_branches_shows_every_branch_and_opens_nothing(): void
    {
        $html = $this->page(['selected_branch_id' => 'all']);

        $this->assertCount(Branch::count(), $this->rowIds($html));
        $this->assertNull($this->boot($html)['open']);
    }

    public function test_a_chosen_branch_narrows_the_list_and_opens_its_details(): void
    {
        $branch = Branch::orderBy('id')->skip(1)->firstOrFail();

        $html = $this->page(['selected_branch_id' => $branch->id]);

        $this->assertSame([$branch->id], $this->rowIds($html));
        $this->assertSame($branch->id, $this->boot($html)['open']);
        $this->assertStringContainsString('data-stat="total">1<', $html);
    }

    public function test_the_choice_can_come_from_the_query_and_is_remembered(): void
    {
        $branch = Branch::orderBy('id')->firstOrFail();
        $owner = $this->user('admin');

        $this->actingAs($owner, 'admin')->get(route('admin.branches') . '?branch=' . $branch->id)->assertOk();
        $this->assertSame($branch->id, session('selected_branch_id'), 'the same saved choice as every other page');

        $html = $this->actingAs($owner, 'admin')->get(route('admin.branches') . '?branch=all')->assertOk()->getContent();
        $this->assertCount(Branch::count(), $this->rowIds($html));
        $this->assertSame('all', session('selected_branch_id'));
    }

    public function test_a_nonsense_branch_value_is_ignored(): void
    {
        $html = $this->page(['selected_branch_id' => 'all'], '?branch=999999');

        $this->assertCount(Branch::count(), $this->rowIds($html));
        $this->assertSame('all', session('selected_branch_id'));
    }

    // ══════════ who can open it (unchanged: owner only) ══════════

    public static function nonOwners(): array
    {
        return ['staff' => ['staff'], 'supervisor' => ['supervisor']];
    }

    /**
     * @dataProvider nonOwners
     */
    public function test_non_owners_still_cannot_open_the_page_even_with_a_crafted_branch(string $role): void
    {
        $branch = Branch::orderBy('id')->firstOrFail();

        $this->actingAs($this->user($role, $branch->id), 'admin')
            ->get(route('admin.branches') . '?branch=' . Branch::orderByDesc('id')->value('id'))
            ->assertRedirect(route('admin.home'));
    }

    // ══════════ forms ══════════

    public function test_a_duplicate_code_is_refused_with_a_clear_message(): void
    {
        $existing = Branch::orderBy('id')->firstOrFail();

        $this->actingAs($this->user('admin'), 'admin')
            ->from(route('admin.branches'))
            ->post(route('admin.branches.store'), ['_form' => 'create', 'name' => 'Dup ' . uniqid(), 'code' => strtolower($existing->code), 'address' => 'x'])
            ->assertSessionHasErrors(['code' => 'That branch code is already used by another branch. Codes must be unique.']);
    }

    public function test_a_failed_save_reopens_the_form_it_came_from(): void
    {
        $branch = Branch::orderBy('id')->firstOrFail();

        $html = $this->actingAs($this->user('admin'), 'admin')
            ->withSession(['selected_branch_id' => 'all', '_old_input' => ['_form' => 'edit', '_branch_id' => (string) $branch->id, 'name' => 'Typed name']])
            ->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['gcash_number' => ['bad number']]))])
            ->get(route('admin.branches'))
            ->assertOk()
            ->getContent();

        $boot = $this->boot($html);
        $this->assertSame('edit', $boot['form']);
        $this->assertSame((string) $branch->id, $boot['old']['_branch_id']);
        $this->assertStringContainsString('data-testid="edit-errors"', $html);
    }

    public function test_the_edit_form_cannot_close_the_main_branch(): void
    {
        $main = Branch::where('is_main_branch', true)->where('is_active', true)->first();
        if (!$main) {
            $this->markTestSkipped('no open main branch in this database');
        }

        $this->actingAs($this->user('admin'), 'admin')
            ->from(route('admin.branches'))
            ->put(route('admin.branches.update', $main->id), ['name' => $main->name, 'address' => $main->address])
            ->assertSessionHasErrors(['is_active' => 'The main branch cannot be closed.']);

        $this->assertTrue((bool) $main->fresh()->is_active);
    }

    public function test_the_edit_form_cannot_close_the_last_open_branch(): void
    {
        $keep = Branch::where('is_main_branch', false)->orderBy('id')->firstOrFail();
        Branch::where('id', '!=', $keep->id)->update(['is_active' => false]);
        $keep->update(['is_active' => true]);

        $this->actingAs($this->user('admin'), 'admin')
            ->from(route('admin.branches'))
            ->put(route('admin.branches.update', $keep->id), ['name' => $keep->name, 'address' => $keep->address])
            ->assertSessionHasErrors(['is_active' => 'At least one branch must stay open for customers to order from.']);
    }
}
