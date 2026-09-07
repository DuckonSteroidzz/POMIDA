@extends('errors.layout')

@section('code', '503')
@section('emoji', '🛠️')
@section('title', 'We are updating the system')

@section('message')
    <p>The ordering system is briefly offline for maintenance. It is usually
        back within a few minutes — please try again shortly.</p>
    <p>If you are dining in, please ask staff to take your order at the
        counter in the meantime.</p>
@endsection
