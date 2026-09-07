@extends('errors.layout')

@section('code', '404')
@section('emoji', '🔎')
@section('title', 'We could not find that page')

@section('message')
    <p>The page or item you were looking for is not here. It may have been
        renamed, taken off the menu, or the link may have a typo in it.</p>
@endsection

{{-- The layout adds the visitor's own way back (their order, their table's
     menu, home). This is the extra one that makes sense for a missing page —
     but only for a customer: a staff portal visitor (see
     ErrorPageContext::isAdminVisitor()) already gets their dashboard link from
     the layout and is never offered this one. --}}
@unless(\App\Support\ErrorPageContext::isAdminVisitor())
    @section('extra_actions')
        <a class="btn btn-ghost" href="{{ url('/customer/menu') }}">Browse the menu</a>
    @endsection
@endunless
