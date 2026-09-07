@extends('errors.layout')

@section('code', '405')
@section('emoji', '🚫')
@section('title', 'That did not work the way it was tried')

@section('message')
    <p>The page you reached does not accept requests the way this one was
        made — usually from an old bookmark or a link that changed. Nothing
        was submitted or changed.</p>
@endsection
