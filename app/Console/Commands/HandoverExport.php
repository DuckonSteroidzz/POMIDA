<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * `php artisan handover:export` — package the whole project state into ONE zip.
 *
 * WHY THIS EXISTS
 * ---------------
 * The owner has to hand the database AND the uploaded images to the team
 * leader, and will have to do it again every time the data changes and again
 * before the cloud deploy. By hand that is three steps (dump the DB, zip the
 * images, copy .env and scrub its secrets) and the images get forgotten — which
 * already happened once. This makes it one step and one file.
 *
 * WHAT IT IS NOT
 * -------------
 * It is READ-ONLY. It never writes, updates, deletes or truncates a database
 * row, and it has no --reset / --wipe / --fresh flag. There is no path through
 * this command that can empty anything. A turnover command may be written
 * later; this one only exports.
 */
class HandoverExport extends Command
{
    protected $signature = 'handover:export';

    protected $description = 'Package the database, uploaded images and import instructions into one handover zip';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  POMIDA handover export');
        $this->line('  ' . str_repeat('=', 62));
        $this->newLine();

        // ── 1. Output directory must exist and be writable ────────────────
        $outputDir = (string) config('handover.output_dir');
        if (! is_dir($outputDir) && ! @mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            return $this->bail("Cannot create the output folder:\n    {$outputDir}\nCreate it by hand and make sure this user can write to it.");
        }
        if (! is_writable($outputDir)) {
            return $this->bail("The output folder is not writable:\n    {$outputDir}\nFix its permissions (or your user) and run this again.");
        }

        // ── 2. Uploads directories ───────────────────────────────────────
        $uploadPaths = (array) config('handover.upload_paths');
        $presentUploads = array_filter($uploadPaths, fn ($p) => is_dir($p));
        if ($presentUploads === []) {
            return $this->bail(
                "None of the expected uploads directories exist:\n    "
                . implode("\n    ", $uploadPaths)
                . "\nNothing to package. If uploads live somewhere else, set config/handover.php 'upload_paths'."
            );
        }

        // ── 3. mysqldump ────────────────────────────────────────────────
        $mysqldump = $this->locateMysqldump();
        if ($mysqldump === null) {
            return $this->bail($this->missingMysqldumpMessage());
        }
        $this->line('  mysqldump:  ' . $mysqldump);

        // ── 4. Summary the owner sanity-checks BEFORE anything is sent ────
        $db = (string) config('database.connections.' . config('database.default') . '.database');
        [$tableCount, $rowCounts] = $this->gatherDbSummary();
        [$imageCount, $imageBytes] = $this->measureUploads($presentUploads);

        // ── 5. Dump the database to a temp file ─────────────────────────
        $work = $outputDir . DIRECTORY_SEPARATOR . 'tmp-' . getmypid();
        @mkdir($work, 0775, true);
        $sqlPath = $work . DIRECTORY_SEPARATOR . 'database.sql';

        $dumpResult = $this->runMysqldump($mysqldump, $sqlPath);
        if ($dumpResult !== null) {
            $this->rrmdir($work);
            return $this->bail($dumpResult);
        }

        // ── 6. .env.handover + README.md ────────────────────────────────
        $envSource = is_file(base_path('.env')) ? (string) file_get_contents(base_path('.env')) : '';
        $envHandover = $this->buildEnvHandover($envSource);
        $readme = $this->buildReadme();

        // ── 7. Assemble the zip ────────────────────────────────────────
        $date = now()->format('Y-m-d');
        $zipPath = $outputDir . DIRECTORY_SEPARATOR . "pomida-handover-{$date}.zip";
        @unlink($zipPath);

        $error = $this->assembleZip($zipPath, $sqlPath, $presentUploads, $envHandover, $readme, $work . DIRECTORY_SEPARATOR . 'bundle');
        $this->rrmdir($work);
        if ($error !== null) {
            return $this->bail($error);
        }

        $zipBytes = (int) filesize($zipPath);

        // ── 8. Print the summary and the final path ─────────────────────
        $this->newLine();
        $this->line('  SUMMARY — check this before you send the file');
        $this->line('  ' . str_repeat('-', 62));
        $this->line('  Database:        ' . $db);
        $this->line('  Tables:          ' . $tableCount);
        foreach ($rowCounts as $t => $c) {
            $this->line(sprintf('    %-22s %s rows', $t, $c));
        }
        $this->line('  Image files:     ' . $imageCount . '  (' . $this->humanBytes($imageBytes) . ')');
        $this->line('  Final zip size:  ' . $this->humanBytes($zipBytes));
        if ($zipBytes > 20 * 1024 * 1024) {
            $this->line('  NOTE: over 20 MB — too big for most email. Share it on Google Drive.');
        }
        $this->newLine();
        $this->line('  ' . str_repeat('=', 62));
        $this->line('  Bundle written to:');
        $this->line('  ' . (realpath($zipPath) ?: $zipPath));
        $this->newLine();

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════
    // mysqldump
    // ══════════════════════════════════════════════════════════════════

