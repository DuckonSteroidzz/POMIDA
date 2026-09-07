@extends('errors.layout')

@section('code', '419')
@section('emoji', '⏳')
@section('title', 'Your session timed out')

@section('message')
    <p>For your security, the page sat idle too long and the form could not be
        submitted. Nothing was saved and nothing was charged.</p>
    <p>Reload the page and try again. If you were signed in, you may need to
        sign in once more.</p>
@endsection

{{-- Reloading is the actual fix for an expired CSRF token, so it stays first;
     the layout then adds the way back to whatever the visitor was doing. --}}
@section('extra_actions')
    <button class="btn btn-primary" type="button" onclick="window.location.reload()">Reload the page</button>
@endsection
