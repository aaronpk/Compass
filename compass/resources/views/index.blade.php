@extends('layouts.master')

@section('content')

@if(session('me'))
  <p>{{ session('me') }}</p>
  <p><a href="/auth/logout">sign out</a></p>
@else
Log in: 

<form action="/auth/start" method="post">
  <input type="url" name="me">
</form>
@endif

@endsection