@extends('layouts.master')

@section('content')

@include('partials/logged-in')

<div class="dashboard">

  <h2>Database: {{ $database->name }}</h2>

  <form class="ui form">
    <div class="field">
      <label>Read Token</label>
      <input readonly="" value="{{ $database->read_token }}">
    </div>

    @if ($database->created_by == session('user_id'))
    <div class="field">
      <label>Write Token <a href="#" class="show-api-endpoint">(show API endpoint)</a></label>
      <input readonly="" value="{{ $database->write_token }}">
    </div>

    <div class="api-endpoint field hidden">
      <label>API Endpoint</label>
      <input type="text" readonly value="{{ env('BASE_URL') }}api/input?token={{ $database->write_token }}">
    </div>
    @endif
  </form>

  <div style="margin-top: 20px;">
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
          <form action="/settings/{{ $database->name }}" method="post">
            <div class="ui action input">
              <input type="url" name="add_user" value="{{ session('add-user-url') }}" placeholder="github or indieauth url">
              <button type="submit" class="ui button primary">Add User</button>
            </div>
          </form>
        </span>
      </li>
    </ul>

  </div>

  <br><br>

  <h2>Realtime Micropub Export</h2>

  <p>Enter a Micropub endpoint and token below and any trips that are written to this database will be sent to the endpoint as well.</p>

  <div class="panel">
    <form action="/settings/{{ $database->name }}" method="post" class="ui form">
      <div class="field">
        <label for="micropub_endpoint">Micropub Endpoint</label>
        <input name="micropub_endpoint" type="url" placeholder="http://example.com/micropub" class="pure-input-1" value="{{ $database->micropub_endpoint }}">
      </div>

      <div class="field">
        <label for="micropub_token">Access Token</label>
        <input name="micropub_token" type="text" placeholder="" class="pure-input-1" value="{{ $database->micropub_token }}">
      </div>

      <button type="submit" class="ui button primary">Save</button>
    </form>
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
  $(".show-api-endpoint").click(function(){
    $(".api-endpoint").removeClass("hidden");
  });
});
</script>
@endsection
