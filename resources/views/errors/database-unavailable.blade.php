@extends('errors.layout')

@section('code', '500')
@section('emoji', '🔌')
@section('title', 'We cannot reach the system right now')

@section('message')
    <p>Something behind the scenes is briefly unreachable. Nothing was saved
        or charged.</p>
    <p>Please wait a moment and try again. If you were dining in, please ask
        staff to take your order at the counter in the meantime.</p>
@endsection
