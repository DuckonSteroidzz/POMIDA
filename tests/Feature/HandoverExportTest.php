<?php

namespace Tests\Feature;

use App\Console\Commands\HandoverExport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * `php artisan handover:export` — does the bundle actually contain what a
 * teammate needs, and does it fail safely when it cannot?
 *
 * These tests never shell out to a real mysqldump. The parts that CAN be
 * checked deterministically are:
 *   - the staged bundle has database.sql, .env.handover, README.md and uploads/
 *   - .env.handover keeps APP_KEY verbatim and blanks DB_PASSWORD / secrets
 *   - the handover output directory is never copied into its own bundle
 *   - a missing mysqldump produces a friendly, path-naming error, not a trace
 *   - an empty dump is rejected — the command never "succeeds" with no CREATE TABLE
 */
class HandoverExportTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'handover-test-' . uniqid();
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    private function cmd(): HandoverExport
    {
        return $this->app->make(HandoverExport::class);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    // ── .env.handover ────────────────────────────────────────────────

    public function test_env_handover_keeps_app_key_and_blanks_db_password(): void
    {
        $source = implode("\n", [
            'APP_NAME=POMIDA',
            'APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=',
            'APP_URL=http://192.168.1.10:8000',
            'DB_DATABASE=pomida_db',
            'DB_USERNAME=root',
            'DB_PASSWORD=super-secret-live-password',
        ]);

        $out = $this->cmd()->buildEnvHandover($source);

        $this->assertStringContainsString('APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=', $out);
        $this->assertStringContainsString("\nDB_PASSWORD=\n", $out . "\n");
        $this->assertStringNotContainsString('super-secret-live-password', $out);
        $this->assertStringContainsString('APP_URL=http://192.168.1.10:8000', $out);
        $this->assertStringContainsString('Rename this file to  .env', $out);
    }

    public function test_env_handover_blanks_mail_and_api_secrets_but_not_ordinary_values(): void
    {
        $source = implode("\n", [
            'APP_KEY=base64:keep-me',
            'MAIL_MAILER=smtp',
            'MAIL_USERNAME=orders@pomida.test',
            'MAIL_PASSWORD=mail-secret',
            'AWS_ACCESS_KEY_ID=AKIAEXAMPLE',
            'AWS_SECRET_ACCESS_KEY=aws-secret',
            'ADMIN_BOOTSTRAP_PASSWORD=Admin123!',
        ]);

        $out = $this->cmd()->buildEnvHandover($source);

        $this->assertMatchesRegularExpression('/^MAIL_PASSWORD=$/m', $out);
        $this->assertMatchesRegularExpression('/^MAIL_USERNAME=$/m', $out);
        $this->assertMatchesRegularExpression('/^AWS_ACCESS_KEY_ID=$/m', $out);
        $this->assertMatchesRegularExpression('/^AWS_SECRET_ACCESS_KEY=$/m', $out);
        $this->assertMatchesRegularExpression('/^ADMIN_BOOTSTRAP_PASSWORD=$/m', $out);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $out);
        $this->assertStringNotContainsString('mail-secret', $out);
        $this->assertStringNotContainsString('aws-secret', $out);
        $this->assertStringNotContainsString('Admin123!', $out);
        $this->assertStringContainsString('APP_KEY=base64:keep-me', $out);
    }

    // ── staged bundle layout ────────────────────────────────────────

    public function test_staged_bundle_has_every_expected_entry(): void
    {
        // a fake dump and a fake uploads tree
        $sql = $this->tmp . '/database.sql';
        file_put_contents($sql, "-- dump\nCREATE TABLE `x` (id int);\n");

        $uploads = $this->tmp . '/src-uploads';
        mkdir($uploads . '/menu-items', 0775, true);
        file_put_contents($uploads . '/menu-items/pizza.jpg', 'JPEGDATA');

        $staging = $this->tmp . '/bundle';
        $error = $this->cmd()->stageBundle($staging, $sql, ['public/uploads' => $uploads], "APP_KEY=x\n", '# README');

        $this->assertNull($error);
        $this->assertFileExists($staging . '/database.sql');
        $this->assertFileExists($staging . '/.env.handover');
        $this->assertFileExists($staging . '/README.md');
        $this->assertFileExists($staging . '/uploads/public/uploads/menu-items/pizza.jpg');
        $this->assertStringContainsString('CREATE TABLE', file_get_contents($staging . '/database.sql'));
    }

    public function test_handover_output_directory_is_never_copied_into_its_own_bundle(): void
    {
        config(['handover.output_dir' => $this->tmp . '/out']);
        mkdir($this->tmp . '/out', 0775, true);

        // A previous bundle sitting inside the output dir, which itself lives
        // inside the tree being packaged.
        $uploads = $this->tmp . '/out';                       // packaging the dir that contains the bundle
        file_put_contents($uploads . '/pomida-handover-2000-01-01.zip', 'OLD BUNDLE');
        mkdir($uploads . '/keep', 0775, true);
        file_put_contents($uploads . '/keep/real.jpg', 'REAL');

        $sql = $this->tmp . '/database.sql';
        file_put_contents($sql, "CREATE TABLE `x` (id int);\n");

        $staging = $this->tmp . '/bundle';
        $this->cmd()->stageBundle($staging, $sql, ['public/uploads' => $uploads], "APP_KEY=x\n", '# README');

        // The exclusion is the whole output dir, so nothing under it is staged.
        $this->assertFileDoesNotExist($staging . '/uploads/public/uploads/pomida-handover-2000-01-01.zip');
        $this->assertFileDoesNotExist($staging . '/uploads/public/uploads/keep/real.jpg');
    }

    // ── README ─────────────────────────────────────────────────────

    public function test_readme_carries_the_qr_regeneration_warning_verbatim(): void
    {
        $readme = $this->cmd()->buildReadme();

        $this->assertStringContainsString(
            'The table QR codes are built from APP_URL. After changing APP_URL or'
            . "\ndeploying, regenerate every table's QR in Admin > QR & Table Codes and"
            . "\nreprint the cards — the old ones will not work.",
            $readme
        );
        $this->assertStringContainsString('composer install', $readme);
        $this->assertStringContainsString('php artisan storage:link', $readme);
    }

    // ── failure modes ──────────────────────────────────────────────

    public function test_missing_mysqldump_gives_a_friendly_error_that_names_the_path(): void
    {
        config([
            'handover.mysqldump_candidates' => [],
            'handover.mysqldump_globs' => [],
            'handover.probe_path' => false,
        ]);

        $this->assertNull($this->cmd()->locateMysqldump());

        $exit = Artisan::call('handover:export');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mysqldump was not found', $output);
        $this->assertStringContainsString('mysqldump.exe', $output);
        $this->assertStringNotContainsString('Stack trace', $output);
        $this->assertStringNotContainsString('Exception', $output);
    }

    public function test_an_empty_dump_is_rejected_and_no_bundle_is_written(): void
    {
        $out = $this->tmp . '/out';
        config([
            'handover.output_dir' => $out,
            'handover.probe_path' => true,
        ]);

        // mysqldump "runs" but produces nothing usable.
        Process::fake([
            '*' => Process::result(output: "-- MariaDB dump\n-- no tables\n"),
        ]);

        $exit = Artisan::call('handover:export');
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('no usable output', $output);
        $this->assertFalse(
            is_file($out . DIRECTORY_SEPARATOR . 'pomida-handover-' . now()->format('Y-m-d') . '.zip'),
            'a bundle must not be written when the dump is empty'
        );
    }
}
