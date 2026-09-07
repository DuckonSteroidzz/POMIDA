@extends('errors.layout')

@section('code', '429')
@section('emoji', '🛑')
@section('title', 'Too many attempts')

@section('message')
    <p>That was tried too many times in a row. Please wait a minute and try
        again — this limit is what keeps the account safe from guessing.</p>
    <p>Nothing was charged and nothing was lost. If you were ordering, your
        cart and your table are still exactly as you left them.</p>
@endsection
