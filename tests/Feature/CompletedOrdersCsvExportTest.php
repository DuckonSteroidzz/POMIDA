<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderDiscountBeneficiary;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin > Completed Orders (Order History): "Export CSV" (Batch 2, 2026-09-29).
 *
 * The export is the page's own list in a file:
 *  - the SAME filtered, branch-scoped query the list and "Print Filtered" use
 *    (AdminController::completedOrdersQuery()), so date range, type and status
 *    filter it exactly as they filter the screen, and a branch-locked role can
 *    never widen it — the branch comes from getSelectedBranch(), never the URL;
 *  - the same roles as the page (Owner, Supervisor, Staff);
 *  - no PWD/Senior ID numbers or names, ever;
 *  - every user-entered text cell through App\Support\Csv::cell().
 *
 * Every row is created inside DatabaseTransactions and carries the CCSV prefix.
 */
class CompletedOrdersCsvExportTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'CCSV';

    private const HEADER = [
        'Order #', 'Date/Time', 'Branch', 'Type', 'Table', 'Items',
        'Subtotal', 'Discount', 'Total', 'Payment Method', 'Payment Status', 'Status',
    ];

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function lockedUser(string $role, int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' ' . $role,
            'email'     => strtolower(self::PREFIX) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function branch(?string $name = null): Branch
    {
        return Branch::create([
            'name'      => $name ?? self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'CC' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    /** One order with one line; $line lets a test choose the item name, size and add-ons. */
    private function order(int $branchId, array $attrs = [], array $line = []): Order
    {
        $item = MenuItem::orderBy('id')->firstOrFail();

        $order = Order::create(array_merge([
            'order_number'   => self::PREFIX . '-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'      => $branchId,
            'type'           => 'pick_up',
            'table_number'   => null,
            'status'         => 'completed',
            'subtotal'       => 200,
            'discount_amount' => 0,
            'total'          => 200,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'completed_at'   => now(),
        ], $attrs));

        $orderItem = OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $line['name'] ?? (self::PREFIX . ' Latte'),
            'size_name'    => $line['size'] ?? null,
            'item_price'   => 100,
            'quantity'     => $line['qty'] ?? 2,
            'subtotal'     => 200,
        ]);

        $optionId = DB::table('menu_options')->value('id');

        foreach ($line['options'] ?? [] as $optionName) {
            $this->assertNotNull($optionId, 'fixture needs one existing menu option to hang add-on rows on');
            DB::table('order_item_options')->insert([
                'order_item_id'    => $orderItem->id,
                // The export reads the option_name SNAPSHOT, never the live option.
                'menu_option_id'   => $optionId,
                'option_name'      => $optionName,
                'additional_price' => 10,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        return $order;
    }

    private function export(User $as, array $query = [], string $selectedBranch = 'all')
    {
        return $this->actingAs($as, 'admin')
            ->withSession(['selected_branch_id' => $selectedBranch])
            ->get(route('admin.completed-orders.export', $query));
    }

    /** @return array{0: list<array>, 1: string} data rows keyed by header, and the raw body */
    private function csv($response): array
    {
        $response->assertOk();
        $raw = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw, 'UTF-8 BOM, like the other exports');

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($raw, 3));
        rewind($stream);

        $rows = [];
        $header = null;

        while (($cells = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($header === null) {
                if ($cells === self::HEADER) {
                    $header = $cells;
                }
                continue;
            }
            if ($cells === [null] || count($cells) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $cells);
        }

        $this->assertNotNull($header, 'the column header row is present and exact');

        return [$rows, $raw];
    }

    private function mine(array $rows): array
    {
        return array_values(array_filter($rows, fn ($r) => str_starts_with($r['Order #'], self::PREFIX . '-')));
    }

    // ══════════ content ══════════

    public function test_the_export_carries_every_requested_column(): void
    {
        $branch = $this->branch();
        $order = $this->order($branch->id, [
            'type' => 'dine_in', 'table_number' => '7', 'subtotal' => 250, 'discount_amount' => 50,
            'total' => 200, 'payment_method' => 'gcash', 'payment_status' => 'paid', 'discount_type' => 'senior',
        ], ['name' => self::PREFIX . ' Latte', 'size' => 'Large', 'qty' => 2, 'options' => ['Extra Shot', 'Oat Milk']]);

        [$rows, $raw] = $this->csv($this->export($this->admin()));
        $row = collect($this->mine($rows))->firstWhere('Order #', $order->order_number);

        $this->assertNotNull($row);
        $this->assertSame($order->completed_at->format('Y-m-d H:i'), $row['Date/Time']);
        $this->assertSame($branch->name, $row['Branch']);
        $this->assertSame('Dine-in', $row['Type']);
        $this->assertSame('7', $row['Table']);
        $this->assertSame('2x ' . self::PREFIX . ' Latte (Large) [+ Extra Shot, + Oat Milk]', $row['Items']);
        $this->assertSame('250.00', $row['Subtotal']);
        $this->assertSame('50.00', $row['Discount']);
        $this->assertSame('200.00', $row['Total']);
        $this->assertSame('GCash', $row['Payment Method']);
        $this->assertSame('Paid', $row['Payment Status']);
        $this->assertSame('Completed', $row['Status']);

        $this->assertStringContainsString('text/csv', $this->export($this->admin())->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="completed-orders_', $this->export($this->admin())->headers->get('Content-Disposition'));
    }

    public function test_pickup_and_cancelled_rows_read_plainly(): void
    {
        $branch = $this->branch();
        $order = $this->order($branch->id, ['type' => 'pick_up', 'status' => 'cancelled', 'payment_status' => 'pending']);

        [$rows] = $this->csv($this->export($this->admin()));
        $row = collect($rows)->firstWhere('Order #', $order->order_number);

        $this->assertSame('Pickup', $row['Type']);
        $this->assertSame('-', $row['Table']);
        $this->assertSame('Cancelled', $row['Status']);
        $this->assertSame('Pending', $row['Payment Status']);
    }

    public function test_pwd_senior_ids_and_names_are_never_exported(): void
    {
        $branch = $this->branch();
        $order = $this->order($branch->id, [
            'discount_type' => 'pwd', 'discount_amount' => 40, 'total' => 160,
            'discount_beneficiary_name' => 'Secreta Beneficiaria',
            'discount_beneficiary_card_number' => 'PWD-SECRET-0001',
        ]);
        OrderDiscountBeneficiary::create([
            'order_id' => $order->id, 'position' => 1, 'full_name' => 'Otra Secreta', 'id_number' => 'PWD-SECRET-0002',
        ]);

        [$rows, $raw] = $this->csv($this->export($this->admin()));

        $this->assertNotNull(collect($rows)->firstWhere('Order #', $order->order_number), 'the order itself is exported');
        foreach (['Secreta Beneficiaria', 'PWD-SECRET-0001', 'Otra Secreta', 'PWD-SECRET-0002'] as $private) {
            $this->assertStringNotContainsString($private, $raw);
        }
    }

    // ══════════ formula injection ══════════

    public function test_crafted_text_cells_are_neutralised(): void
    {
        $branch = $this->branch('@' . self::PREFIX . ' SUM(1+1) branch ' . uniqid());
        $order = $this->order($branch->id, ['type' => 'dine_in', 'table_number' => '+7'], [
            'name'    => '=HYPERLINK("http://evil.test","' . self::PREFIX . '")',
            'options' => ['-Sauce'],
        ]);

        [$rows, $raw] = $this->csv($this->export($this->admin()));
        $row = collect($rows)->firstWhere('Order #', $order->order_number);

        $this->assertSame("'" . $branch->name, $row['Branch']);
        $this->assertSame("'+7", $row['Table']);
        // The Items cell always leads with the quantity, so a crafted item
        // name can never lead it; it is carried through intact.
        $this->assertStringStartsWith('2x =HYPERLINK(', $row['Items']);
        $this->assertStringContainsString('[+ -Sauce]', $row['Items']);

        // The guard itself, for every trigger the task names.
        foreach (['=', '+', '-', '@', "\t", "\r"] as $trigger) {
            $this->assertSame("'" . $trigger . 'x', \App\Support\Csv::cell($trigger . 'x'));
        }

        // Every data cell of every row: none may start with a trigger
        // character. Numbers are exempt (never guarded, as in every export),
        // and so is the bare '-' "no table" placeholder: nothing follows it,
        // so it is not a formula — the Sales export writes the same.
        foreach ($rows as $r) {
            foreach ($r as $column => $value) {
                if (in_array($column, ['Subtotal', 'Discount', 'Total'], true) || $value === '-') {
                    continue;
                }
                $this->assertFalse(
                    $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true),
                    "cell {$column} starts with a formula trigger: {$value}"
                );
            }
        }
    }

    /** The header block carries two user-entered names too: the branch and the account exporting. */
    public function test_the_header_block_neutralises_a_crafted_branch_and_account_name(): void
    {
        $branch = $this->branch('=' . self::PREFIX . ' evil branch ' . uniqid());
        $user = $this->lockedUser('staff', $branch->id);
        $user->forceFill(['name' => '@SUM(1+1) ' . self::PREFIX])->save();
        $this->order($branch->id);

        [, $raw] = $this->csv($this->export($user));

        $meta = [];
        foreach (array_slice(explode("\n", substr($raw, 3)), 0, 6) as $line) {
            $cells = str_getcsv($line, ',', '"', '\\');
            if (count($cells) === 2) {
                $meta[$cells[0]] = $cells[1];
            }
        }

        $this->assertSame("'" . $branch->name, $meta['Branch'] ?? null);
        $this->assertSame("'@SUM(1+1) " . self::PREFIX, $meta['Generated by'] ?? null);
    }

    // ══════════ filters ══════════

    public function test_the_page_filters_apply_to_the_export(): void
    {
        $branch = $this->branch();
        $keep = $this->order($branch->id, ['type' => 'dine_in', 'table_number' => '3', 'status' => 'completed']);
        $wrongType = $this->order($branch->id, ['type' => 'pick_up', 'status' => 'completed']);
        $wrongStatus = $this->order($branch->id, ['type' => 'dine_in', 'table_number' => '4', 'status' => 'cancelled']);
        $tooOld = $this->order($branch->id, ['type' => 'dine_in', 'table_number' => '5', 'status' => 'completed']);

        // created_at is not fillable here — backdate it directly. The page's
        // date range filters on created_at, and so does the export.
        DB::table('orders')->where('id', $tooOld->id)->update(['created_at' => now()->subDays(40)]);

        [$rows] = $this->csv($this->export($this->admin(), [
            'date_from' => now()->subDays(3)->toDateString(),
            'date_to'   => now()->toDateString(),
            'type'      => 'dine_in',
            'status'    => 'completed',
        ]));
        $numbers = array_column($this->mine($rows), 'Order #');

        $this->assertContains($keep->order_number, $numbers);
        $this->assertNotContains($wrongType->order_number, $numbers);
        $this->assertNotContains($wrongStatus->order_number, $numbers);
        $this->assertNotContains($tooOld->order_number, $numbers);

        foreach ($rows as $r) {
            $this->assertSame('Dine-in', $r['Type']);
            $this->assertSame('Completed', $r['Status']);
        }
    }

    public function test_the_export_is_not_paginated(): void
    {
        $branch = $this->branch();
        for ($i = 0; $i < 18; $i++) {
            $this->order($branch->id);
        }

        [$rows] = $this->csv($this->export($this->admin(), ['per_page' => 15, 'page' => 2], (string) $branch->id));

        $this->assertCount(18, $this->mine($rows), 'the whole filtered set, not one page of it');
    }

    // ══════════ branch scope ══════════

    public function test_the_owner_follows_the_selected_branch_view(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $inA = $this->order($a->id);
        $inB = $this->order($b->id);

        [$rows] = $this->csv($this->export($this->admin(), [], (string) $a->id));
        $numbers = array_column($rows, 'Order #');
        $this->assertContains($inA->order_number, $numbers);
        $this->assertNotContains($inB->order_number, $numbers);
        foreach ($rows as $r) {
            $this->assertSame($a->name, $r['Branch'], 'a branch view exports that branch only');
        }

        [$rows] = $this->csv($this->export($this->admin(), [], 'all'));
        $numbers = array_column($rows, 'Order #');
        $this->assertContains($inA->order_number, $numbers, 'All Branches exports both');
        $this->assertContains($inB->order_number, $numbers);
    }

    /** @dataProvider lockedRoles */
    public function test_a_branch_locked_role_exports_only_its_own_branch(string $role): void
    {
        $mine = $this->branch();
        $other = $this->branch();
        $ownOrder = $this->order($mine->id);
        $foreign = $this->order($other->id);
        $user = $this->lockedUser($role, $mine->id);

        // Neither an 'all' session value nor a crafted branch parameter widens it.
        foreach ([
            [[], 'all'],
            [['branch_id' => $other->id, 'branch' => $other->id], (string) $other->id],
        ] as [$query, $session]) {
            [$rows, $raw] = $this->csv($this->export($user, $query, $session));
            $numbers = array_column($rows, 'Order #');

            $this->assertContains($ownOrder->order_number, $numbers);
            $this->assertNotContains($foreign->order_number, $numbers, 'another branch must never appear');
            $this->assertStringNotContainsString($other->name, $raw);
            foreach ($rows as $r) {
                $this->assertSame($mine->name, $r['Branch']);
            }
        }
    }

    public static function lockedRoles(): array
    {
        return ['staff' => ['staff'], 'supervisor' => ['supervisor']];
    }

    // ══════════ who may export ══════════

    public function test_a_guest_or_customer_cannot_export(): void
    {
        $this->get(route('admin.completed-orders.export'))->assertRedirect();

        $customer = User::where('role', 'customer')->orderBy('id')->firstOrFail();
        $response = $this->actingAs($customer, 'customer')->get(route('admin.completed-orders.export'));
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_the_page_offers_the_export_with_the_current_filters(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders', ['type' => 'dine_in', 'status' => 'completed']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Export CSV', $html);
        $this->assertStringContainsString(
            e(route('admin.completed-orders.export', ['type' => 'dine_in', 'status' => 'completed'])),
            $html
        );
    }

    public function test_the_export_route_sits_with_the_page_in_the_all_three_roles_group(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.completed-orders.export');
        $this->assertNotNull($route);
        $page = app('router')->getRoutes()->getByName('admin.completed-orders');

        $this->assertSame(
            collect($page->gatherMiddleware())->filter(fn ($m) => str_starts_with($m, 'role:'))->values()->all(),
            collect($route->gatherMiddleware())->filter(fn ($m) => str_starts_with($m, 'role:'))->values()->all(),
            'same roles as the page'
        );
    }
}
