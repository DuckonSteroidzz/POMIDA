<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The RECEIPT and the COMPLETED ORDERS print output must put only content on
 * the paper — no navigation, burger, branch selector, notification bell or
 * action buttons — and a status badge must print as plain words rather than a
 * rounded, filled pill. The on-screen views are unchanged, and the Summary
 * report's own print styling (built and verified in an earlier pass) is left
 * exactly as it was.
 *
 * Assertions are isolated to the element under test: CSS rules are matched
 * against their own selector block, and markup checks use DOMXPath rather than
 * a bare assertSee().
 */
class ReceiptAndCompletedOrdersPrintTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'PRNSURF';
    private const ORDER_PREFIX = 'PRNS-';

    /** MAX(id) per table BEFORE this run — the high-water marks. */
    private array $highWater = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['orders', 'order_items'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }
    }

    /**
     * BOUNDED cleanup: rows ABOVE this run's high-water mark AND carrying this
     * suite's own name prefix. Never an id-only bound.
     */
    protected function tearDown(): void
    {
        $orderIds = DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->pluck('id');

        DB::table('order_items')
            ->where('id', '>', $this->highWater['order_items'])
            ->whereIn('order_id', $orderIds)
            ->delete();

        DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->delete();

        $this->assertSame(
            0,
            DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count(),
            'this suite leaked an order row'
        );

        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function makeOrder(string $status = 'completed'): Order
    {
        $item = MenuItem::where('is_available', true)->orderBy('id')->firstOrFail();

        $order = Order::create([
            'order_number'   => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'      => 1,
            'type'           => 'dine_in',
            'table_number'   => '7',
            'status'         => $status,
            'subtotal'       => 250,
            'total'          => 250,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'completed_at'   => now(),
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => 250,
            'quantity'     => 2,
            'subtotal'     => 250,
        ]);

        return $order;
    }

    private function receiptHtml(): string
    {
        return $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.receipt', $this->makeOrder()->id))
            ->assertOk()
            ->getContent();
    }

    private function completedOrdersHtml(): string
    {
        $this->makeOrder('completed');

        return $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders'))
            ->assertOk()
            ->getContent();
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /**
     * The concatenated bodies of every @media print block in the document, so
     * a rule can be matched inside print context and nowhere else. (Shared
     * head partials contribute their own small print blocks; this page's block
     * is one of them.)
     */
    private function printBlock(string $html): string
    {
        $this->assertMatchesRegularExpression('/@media\s+print\s*\{/', $html);

        $body = '';
        $offset = 0;
        while (($start = strpos($html, '@media print', $offset)) !== false) {
            $depth = 0;
            for ($i = strpos($html, '{', $start); $i < strlen($html); $i++) {
                $ch = $html[$i];
                if ($ch === '{') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                }
                if ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $body .= $ch;
            }
            $body .= "\n";
            $offset = $i + 1;
        }

        return $body;
    }

    /** The declarations of the first rule whose selector list contains $selector. */
    private function ruleFor(string $css, string $selector): string
    {
        // Strip CSS comments so a comment sitting between } and the next { is
        // not mistaken for part of the selector list.
        $css = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertMatchesRegularExpression(
            '/(^|[\s,{}])' . preg_quote($selector, '/') . '\s*[,{]/',
            $css,
            "No rule for selector {$selector}."
        );

        $pos = 0;
        while (($brace = strpos($css, '{', $pos)) !== false) {
            $head = substr($css, $pos, $brace - $pos);
            $end = strpos($css, '}', $brace);
            $decls = substr($css, $brace + 1, $end - $brace - 1);

            foreach (explode(',', $head) as $sel) {
                if (trim($sel) === $selector) {
                    return $decls;
                }
            }
            $pos = $end + 1;
        }

        $this->fail("Selector {$selector} never appears as its own entry in a selector list.");
    }

    // ══════════════════════════════════════════════════════════════════════
    // RECEIPT
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_receipt_screen_chrome_is_all_flagged_no_print(): void
    {
        $xp = $this->xpath($this->receiptHtml());

        // Every top-level chrome region is a <header>/<nav> tag or carries
        // .no-print — both of which the print stylesheet sets to display:none.
        // (There is no branch selector or notification bell on this view.)
        foreach ($xp->query('/html/body/*') as $n) {
            if (in_array($n->nodeName, ['header', 'nav'], true)) {
                continue;
            }
            if ($n->nodeName === 'main') {
                continue;
            }
            $this->fail("Unexpected top-level receipt element <{$n->nodeName}> that print CSS may not hide.");
        }

        // The customer navbar and the mobile bottom nav both carry .no-print
        // as well, belt and braces.
        foreach ($xp->query('/html/body/header | /html/body/nav') as $n) {
            $this->assertStringContainsString('no-print', $n->getAttribute('class'));
        }

        // Every <button> on the page sits inside a <header>/<nav> chrome region
        // (so it is already hidden by the container rules) and, in any case,
        // the print block hides `button` outright — asserted separately below.
        $buttons = $xp->query('//button');
        $this->assertGreaterThan(0, $buttons->length);
        foreach ($buttons as $b) {
            $inChrome = false;
            for ($p = $b->parentNode; $p && $p->nodeName !== 'body'; $p = $p->parentNode) {
                if (in_array($p->nodeName, ['header', 'nav'], true)
                    || str_contains($p->getAttribute('class') ?? '', 'no-print')) {
                    $inChrome = true;
                    break;
                }
            }
            $this->assertTrue($inChrome, 'a receipt <button> is outside every no-print chrome region');
        }
    }

    public function test_the_receipt_print_css_removes_navigation_and_buttons(): void
    {
        $css = $this->printBlock($this->receiptHtml());

        $decls = $this->ruleFor($css, 'button');
        $this->assertMatchesRegularExpression('/display:\s*none\s*!important/', $decls);

        foreach (['nav', 'header', '.no-print'] as $sel) {
            $this->assertMatchesRegularExpression(
                '/display:\s*none\s*!important/',
                $this->ruleFor($css, $sel),
                "{$sel} is not hidden in the receipt print block"
            );
        }
    }

    public function test_a_receipt_status_badge_prints_without_its_outline_or_fill(): void
    {
        $html = $this->receiptHtml();

        // Screen: the Status pill still has its rounded outline, fill and icon.
        $xp = $this->xpath($html);
        $status = $xp->query("//dt[contains(text(),'Status')]/following-sibling::dd[1]/span");
        $this->assertSame(1, $status->length);
        $cls = $status->item(0)->getAttribute('class');
        $this->assertStringContainsString('rounded-full', $cls);
        $this->assertStringContainsString('text-white', $cls);

        // Print: the badge span inside the details list is stripped to plain text.
        $decls = $this->ruleFor($this->printBlock($html), '.card-surface dd span');
        $this->assertMatchesRegularExpression('/background:\s*transparent\s*!important/', $decls);
        $this->assertMatchesRegularExpression('/border-radius:\s*0\s*!important/', $decls);
        $this->assertMatchesRegularExpression('/border:\s*0\s*!important/', $decls);
    }

    public function test_the_receipt_total_stays_readable_on_paper(): void
    {
        $html = $this->receiptHtml();

        // Screen: the total block is still the filled maroon card.
        $this->assertStringContainsString('bg-peach-deep', $html);

        // Print: rendered as black text under a rule, not white-on-white.
        $decls = $this->ruleFor($this->printBlock($html), 'main .bg-peach-deep');
        $this->assertMatchesRegularExpression('/background:\s*transparent\s*!important/', $decls);
        $this->assertMatchesRegularExpression('/color:\s*#000\s*!important/', $decls);
        $this->assertMatchesRegularExpression('/border-top:\s*2px\s+solid\s+#000\s*!important/', $decls);
    }

    public function test_the_on_screen_receipt_is_unchanged(): void
    {
        $html = $this->receiptHtml();

        // The screen still shows the ticket, the details card and the thank-you
        // block exactly as before — none of the print work leaked onto it.
        $this->assertStringContainsString('Your Items', $html);
        $this->assertStringContainsString('Order Details', $html);
        $this->assertStringContainsString('Total Paid', $html);
        $this->assertStringContainsString('window.print()', $html);

        // The badge markup on screen is untouched: rounded pill, inline fill.
        $this->assertMatchesRegularExpression('/rounded-full[^"]*text-white[^"]*"\s+style="background:/', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // COMPLETED ORDERS
    // ══════════════════════════════════════════════════════════════════════

    public function test_completed_orders_print_hides_nav_burger_branchbar_and_bell(): void
    {
        $css = $this->printBlock($this->completedOrdersHtml());

        foreach (['.sidebar', '.pc-topbar', '.pc-burger', '.pc-overlay', '.no-print', '.pc-topline'] as $sel) {
            $this->assertMatchesRegularExpression(
                '/display:\s*none\s*!important/',
                $this->ruleFor($css, $sel),
                "{$sel} is not hidden in the completed-orders print block"
            );
        }
    }

    public function test_the_completed_orders_action_buttons_are_flagged_no_print(): void
    {
        $xp = $this->xpath($this->completedOrdersHtml());

        // The header holding "Print Selected" / "Print Filtered" carries .no-print.
        $header = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' co-header ')]");
        $this->assertSame(1, $header->length);
        $this->assertStringContainsString('no-print', $header->item(0)->getAttribute('class'));

        // Every per-row Print button sits in a .no-print cell.
        foreach ($xp->query("//button[contains(@onclick,'printReceipt')]") as $btn) {
            $td = $btn->parentNode;
            $this->assertStringContainsString('no-print', $td->getAttribute('class'));
        }
    }

    public function test_a_completed_orders_status_badge_prints_as_plain_text(): void
    {
        $html = $this->completedOrdersHtml();

        // Screen: the badge keeps its pill shape and coloured fill.
        $this->assertStringContainsString('.co-badge {', $html);
        $this->assertMatchesRegularExpression('/\.co-badge\s*\{[^}]*border-radius:\s*999px/s', $html);
        $this->assertMatchesRegularExpression('/\.co-badge-ok\s*\{[^}]*background:\s*#E6F4EC/s', $html);

        // Print: no border, no background, no rounding — plain words.
        $decls = $this->ruleFor($this->printBlock($html), '.co-badge');
        $this->assertMatchesRegularExpression('/border:\s*none\s*!important/', $decls);
        $this->assertMatchesRegularExpression('/background:\s*transparent\s*!important/', $decls);
        $this->assertMatchesRegularExpression('/border-radius:\s*0\s*!important/', $decls);
    }

    public function test_the_completed_orders_table_repeats_its_header_and_never_splits_a_row(): void
    {
        $css = $this->printBlock($this->completedOrdersHtml());

        $this->assertMatchesRegularExpression(
            '/\.co-table thead\s*\{[^}]*display:\s*table-header-group/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/page-break-inside:\s*avoid/',
            $this->ruleFor($css, '.co-table tr')
        );
    }

    public function test_the_on_screen_completed_orders_view_is_unchanged(): void
    {
        $html = $this->completedOrdersHtml();

        $this->assertStringContainsString('Order History', $html);
        $this->assertStringContainsString('Apply Filters', $html);
        $this->assertStringContainsString('Print Selected', $html);
        $this->assertStringContainsString('Print Filtered', $html);
        // The on-screen table still renders a badge cell.
        $this->assertMatchesRegularExpression('/<span class="co-badge co-badge-(ok|bad)">/', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // The SUMMARY report's print output is untouched
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_summary_print_report_styling_is_unchanged(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.summary'))
            ->assertOk()
            ->getContent();

        // The exact rules the SummaryPrintReportTest defends still stand.
        $this->assertStringContainsString('.print-only { display: none !important; }', $html);
        $this->assertStringContainsString('.print-table thead { display: table-header-group; }', $html);
        $this->assertMatchesRegularExpression('/@bottom-right\s*\{[^}]*counter\(page\)/s', $html);
        $this->assertMatchesRegularExpression(
            '/\.print-table tr\s*\{[^}]*page-break-inside:\s*avoid/s',
            $html
        );

        $hidePos = strpos($html, '.print-only { display: none !important; }');
        $showPos = strpos($html, '.print-only { display: block !important; }');
        $this->assertIsInt($hidePos);
        $this->assertIsInt($showPos);
        $this->assertLessThan($showPos, $hidePos);
    }
}
