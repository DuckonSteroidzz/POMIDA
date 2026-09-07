<?php

/*
|--------------------------------------------------------------------------
| Handover bundle
|--------------------------------------------------------------------------
|
| Settings for `php artisan handover:export` — the one command that packages
| the database, the uploaded images and import instructions into a single
| zip so the project can be handed to a teammate (and, later, taken to a
| cloud host) without forgetting a piece.
|
*/

return [

    // Where the finished bundle is written. Excluded from its own archive so
    // re-running never nests the previous bundle inside the new one, and
    // gitignored so a bundle full of real customer data never reaches the repo.
    'output_dir' => storage_path('handover'),

    // The directories that actually hold uploaded files on this project. The
    // key is the path the files must be copied back to on the new machine; the
    // value is where they live here. Verified against where the code writes:
    //   - menu / category / ad images .......... public/uploads/*  (AdminController)
    //   - GCash QR ............................. storage/app/public/settings/gcash (SettingsController)
    //   - PWD / Senior ID scans ................ public/uploads/ids + storage/app/(public/)discount_ids
    'upload_paths' => [
        'public/uploads'           => base_path('public/uploads'),
        'storage/app/public'       => storage_path('app/public'),
        'storage/app/discount_ids' => storage_path('app/discount_ids'),
    ],

    // mysqldump is not on PATH in every environment (XAMPP and Laragon ship it
    // but do not add it to PATH). Checked in order; the first that exists wins.
    // MYSQLDUMP_PATH in .env overrides everything.
    'mysqldump_candidates' => array_values(array_filter([
        env('MYSQLDUMP_PATH'),
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump',
    ])),

    // Glob patterns for installs that carry a version number in the path.
    'mysqldump_globs' => [
        'C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe',
    ],

    // The path suggested in the error message when nothing above is found.
    'mysqldump_hint' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe',

    // Let tests turn off the "is it already on PATH?" probe so the missing-
    // mysqldump path is exercised deterministically regardless of the machine.
    'probe_path' => true,

    // Row counts shown in the pre-send summary.
    'summary_tables' => ['orders', 'users', 'menu_items', 'inventory', 'branches', 'restaurant_tables'],

];
