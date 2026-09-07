@extends('errors.layout')

@php
    /*
     * The limit the server actually enforces. ValidatePostSize (Laravel's own
     * default middleware) rejects the request by comparing its Content-Length
     * against post_max_size BEFORE Laravel's validation ever runs — so this is
     * the one place that can tell the visitor a number, and it reads the same
     * ini setting the middleware itself checks rather than a guessed constant
     * that could drift from it.
     *
     * Self-contained on purpose, like the rest of this layout: ini_get() is a
     * PHP built-in, not a database or session read, so this still renders if
     * whatever else in the app is broken.
     */
    $postMaxSizeLabel = null;

    try {
        $shorthand = trim((string) ini_get('post_max_size'));

        if ($shorthand !== '' && preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)$/i', $shorthand, $m)) {
            $number = (float) $m[1];
            $unit = strtoupper($m[2]);

            // Trim a trailing ".0" from a whole number without ever touching
            // a significant digit — number_format always adds the decimal
            // point back, so it is only ever THAT which gets stripped.
            $formatted = rtrim(rtrim(number_format($number, 1), '0'), '.');

            $postMaxSizeLabel = $formatted . ($unit === '' ? ' bytes' : ' ' . $unit . 'B');
        }
    } catch (\Throwable $e) {
        $postMaxSizeLabel = null;
    }
@endphp

@section('code', '413')
@section('emoji', '📦')
@section('title', 'That upload is too large')

@section('message')
    <p>
        The file (or the form it was attached to) is bigger than this server accepts
        @if ($postMaxSizeLabel)
            — please keep it under {{ $postMaxSizeLabel }}
        @endif
        . Nothing was saved.
    </p>
    <p>Try a smaller photo, or resize it, and submit the form again.</p>
@endsection
