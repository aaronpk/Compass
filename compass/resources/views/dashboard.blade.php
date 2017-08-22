@extends('layouts.master')

@section('content')

@include('partials/logged-in')

<div class="dashboard">

  <h2>Databases</h2>
  <ul class="databases">
    @foreach($databases as $database)
    <li class="db"><a href="/map/{{ $database->name }}">{{ $database->name }}</a></li>
    @endforeach
    <li>
      <a href="#" class="pure-button create-link {{ session('create-error') ? 'hidden' : '' }}">create database</a>
      @if(session('create-error'))
        <div class="error">{{ session('create-error') }}</div>
      @endif
      <span class="create {{ session('create-error') ? '' : 'hidden' }}">
        <form action="/database/create" method="post" class="ui form">
          <div class="ui action input">
            <input type="text" name="name" value="{{ session('database-name') }}">
            <button type="submit" class="ui button primary">Create</button>
          </div>
        </form>
      </span>
    </li>
  </ul>

</div>
<script>
$(function(){
  $(".create-link").click(function(){
    $('.databases .create').removeClass('hidden');
    $('.create-link').addClass('hidden');
    return false;
  });
});
</script>
@endsection
