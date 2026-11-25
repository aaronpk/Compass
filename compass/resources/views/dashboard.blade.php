@extends('layouts.master')

@section('content')

@include('partials/logged-in')

<div class="page dashboard">

  <h2>Databases</h2>
  <ul class="databases">
    @foreach($databases as $database)
    <li class="db"><a href="/map/{{ $database->name }}">{{ $database->name }}</a></li>
    @endforeach
    <li class="new">
      <a href="#" class="button create-link {{ session('create-error') ? 'hidden' : '' }}">create database</a>
      @if(session('create-error'))
        <div class="error">{{ session('create-error') }}</div>
      @endif
      <span class="create {{ session('create-error') ? '' : 'hidden' }}">
        <form action="/database/create" method="post">
          <div class="input-group">
            <input type="text" name="name" value="{{ session('database-name') }}" aria-label="Database name">
            <button type="submit" class="button primary">Create</button>
          </div>
        </form>
      </span>
    </li>
  </ul>

</div>
<script>
document.querySelector('.create-link').addEventListener('click', function(evt){
  evt.preventDefault();
  document.querySelector('.databases .create').classList.remove('hidden');
  this.classList.add('hidden');
  document.querySelector('.databases .create input').focus();
});
</script>
@endsection
