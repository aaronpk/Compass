@extends('layouts.master')

@section('content')

@include('partials/logged-in')

<div class="dashboard">

  <div class="panel">
    <h3>Read Token</h3>
    <div class="token"><code>{{ $database->read_token }}</code></div>
  </div>

  @if ($database->created_by == session('user_id'))
  <div class="panel">
    <h3>Write Token</h3>
    <div class="token"><code>{{ $database->write_token }}</code></div>
  </div>
  @endif

  <div class="panel">
    <h3>Users with Access</h3>

    <ul class="users">
      @foreach($users as $user)
      <li class="user">
        @if($user->id != session('user_id'))
          <a href="#" data-user="{{ $user->url }}" class="remove-user hidden">&times;</a>
        @endif
        {{ $user->url }}
      </li>
      @endforeach
      <li>
        <a href="javascript:$('.users .create').removeClass('hidden');$('.create-link').addClass('hidden');" class="pure-button create-link {{ session('create-error') ? 'hidden' : '' }}">New User</a>
        @if(session('create-error'))
          <div class="error">{{ session('create-error') }}</div>
        @endif
        <span class="create {{ session('create-error') ? '' : 'hidden' }}">
          <form action="/settings/{{ $database->name }}" method="post" class="pure-form">
            <input type="url" name="add_user" value="{{ session('add-user-url') }}" placeholder="github or indieauth url">
            <button type="submit" class="pure-button pure-button-primary">Add User</button>
          </form>
        </span>
      </li>
    </ul>

  </div>

</div>
<script>
jQuery(function($){
  $(".users .user").hover(function(){
    $(this).children(".remove-user").removeClass("hidden");
  }, function(){
    $(this).children(".remove-user").addClass("hidden");
  });
  $(".remove-user").click(function(){
    $.post("/settings/{{ $database->name }}", {
      database: "{{ $database->name }}",
      remove_user: $(this).data('user')
    }, function(data){
      window.location = window.location;
    });
    return false;
  });
});
</script>
@endsection
