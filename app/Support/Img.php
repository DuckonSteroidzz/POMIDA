<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class Img
{
    public static function url(?string $path): string
    {
        if (!$path) {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $clean = ltrim(str_replace('\\', '/', $path), '/');
        $clean = preg_replace('#^(public/|storage/)#', '', $clean);

        if (is_file(storage_path('app/public/' . $clean))) {
            return asset('storage/' . $clean);
        }

        return asset($clean);
    }

    public static function delete(?string $path): void
    {
        if (!$path) {
            return;
        }

        $clean = ltrim(str_replace('\\', '/', $path), '/');
        $clean = preg_replace('#^(public/|storage/)#', '', $clean);

        if (Storage::disk('public')->exists($clean)) {
            Storage::disk('public')->delete($clean);
            return;
        }

        $legacy = public_path($clean);
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }
}
