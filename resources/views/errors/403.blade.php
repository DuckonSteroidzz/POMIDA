@extends('errors.layout')

@section('code', '403')
@section('emoji', '🔒')
@section('title', 'You do not have access to this page')

@section('message')
    <p>This area is limited to certain staff accounts. If you believe you
        should have access, ask an admin to check your account role.</p>
@endsection
