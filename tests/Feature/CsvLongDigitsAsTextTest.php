<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * QA Nov 5: an exported spreadsheet showed 1.11111E+46 — a 47-digit run of 1s.
 *
 * Every money and quantity column in this schema is a DECIMAL (10,2) or (12,3),
 * so a number that big cannot come from one; it can only be a free-text cell
 * (an item name, a unit, a branch name, a table label) made entirely of
 * digits. A CSV cell that is only digits is parsed by Excel as a NUMBER however
 * it is quoted, and General format prints 12+ digits in scientific notation
 * and discards everything past the 15th.
 *
 * App\Support\Csv::cell() now writes such a cell as ="digits" (text), and
 * leaves real numbers alone. Every row is created inside DatabaseTransactions
 * and carries the LDT prefix.
 */
class CsvLongDigitsAsTextTest extends TestCase
{
    use DatabaseTransactions;

    private const FORTY_SEVEN = '11111111111111111111111111111111111111111111111';

    public function test_a_forty_seven_digit_cell_is_written_as_text(): void
    {
        $this->assertSame(47, strlen(self::FORTY_SEVEN));
        $this->assertSame('="' . self::FORTY_SEVEN . '"', Csv::cell(self::FORTY_SEVEN));
    }

    public function test_every_digit_string_a_spreadsheet_would_turn_into_a_number_is_written_as_text(): void
    {
        $cases = [
            '123456789012'      => 'exactly 12 digits: General format shows 1.23457E+11',
            '1234567890123456'  => 'a 16-digit ID: Excel zeroes every digit past the 15th',
            '09171234567'       => 'a phone number: the leading zero is dropped',
            '007'               => 'a table label: the leading zeros are dropped',
            '1e46'              => 'e-notation typed as text is parsed as 1E+46',
            '1.5E10'            => 'e-notation with a fraction',
        ];

        foreach ($cases as $input => $why) {
            // PHP turns an all-digit array key into an int; the cell is text.
            $input = (string) $input;
            $this->assertSame('="' . $input . '"', Csv::cell($input), $why);
        }
    }

    public function test_text_and_short_numbers_are_left_exactly_as_they_were(): void
    {
        foreach (['ORD-20261105-ABC123', 'Table 12', '12345678901', '7', '0', '0.5', 'Coffee Beans', 'Kg'] as $same) {
            $this->assertSame($same, Csv::cell($same), "'{$same}' must be untouched");
        }

        // Non-strings are untouched: a real number stays a number.
        $this->assertSame(5343127.6, Csv::cell(5343127.6));
        $this->assertSame(12, Csv::cell(12));
        $this->assertNull(Csv::cell(null));
        $this->assertSame('', Csv::cell(''));
    }

    public function test_the_formula_guard_still_applies_to_everything_that_is_not_digits(): void
    {
        $this->assertSame("'=cmd|' /c calc'!A1", Csv::cell("=cmd|' /c calc'!A1"));
        $this->assertSame("'-Sauce", Csv::cell('-Sauce'));
        $this->assertSame("'+639171234567", Csv::cell('+639171234567'));
        $this->assertSame("'@SUM(1)", Csv::cell('@SUM(1)'));
        // A leading-zero cell that is NOT purely digits is not rewritten.
        $this->assertSame('007A', Csv::cell('007A'));
    }

    public function test_the_text_form_can_only_be_built_from_digits_and_e_notation(): void
    {
        // The wrapper starts with "=", so it must be impossible to smuggle
        // anything executable through it.
        foreach (['1234567890123"&HYPERLINK("x")', '123456789012 ', "123456789012\n", '=1234567890123'] as $hostile) {
            $out = Csv::cell($hostile);
            $this->assertNotSame('="' . $hostile . '"', $out, 'hostile text must never be wrapped as a text-number');
        }
    }

    public function test_inventory_export_keeps_a_forty_seven_digit_item_name_as_text_and_the_money_numeric(): void
    {
        $branch = $this->branch();

        Inventory::create([
            'branch_id'       => $branch->id,
            'item_name'       => self::FORTY_SEVEN,
            'item_code'       => 'LDT-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 3,
            'unit'            => '09171234567',
            'low_stock_alert' => 1,
            'unit_cost'       => 1234.5,
            'is_active'       => true,
        ]);

        $csv = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->get(route('admin.inventory.export'))
            ->assertOk()
            ->streamedContent();

        $rows = $this->parse($csv);
        $row = $this->rowStartingWith($rows, '="' . self::FORTY_SEVEN . '"');

        $this->assertNotNull($row, 'the 47-digit name must be exported as ="digits", not bare digits');
        $this->assertSame('="09171234567"', $row[2], 'the unit column keeps its leading zero as text');
        $this->assertSame('3', $row[1], 'quantity stays a plain number');
        $this->assertSame('3703.50', $row[4], 'stock value stays a plain two-decimal number');

        // No cell anywhere is the bare 47-digit string a spreadsheet would
        // turn into 1.11111E+46.
        foreach ($rows as $cells) {
            $this->assertNotContains(self::FORTY_SEVEN, $cells);
        }
    }

    public function test_every_export_header_keeps_a_digit_only_branch_name_as_text(): void
    {
        $branch = Branch::create([
            'name'      => self::FORTY_SEVEN,
            'code'      => 'LD-' . strtoupper(substr(uniqid(), -6)),
            'address'   => '123 Test Street',
            'is_active' => true,
        ]);
        $admin = $this->admin();

        $exports = [
            'inventory'        => route('admin.inventory.export'),
            'sales'            => route('admin.export.orders'),
            'completed-orders' => route('admin.completed-orders.export'),
            'analytics'        => route('admin.analytics.export', [
                'date_from' => now()->subDay()->format('Y-m-d'),
                'date_to'   => now()->format('Y-m-d'),
            ]),
        ];

        foreach ($exports as $label => $url) {
            $csv = $this->actingAs($admin, 'admin')
                ->withSession(['selected_branch_id' => $branch->id])
                ->get($url)
                ->assertOk()
                ->streamedContent();

            $branchRow = $this->rowStartingWith($this->parse($csv), 'Branch');

            $this->assertNotNull($branchRow, "{$label}: Branch header row present");
            $this->assertSame('="' . self::FORTY_SEVEN . '"', $branchRow[1], "{$label}: branch name written as text");
        }
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'      => 'LDT Branch ' . uniqid(),
            'code'      => 'LD-' . strtoupper(substr(uniqid(), -6)),
            'address'   => '123 Test Street',
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name'      => 'LDT Admin ' . uniqid(),
            'email'     => 'ldt-admin-' . uniqid() . '@example.test',
            'password'  => bcrypt('AdminReal!Pass1'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    /** @return list<list<string|null>> */
    private function parse(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $rows[] = $cells;
        }
        fclose($handle);

        return $rows;
    }

    private function rowStartingWith(array $rows, string $first): ?array
    {
        foreach ($rows as $cells) {
            // The first cell of the very first row carries the UTF-8 BOM.
            if (ltrim((string) ($cells[0] ?? ''), "\xEF\xBB\xBF") === $first) {
                return $cells;
            }
        }

        return null;
    }
}
