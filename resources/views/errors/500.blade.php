@extends('errors.layout')

@section('code', '500')
@section('emoji', '🍰')
@section('title', 'Something went wrong on our end')

@section('message')
    <p>This one is our fault, not yours. The problem has been recorded and
        the team can look it up.</p>
    <p>If you were placing an order, please check your Orders page before
        trying again, so you do not order the same thing twice.</p>
@endsection

{{-- Customer-only: staff already get their dashboard link from the layout
     (see ErrorPageContext::isAdminVisitor()) and are never offered this one. --}}
@unless(\App\Support\ErrorPageContext::isAdminVisitor())
    @section('extra_actions')
        <a class="btn btn-ghost" href="{{ url('/customer/orders') }}">Check my orders</a>
    @endsection
@endunless