    /** @return string|null absolute path, or null if it cannot be found anywhere */
    public function locateMysqldump(): ?string
    {
        if (config('handover.probe_path', true)) {
            try {
                if (Process::run(['mysqldump', '--version'])->successful()) {
                    return 'mysqldump';
                }
            } catch (\Throwable) {
                // fall through to the explicit candidates
            }
        }

        foreach ((array) config('handover.mysqldump_candidates') as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        foreach ((array) config('handover.mysqldump_globs') as $pattern) {
            foreach (glob($pattern) ?: [] as $hit) {
                if (is_file($hit)) {
                    return $hit;
                }
            }
        }

        return null;
    }

    public function missingMysqldumpMessage(): string
    {
        $hint = (string) config('handover.mysqldump_hint');

        return "mysqldump was not found.\n"
            . "It ships with XAMPP and Laragon but is not added to PATH. The most likely location is:\n"
            . "    {$hint}\n"
            . "Fix it in one of these ways:\n"
            . "    - add that folder to your PATH, or\n"
            . "    - set MYSQLDUMP_PATH in .env to the full path of mysqldump.exe\n"
            . "then run  php artisan handover:export  again.";
    }

    /** @return string|null null on success, otherwise a friendly error message */
    private function runMysqldump(string $mysqldump, string $sqlPath): ?string
    {
        $conn = (string) config('database.default');
        $cfg = (array) config("database.connections.{$conn}");

        $command = [
            $mysqldump,
            '--single-transaction',
            '--skip-lock-tables',
            '--default-character-set=' . ($cfg['charset'] ?? 'utf8mb4'),
            '--host=' . ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . ($cfg['port'] ?? '3306'),
            '--user=' . ($cfg['username'] ?? 'root'),
            $cfg['database'] ?? '',
        ];

        $env = [];
        if (($cfg['password'] ?? '') !== '') {
            $env['MYSQL_PWD'] = (string) $cfg['password'];
        }

        try {
            $result = Process::timeout(600)
                ->env($env)
                ->run($command);
        } catch (\Throwable $e) {
            return "Could not run mysqldump:\n    " . $e->getMessage();
        }

        if (! $result->successful()) {
            return "mysqldump failed (exit {$result->exitCode()}):\n"
                . '    ' . trim($result->errorOutput() ?: $result->output());
        }

        $sql = $result->output();
        file_put_contents($sqlPath, $sql);

        // Never hand over an empty dump that looks like a success.
        if (! is_file($sqlPath) || filesize($sqlPath) < 512 || ! str_contains($sql, 'CREATE TABLE')) {
            return "mysqldump produced no usable output (no CREATE TABLE statements).\n"
                . "The database name or credentials are probably wrong. Nothing was written.";
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    // .env.handover  (pure — unit tested)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Copy of .env with APP_KEY kept exactly (encrypted DB values are
     * unreadable without it) and every password / secret / token blanked.
     */
    public function buildEnvHandover(string $env): string
    {
        $header = implode("\n", [
            '# ─────────────────────────────────────────────────────────────────────',
            '# Rename this file to  .env  before using it.',
            '#',
            '# APP_KEY below is the ORIGINAL key and must be kept EXACTLY as-is —',
            '# encrypted values already in the database cannot be read without it.',
            '#',
            '# Fill in your own DB_DATABASE / DB_USERNAME / DB_PASSWORD, and set',
            '# APP_URL to the address this site will actually run on.',
            '# ─────────────────────────────────────────────────────────────────────',
            '',
            '',
        ]);

        $lines = preg_split('/\r\n|\r|\n/', $env);
        $out = [];

        foreach ($lines as $line) {
            if (! preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $m)) {
                $out[] = $line;
                continue;
            }

            $key = $m[1];

            if ($key === 'APP_KEY') {
                $out[] = $line;                 // preserved verbatim
                continue;
            }

            if ($this->isSecretKey($key)) {
                $out[] = $key . '=';            // blanked
                continue;
            }

            $out[] = $line;
        }

        return $header . implode("\n", $out);
    }

    private function isSecretKey(string $key): bool
    {
        if (preg_match('/(PASSWORD|SECRET|_KEY|_TOKEN|API_KEY|ACCESS_KEY)$/', $key)) {
            return true;
        }

        return in_array($key, [
            'DB_PASSWORD',
            'MAIL_PASSWORD',
            'MAIL_USERNAME',
            'AWS_ACCESS_KEY_ID',
            'ADMIN_BOOTSTRAP_PASSWORD',
            'ADMIN_BOOTSTRAP_EMAIL',
            'REDIS_PASSWORD',
        ], true);
    }

    // ══════════════════════════════════════════════════════════════════
    // README.md
    // ══════════════════════════════════════════════════════════════════

    public function buildReadme(): string
    {
        return <<<'MD'
        # POMIDA — importing this handover bundle

        This zip contains everything needed to run the project on another machine:

        | File            | What it is                                              |
        |-----------------|--------------------------------------------------------|
        | `database.sql`  | Full mysqldump of the database (`--single-transaction`) |
        | `uploads/`      | Every uploaded image, in its original folder structure  |
        | `.env.handover` | The environment file with all secrets blanked           |
        | `README.md`     | This file                                               |

        ## Steps, in order

        1. **Create the database.** In phpMyAdmin (or the MySQL CLI) create an empty
           database, e.g. `pomida_db`, using `utf8mb4` / `utf8mb4_unicode_ci`.

        2. **Import `database.sql`.**
           ```
           mysql -u root -p pomida_db < database.sql
           ```
           (or Import → choose `database.sql` in phpMyAdmin).

        3. **Rename `.env.handover` to `.env`** and fill in:
           - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — your own database credentials
           - `APP_URL` — the address this site will run on (e.g. `http://localhost:8000`)

           Leave `APP_KEY` exactly as it is. Encrypted values already in the
           database cannot be decrypted with a different key.

        4. **Install PHP dependencies.**
           ```
           composer install
           ```

        5. **Copy the images back.** From `uploads/` in this zip:
           - `uploads/public/uploads/`        → `public/uploads/`
           - `uploads/storage/app/public/`     → `storage/app/public/`
           - `uploads/storage/app/discount_ids/` → `storage/app/discount_ids/`

        6. **Link storage.**
           ```
           php artisan storage:link
           ```

        7. **Run it.**
           ```
           php artisan serve
           ```

        ---

        The table QR codes are built from APP_URL. After changing APP_URL or
        deploying, regenerate every table's QR in Admin > QR & Table Codes and
        reprint the cards — the old ones will not work.
        MD;
    }

    // ══════════════════════════════════════════════════════════════════
    // Bundle assembly  (tested)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Stage every entry into $stagingDir in its final layout, then compress
     * $stagingDir into a single .zip. Staging first means the exact same tree
     * is used whether the zip is written by PHP's ZipArchive or, when the zip
     * extension is not enabled, by Windows' Compress-Archive / the `zip` binary.
     *
     * @param array<string,string> $uploadPaths  local-prefix => absolute source dir
     * @return string|null null on success, otherwise a friendly error message
     */
    public function assembleZip(string $zipPath, string $sqlPath, array $uploadPaths, string $envHandover, string $readme, string $stagingDir): ?string
    {
        $error = $this->stageBundle($stagingDir, $sqlPath, $uploadPaths, $envHandover, $readme);
        if ($error !== null) {
            $this->rrmdir($stagingDir);
            return $error;
        }

        $error = $this->compress($stagingDir, $zipPath);
        $this->rrmdir($stagingDir);

        return $error;
    }

    /**
     * Lay every entry out inside $stagingDir exactly as it will appear in the
     * zip. Kept separate so the layout can be asserted without depending on a
     * zip writer being available.
     *
     * @param array<string,string> $uploadPaths  local-prefix => absolute source dir
     * @return string|null null on success, otherwise a friendly error message
     */
    public function stageBundle(string $stagingDir, string $sqlPath, array $uploadPaths, string $envHandover, string $readme): ?string
    {
        // storage/handover must never end up inside its own archive.
        $exclude = realpath((string) config('handover.output_dir')) ?: (string) config('handover.output_dir');

        $this->rrmdir($stagingDir);
        if (! @mkdir($stagingDir, 0775, true) && ! is_dir($stagingDir)) {
            return "Could not create the staging folder:\n    {$stagingDir}";
        }

        copy($sqlPath, $stagingDir . DIRECTORY_SEPARATOR . 'database.sql');
        file_put_contents($stagingDir . DIRECTORY_SEPARATOR . '.env.handover', $envHandover);
        file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'README.md', $readme);

        foreach ($uploadPaths as $prefix => $sourceDir) {
            $real = realpath($sourceDir);
            if ($real === false) {
                continue;
            }
            $dest = $stagingDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, trim($prefix, '/'));
            $this->copyTree($real, $dest, $exclude);
        }

