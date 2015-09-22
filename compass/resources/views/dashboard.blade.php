@extends('layouts.master')

@section('content')

<div class="corner-logo"><img src="/assets/compass.svg" height="40"/></div>

<div class="logged-in">
  <span>{{ $displayURL }}</span>
  <span><a href="/auth/logout">sign out</a></span>
</div>

<div class="dashboard">

  <h2>Databases</h2>
  <ul class="databases">
    @foreach($databases as $database)
    <li class="db"><a href="/map/{{ $database->name }}">{{ $database->name }}</a></li>
    @endforeach
    <li>
      <a href="javascript:$('.databases .create').removeClass('hidden');$('.create-link').addClass('hidden');" class="pure-button create-link {{ session('error') ? 'hidden' : '' }}">create database</a>
      @if(session('error'))
        <div class="error">{{ session('error') }}</div>
      @endif
      <span class="create {{ session('error') ? '' : 'hidden' }}">
        <form action="/database/create" method="post" class="pure-form">
          <input type="text" name="name" value="{{ session('database-name') }}">
          <button type="submit" class="pure-button pure-button-primary">Create</button>
        </form>
      </span>
    </li>
  </ul>

</div>

@endsection