<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer Account Settings page has four tabs (Oct 2026), from
 * docs/design-refs/peachy-account-settings.html: Overview, Rewards, Profile,
 * Security. Switching is client-side only, so the server contract these tests
 * pin is the markup the script works on:
 *
 *  - the profile card sits above the tabs, outside every panel;
 *  - all four panels are in the HTML (inactive ones carry `hidden`, they are
 *    never left out) and every section sits in the panel the reference gives it;
 *  - all four panels, and the sticky Delete / Save bar, are inside the ONE
 *    account-update form, so Save submits every field whichever tab is open;
 *  - a rejected field starts the page on the tab that holds it, with the error
 *    printed next to the field.
 *
 * The page is parsed the way a browser would (DOMDocument), not string-matched,
 * so "inside the form" and "inside this panel" mean what they say. Behaviour in
 * a real browser (hash, arrow keys, no horizontal scroll) is covered by the
 * Playwright pass recorded in the task report, not here.
 */
class AccountSettingsTabsTest extends TestCase
{
    use DatabaseTransactions;

    private const TABS = ['overview', 'rewards', 'profile', 'security'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
        Cache::flush();
        config(['loyalty.free_dessert_visit_days' => 7]);
    }

    private function customer(array $attrs = []): User
    {
        return User::create(array_merge([
            'name'              => 'Tabs Test',
            'email'             => 'tabs-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $attrs));
    }

    private function page(User $user, bool $withBranch = true): string
    {
        $request = $this->actingAs($user, 'customer');
        if ($withBranch) {
            $request = $request->withSession(['branch_id' => 1]);
        }

        return $request->get(route('customer.account'))->assertOk()->getContent();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function one(DOMXPath $x, string $query): DOMElement
    {
        $node = $x->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, "not on the page: $query");

        return $node;
    }

    /** Is there a match for $query anywhere under the panel with this id? */
    private function inPanel(DOMXPath $x, string $panel, string $query): bool
    {
        return $x->query('//*[@id="p-' . $panel . '"]' . $query)->length > 0;
    }

    // ══════════ the four tabs ══════════

    public function test_the_four_tabs_render_as_an_accessible_tablist_defaulting_to_overview(): void
    {
        $x = $this->xpath($this->page($this->customer()));

        $list = $this->one($x, '//*[@role="tablist"]');
        $this->assertSame('Account sections', $list->getAttribute('aria-label'));

        $tabs = $x->query('.//*[@role="tab"]', $list);
        $this->assertSame(4, $tabs->length);

        foreach (self::TABS as $i => $name) {
            /** @var DOMElement $tab */
            $tab = $tabs->item($i);
            $this->assertSame('t-' . $name, $tab->getAttribute('id'));
            $this->assertSame('p-' . $name, $tab->getAttribute('aria-controls'));
            $this->assertSame('button', strtolower($tab->tagName));
            $this->assertSame('button', $tab->getAttribute('type'), 'a tab must not submit the form');

            $active = $name === 'overview';
            $this->assertSame($active ? 'true' : 'false', $tab->getAttribute('aria-selected'), "$name aria-selected");
            // Roving tabindex: only the selected tab is in the Tab order.
            $this->assertSame($active ? '0' : '-1', $tab->getAttribute('tabindex'), "$name tabindex");
        }

        $this->assertSame(
            ['Overview', 'Rewards', 'Profile', 'Security'],
            array_map(fn ($n) => preg_match('/^[A-Za-z]+/', trim($n->textContent), $m) ? $m[0] : '', iterator_to_array($tabs)),
        );
    }

    public function test_the_rewards_tab_carries_the_bonus_points_pill(): void
    {
        $user = $this->customer();
        $user->forceFill(['bonus_points' => 8])->save();

        $x = $this->xpath($this->page($user->fresh()));

        $this->assertSame('8 pts', trim($this->one($x, '//*[@data-testid="tab-rewards-count"]')->textContent));
        $this->assertTrue(
            $x->query('//*[@id="t-rewards"]//*[@data-testid="tab-rewards-count"]')->length === 1,
            'the pill belongs to the Rewards tab, not another',
        );
    }

    public function test_all_four_panels_are_in_the_html_and_only_overview_is_visible_by_default(): void
    {
        $x = $this->xpath($this->page($this->customer()));

        foreach (self::TABS as $name) {
            $panel = $this->one($x, '//*[@id="p-' . $name . '"]');
            $this->assertSame('tabpanel', $panel->getAttribute('role'));
            $this->assertSame('t-' . $name, $panel->getAttribute('aria-labelledby'));
            $this->assertSame($name !== 'overview', $panel->hasAttribute('hidden'), "$name hidden state");
        }
    }

    public function test_the_profile_card_sits_above_the_tabs_and_outside_every_panel(): void
    {
        $html = $this->page($this->customer());
        $x = $this->xpath($html);

        $this->one($x, '//*[@data-testid="account-header"]');
        $this->assertSame(0, $x->query('//*[@role="tabpanel"]//*[@data-testid="account-header"]')->length);
        $this->assertLessThan(strpos($html, 'role="tablist"'), strpos($html, 'data-testid="account-header"'));

        // The three mini stats are the header's, not the Overview's.
        foreach (['chip-points', 'chip-streak', 'chip-stamps'] as $chip) {
            $this->assertSame(1, $x->query('//*[@data-testid="account-header"]//*[@data-testid="' . $chip . '"]')->length, $chip);
        }
    }

    // ══════════ every section in the panel the reference gives it ══════════

    public function test_every_section_is_in_the_html_and_in_its_own_panel(): void
    {
        $x = $this->xpath($this->page($this->customer(['email_verified_at' => null])));

        // Overview: bonus banner, Streak Calendar, Reward Challenge, Reward Stamp Card.
        foreach (['bonus-banner', 'streak-calendar', 'free-dessert-challenge', 'free-dessert-card'] as $id) {
            $this->assertTrue($this->inPanel($x, 'overview', '//*[@data-testid="' . $id . '"]'), "$id belongs to Overview");
        }
        // Rewards: Account Benefits and Recent Bonuses.
        $this->assertTrue($this->inPanel($x, 'rewards', '//*[@id="benefits"]'));
        $this->assertTrue($this->inPanel($x, 'rewards', '//*[@id="recent-bonuses-title"]'));
        // Profile: Account Details.
        foreach (['name', 'address', 'contact_number', 'email'] as $field) {
            $this->assertTrue($this->inPanel($x, 'profile', '//input[@name="' . $field . '"]'), "$field belongs to Profile");
        }
        // Security: Email Verification and Password Management with both eye toggles.
        $this->assertTrue($this->inPanel($x, 'security', '//*[@data-testid="email-verification"]'));
        $this->assertTrue($this->inPanel($x, 'security', '//*[@onclick="resendVerification()"]'));
        foreach (['password', 'current_password'] as $field) {
            $this->assertTrue($this->inPanel($x, 'security', '//input[@name="' . $field . '"]'), "$field belongs to Security");
        }
        $this->assertSame(2, $x->query('//*[@id="p-security"]//*[@data-password-toggle]')->length);

        // Nothing leaked into the wrong panel.
        $this->assertFalse($this->inPanel($x, 'overview', '//input[@name="email"]'));
        $this->assertFalse($this->inPanel($x, 'rewards', '//*[@data-testid="streak-calendar"]'));
    }

    public function test_the_stamp_card_anchor_the_benefits_card_links_to_is_in_overview(): void
    {
        // account-benefits.blade.php links to #free-dessert from the Rewards tab;
        // the script opens the tab that holds the target, which is Overview.
        $x = $this->xpath($this->page($this->customer()));

        $this->assertTrue($this->inPanel($x, 'overview', '//*[@id="free-dessert"]'));
        $this->assertTrue($this->inPanel($x, 'overview', '//*[@id="streak"]'), 'the month links end in #streak');
    }

    public function test_the_choose_a_branch_card_is_in_the_overview_tab(): void
    {
        $x = $this->xpath($this->page($this->customer(), withBranch: false));

        $this->assertTrue($this->inPanel($x, 'overview', '//*[@data-testid="streak-choose-branch"]'));
        $this->assertSame(0, $x->query('//*[@data-testid="streak-choose-branch"]/ancestor::*[@role="tabpanel"][@id!="p-overview"]')->length);
    }

    // ══════════ one form, one action bar ══════════

    public function test_one_form_holds_all_four_panels_and_every_field(): void
    {
        $x = $this->xpath($this->page($this->customer()));
        $update = route('customer.account.update');

        $form = $this->one($x, '//form[@action="' . $update . '"]');
        $this->assertSame('account-form', $form->getAttribute('id'));

        foreach (self::TABS as $name) {
            $this->assertSame(
                1,
                $x->query('.//*[@id="p-' . $name . '"]', $form)->length,
                "panel $name is not inside the account-update form",
            );
        }
        foreach (['name', 'address', 'contact_number', 'email', 'password', 'current_password'] as $field) {
            $this->assertSame(1, $x->query('.//input[@name="' . $field . '"]', $form)->length, "$field is not in the form");
        }
        $this->assertSame(1, $x->query('.//input[@name="_method"][@value="PUT"]', $form)->length);
        $this->assertSame(1, $x->query('.//input[@name="_token"]', $form)->length);

        // The tab buttons are outside the form: they can never submit it.
        $this->assertSame(0, $x->query('.//*[@role="tab"]', $form)->length);
        // No second form anywhere, so nothing nests.
        $this->assertSame(1, $x->query('//form[@action="' . $update . '"]')->length);
    }

    public function test_the_save_and_delete_bar_is_in_the_form_but_in_no_panel_so_every_tab_shows_it(): void
    {
        $x = $this->xpath($this->page($this->customer()));

        $bar = $this->one($x, '//*[@data-testid="account-action-bar"]');
        $this->assertFalse($bar->hasAttribute('hidden'));
        $this->assertSame(0, $x->query('ancestor::*[@role="tabpanel"]', $bar)->length, 'a hidden panel would hide the bar');
        $this->assertSame(1, $x->query('ancestor::form[@id="account-form"]', $bar)->length);
        $this->assertSame(1, $x->query('.//button[@type="submit"][contains(normalize-space(.), "Save Changes")]', $bar)->length);
        $this->assertSame(1, $x->query('.//button[@onclick="confirmDelete()"]', $bar)->length);
        $this->assertStringContainsString('sticky', $bar->getAttribute('class'));
    }

    public function test_the_tab_script_handles_hash_and_arrow_keys(): void
    {
        $html = $this->page($this->customer());

        foreach (['location.hash', "history.replaceState", "'hashchange'", "'ArrowRight'", "'ArrowLeft'", "'Home'", "'End'", "'invalid'"] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    // ══════════ a rejected field opens its tab ══════════

    private function startTab(string $html): array
    {
        $x = $this->xpath($html);
        $list = $this->one($x, '//*[@role="tablist"]');
        $selected = $x->query('.//*[@role="tab"][@aria-selected="true"]', $list);
        $this->assertSame(1, $selected->length, 'exactly one tab is selected');
        $selectedTab = $selected->item(0);
        $this->assertInstanceOf(DOMElement::class, $selectedTab);

        $visible = [];
        foreach (self::TABS as $name) {
            if (! $this->one($x, '//*[@id="p-' . $name . '"]')->hasAttribute('hidden')) {
                $visible[] = $name;
            }
        }

        return [
            'selected' => $selectedTab->getAttribute('data-tab'),
            'visible'  => $visible,
            'forced'   => $list->getAttribute('data-start-forced'),
            'x'        => $x,
        ];
    }

    private function submit(User $user, array $overrides): string
    {
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->from(route('customer.account'))
            ->put(route('customer.account.update'), array_merge([
                'name'             => $user->name,
                'email'            => $user->email,
                'contact_number'   => '',
                'address'          => '',
                'password'         => '',
                'current_password' => '',
            ], $overrides))
            ->assertRedirect(route('customer.account'));

        return $this->get(route('customer.account'))->assertOk()->getContent();
    }

    public function test_a_rejected_email_opens_profile_and_prints_the_error_beside_the_field(): void
    {
        $user = $this->customer();

        $html = $this->submit($user, ['email' => 'not-an-email']);
        $tab = $this->startTab($html);

        $this->assertSame('profile', $tab['selected']);
        $this->assertSame(['profile'], $tab['visible']);
        $this->assertSame('1', $tab['forced']);
        $this->assertTrue($this->inPanel($tab['x'], 'profile', '//*[@data-testid="email-error"]'), 'the message is inside the open tab');
        // The toast still shows it too.
        $this->assertStringContainsString('data-toast', $html);
    }

    public function test_a_rejected_name_opens_profile(): void
    {
        $tab = $this->startTab($this->submit($this->customer(), ['name' => '']));

        $this->assertSame('profile', $tab['selected']);
        $this->assertTrue($this->inPanel($tab['x'], 'profile', '//*[@data-testid="name-error"]'));
    }

    public function test_a_weak_new_password_opens_security(): void
    {
        $user = $this->customer();

        $tab = $this->startTab($this->submit($user, ['password' => 'abc', 'current_password' => 'Aa1!aaaaaa']));

        $this->assertSame('security', $tab['selected']);
        $this->assertSame(['security'], $tab['visible']);
        $this->assertTrue($this->inPanel($tab['x'], 'security', '//*[@data-testid="password-error"]'));
    }

    public function test_a_missing_current_password_opens_security_even_though_the_email_is_on_profile(): void
    {
        $user = $this->customer();

        $html = $this->submit($user, ['email' => 'changed-' . Str::lower(Str::random(8)) . '@example.test']);
        $tab = $this->startTab($html);

        $this->assertSame('security', $tab['selected']);
        $this->assertTrue($this->inPanel($tab['x'], 'security', '//*[@data-testid="current-password-error"]'));
        $this->assertStringContainsString('Enter your current password to change your email or password.', $html);
    }

    public function test_a_saved_page_starts_on_the_default_tab_and_tells_the_script_to_return_to_where_you_saved(): void
    {
        $user = $this->customer();

        $html = $this->submit($user, ['name' => 'Renamed Customer']);
        $x = $this->xpath($html);

        $this->assertSame('Account updated successfully!', trim($this->one($x, '//*[@data-toast]')->textContent));
        $list = $this->one($x, '//*[@role="tablist"]');
        $this->assertSame('1', $list->getAttribute('data-saved'));
        $this->assertSame('0', $list->getAttribute('data-start-forced'));
        $this->assertSame('overview', $list->getAttribute('data-start-tab'));
        $this->assertSame('Renamed Customer', $user->fresh()->name, 'saving still saves');
    }

    public function test_a_clean_page_load_is_not_forced_and_not_marked_saved(): void
    {
        $list = $this->one($this->xpath($this->page($this->customer())), '//*[@role="tablist"]');

        $this->assertSame('overview', $list->getAttribute('data-start-tab'));
        $this->assertSame('0', $list->getAttribute('data-start-forced'));
        $this->assertSame('0', $list->getAttribute('data-saved'));
    }
}