        return null;
    }

    /** Recursively copy $src to $dest, skipping $excludeReal and anything under it. */
    public function copyTree(string $src, string $dest, string $excludeReal): void
    {
        $excludeReal = rtrim($excludeReal, "/\\");
        @mkdir($dest, 0775, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            $realPath = realpath($path) ?: $path;

            if ($realPath === $excludeReal || str_starts_with($realPath, $excludeReal . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = substr($path, strlen($src) + 1);
            $target = $dest . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                @mkdir($target, 0775, true);
            } else {
                @mkdir(dirname($target), 0775, true);
                copy($path, $target);
            }
        }
    }

    /**
     * Compress the contents of $stagingDir into $zipPath. Uses PHP's ZipArchive
     * when the zip extension is enabled; otherwise falls back to Windows
     * PowerShell Compress-Archive, then to a `zip` binary on PATH.
     *
     * @return string|null null on success, otherwise a friendly error message
     */
    private function compress(string $stagingDir, string $zipPath): ?string
    {
        @unlink($zipPath);

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return "Could not create the zip file:\n    {$zipPath}";
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($stagingDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                $local = str_replace('\\', '/', substr($item->getPathname(), strlen($stagingDir) + 1));
                if ($item->isDir()) {
                    $zip->addEmptyDir($local);
                } else {
                    $zip->addFile($item->getPathname(), $local);
                }
            }
            if (! $zip->close()) {
                return "Could not finish writing the zip file:\n    {$zipPath}";
            }

            return is_file($zipPath) ? null : "The zip file was not written:\n    {$zipPath}";
        }

        // No ext-zip. Windows PowerShell can do it with no extra software.
        if (stripos(PHP_OS, 'WIN') === 0) {
            $ps = sprintf(
                "Compress-Archive -Path %s -DestinationPath %s -Force",
                escapeshellarg($stagingDir . DIRECTORY_SEPARATOR . '*'),
                escapeshellarg($zipPath)
            );
            try {
                $result = Process::timeout(600)->run(['powershell', '-NoProfile', '-NonInteractive', '-Command', $ps]);
                if ($result->successful() && is_file($zipPath)) {
                    return null;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // Last resort: a `zip` binary on PATH.
        try {
            $result = Process::path($stagingDir)->timeout(600)->run(['zip', '-r', '-q', $zipPath, '.']);
            if ($result->successful() && is_file($zipPath)) {
                return null;
            }
        } catch (\Throwable) {
            // fall through
        }

        return "Could not create the zip: PHP's zip extension is not enabled and no fallback worked.\n"
            . "Enable it by removing the ';' in front of  extension=zip  in php.ini\n"
            . "    (" . (php_ini_loaded_file() ?: 'your php.ini') . ")\n"
            . "then run  php artisan config:clear  and try again.";
    }

    // ══════════════════════════════════════════════════════════════════
    // Summary helpers  (read-only)
    // ══════════════════════════════════════════════════════════════════

    /** @return array{0:int,1:array<string,int>} */
    private function gatherDbSummary(): array
    {
        $tableCount = count(DB::select('SHOW TABLES'));

        $rows = [];
        foreach ((array) config('handover.summary_tables') as $table) {
            try {
                $rows[$table] = (int) DB::table($table)->count();
            } catch (\Throwable) {
                $rows[$table] = -1;
            }
        }

        return [$tableCount, $rows];
    }

    /**
     * @param array<string,string> $uploadPaths
     * @return array{0:int,1:int}
     */
    private function measureUploads(array $uploadPaths): array
    {
        $count = 0;
        $bytes = 0;

        foreach ($uploadPaths as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $count++;
                    $bytes += $file->getSize();
                }
            }
        }

        return [$count, $bytes];
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($n, 1)) . ' ' . $units[$i];
    }

    // ══════════════════════════════════════════════════════════════════

    private function bail(string $message): int
    {
        $this->newLine();
        foreach (preg_split('/\n/', $message) as $line) {
            $this->line('  <fg=red>' . $line . '</>');
        }
        $this->newLine();

        return self::FAILURE;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
