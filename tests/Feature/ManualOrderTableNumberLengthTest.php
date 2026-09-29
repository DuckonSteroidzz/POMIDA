<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * storeManualOrder()'s table_number limit must match the column it writes to.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * storeManualOrder() validated `table_number` as `max:50` while every column
 * the value reaches is varchar(10):
 *
 *   orders.table_number             varchar(10) NULL   <- written directly
 *   table_sessions.table_number     varchar(10) NOT NULL
 *   table_access_codes.table_number varchar(10) NOT NULL
 *   restaurant_tables.table_number  varchar(10) NOT NULL
 *
 * The three sibling migrations each call orders.table_number "the canonical
 * shape", and the two table-DEFINITION endpoints (storeRestaurantTable() and
 * its sibling) already validated `max:10`. Only the counter's order form
 * disagreed.
 *
 * MySQL runs with STRICT_TRANS_TABLES here, so an 11-to-50 character table
 * number passed validation and then died at INSERT with SQLSTATE 22001 (1406
 * "Data too long"). storeManualOrder() catches QueryException, so staff saw the
 * generic "could not be saved" banner with no field highlighted, instead of a
 * plain "may not be greater than 10 characters" message on the box they typed
 * in. The order was not created either way — this is a diagnosis bug, not a
 * data-loss one.
 *
 * WHAT IS ASSERTED
 * ----------------
 * The boundary, from both sides, against the LIVE column width read out of
 * information_schema rather than a hard-coded 10 — so that if the column is
 * ever widened deliberately, this test says so instead of silently passing.
 */
class ManualOrderTableNumberLengthTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    /** The real width of orders.table_number in the database under test. */
    private function columnWidth(): int
    {
        $row = DB::select(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'orders', 'table_number']
        );

        $this->assertNotEmpty($row, 'orders.table_number is missing');

        return (int) $row[0]->len;
    }

    /** A live, orderable item for branch 1, resolved at runtime. */
    private function line(): array
    {
        $item = MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();

        return [
            (string) $item->id => [
                'menu_item_id' => (string) $item->id,
                'quantity'     => '1',
            ],
        ];
    }

    private function keyInDineInOrder(string $table)
    {
        return $this->actingAs($this->staff(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => 1,
                'order_type'     => 'dine_in',
                'table_number'   => $table,
                'payment_method' => 'cash',
                'amount_paid'    => '5000',
                'items'          => $this->line(),
            ]);
    }

    // ══════════ the boundary ══════════

    /**
     * One character PAST the column: must be refused by VALIDATION, naming the
     * field, and must not reach the database at all.
     */
    public function test_a_table_number_longer_than_the_column_is_a_validation_error(): void
    {
        $width = $this->columnWidth();
        $tooLong = str_repeat('7', $width + 1);

        $before = Order::max('id');

        $response = $this->keyInDineInOrder($tooLong);

        $response->assertSessionHasErrors('table_number');

        $this->assertSame(
            $before,
            Order::max('id'),
            'a table number too long for the column must not create an order'
        );
    }

    /**
     * Exactly the column width: must still be ACCEPTED. This is the half that
     * stops the fix from being "tighten it until the test passes" — the limit
     * has to land exactly on the column, not below it.
     */
    public function test_a_table_number_exactly_the_column_width_is_accepted(): void
    {
        $width = $this->columnWidth();
        $exact = str_repeat('8', $width);

        $response = $this->keyInDineInOrder($exact);

        $response->assertSessionHasNoErrors();

        $order = Order::orderByDesc('id')->firstOrFail();

        $this->assertSame(
            $exact,
            $order->table_number,
            'a table number exactly the column width must be stored verbatim'
        );
    }

    /**
     * The regression proper: the rule the controller declares must equal the
     * column width. Asserted against the parsed rule itself so that widening
     * the column without revisiting the rule (or vice versa) fails here.
     */
    public function test_the_declared_max_rule_equals_the_column_width(): void
    {
        $source = file_get_contents(
            app_path('Http/Controllers/Admin/AdminController.php')
        );

        $this->assertMatchesRegularExpression(
            "/'table_number' => 'nullable\|string\|max:(\d+)'/",
            $source,
            'storeManualOrder() no longer declares a table_number max rule in the expected shape'
        );

        preg_match("/'table_number' => 'nullable\|string\|max:(\d+)'/", $source, $m);

        $this->assertSame(
            $this->columnWidth(),
            (int) $m[1],
            'the table_number max rule must equal orders.table_number width'
        );
    }
}
