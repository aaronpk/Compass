@extends('layouts.master')

@section('content')

@include('partials/logged-in')

<div class="page dashboard">

  <h2>Database: {{ $database->name }}</h2>

  <form class="card">
    <div class="field">
      <label for="read-token">Read Token</label>
      <input id="read-token" readonly="" value="{{ $database->read_token }}">
    </div>

    @if ($database->created_by == session('user_id'))
    <div class="field">
      <label for="write-token">Write Token <a href="#" class="show-api-endpoint">(show API endpoint)</a></label>
      <input id="write-token" readonly="" value="{{ $database->write_token }}">
    </div>

    <div class="api-endpoint field hidden">
      <label for="api-endpoint">API Endpoint</label>
      <input id="api-endpoint" type="text" readonly value="{{ config('compass.base_url') }}api/input?token={{ $database->write_token }}">
    </div>
    @endif
  </form>

  <div class="card">
    <h3>Users with Access</h3>

    <ul class="users">
      @foreach($users as $user)
      <li class="user">
        @if($user->id != session('user_id'))
          <a href="#" data-user="{{ $user->url }}" class="remove-user" title="Remove this user" aria-label="Remove {{ $user->url }}">&times;</a>
        @endif
        {{ $user->url }}
      </li>
      @endforeach
      <li class="new">
        <a href="#" class="button create-link {{ session('create-error') ? 'hidden' : '' }}">New User</a>
        @if(session('create-error'))
          <div class="error">{{ session('create-error') }}</div>
        @endif
        <span class="create {{ session('create-error') ? '' : 'hidden' }}">
          <form action="/settings/{{ $database->name }}" method="post">
            <div class="input-group">
              <input type="url" name="add_user" value="{{ session('add-user-url') }}" placeholder="github or indieauth url" aria-label="URL of the user to add">
              <button type="submit" class="button primary">Add User</button>
            </div>
          </form>
        </span>
      </li>
    </ul>

  </div>

  <h2>Settings</h2>

  <p>Here you can pick a default timezone and system of measurement to display this database in.</p>

  <div class="card panel">
    <form action="/settings/{{ $database->name }}" method="post">
      <div class="field">
        <label for="timezone">Timezone</label>
        <input id="timezone" name="timezone" type="text" value="{{ $database->timezone }}">
      </div>

      <div class="field">
        <label for="metric">System</label>
        <select id="metric" name="metric">
          <option value="0" {{ $database->metric ? '' : 'selected' }}>Imperial (miles)</option>
          <option value="1" {{ $database->metric ? 'selected' : '' }}>Metric (kilometer)</option>
        </select>
      </div>

      <button type="submit" class="button primary">Save</button>
    </form>
  </div>

  <h2>Micropub Trip Export</h2>

  <div class="card panel">
    @if (empty($database->micropub_token))
    <p>Authorize Compass with a Micropub endpoint and any trips that are written to this database will be sent to that endpoint as well.</p>
    <form action="/settings/{{ $database->name }}/auth/start" method="post">
      <div class="field">
        <label for="micropub_endpoint">web sign-in</label>
        <input id="micropub_endpoint" name="me" type="url" placeholder="https://example.com/" value="">
      </div>

      <button type="submit" class="button primary">Connect</button>
    </form>
    @else
      <form action="/settings/{{ $database->name }}/auth/remove" method="get">
        <div class="field">
          <p>You are currently posting to <strong>{{$database->micropub_endpoint}}</strong>. Any trips that are written to this database will be sent to that endpoint as well.</p>
        </div>

        <button type="submit" class="button primary">Disconnect</button>
      </form>
    @endif
  </div>

  <h2>Web Hooks</h2>

  <p>Enter one or more URLs to ping when events occur. This will send a POST request to each URL configured when new a location is received, or a trip is started or ended.</p>

  <p>The POST body will be JSON encoded and will contain either a top-level property <code>location</code> or <code>trip</code>. If the trip has started, then the trip object will contain a <code>current_location</code> property. If the trip has ended, the trip object will contain a <code>end_location</code> property.</p>

  <p>Enter one or more URLs separated by whitespace.</p>

  <div class="card panel">
    <form action="/settings/{{ $database->name }}" method="post">
      <div class="field">
        <label for="ping_urls">Ping URLs</label>
        <textarea id="ping_urls" name="ping_urls">{{ $database->ping_urls }}</textarea>
      </div>

      <button type="submit" class="button primary">Save</button>
    </form>
  </div>

</div>
<script>
(function(){
  var settingsURL = @json('/settings/'.$database->name);
  var databaseName = @json($database->name);

  document.querySelector('.users .create-link').addEventListener('click', function(evt){
    evt.preventDefault();
    document.querySelector('.users .create').classList.remove('hidden');
    this.classList.add('hidden');
    document.querySelector('.users .create input').focus();
  });

  document.querySelectorAll('.remove-user').forEach(function(link){
    link.addEventListener('click', function(evt){
      evt.preventDefault();
      if(!confirm('Remove ' + link.dataset.user + ' from this database?')) {
        return;
      }
      fetch(settingsURL, {
        method: 'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({
          database: databaseName,
          remove_user: link.dataset.user
        })
      }).then(function(){
        window.location.reload();
      });
    });
  });

  var showEndpoint = document.querySelector('.show-api-endpoint');
  if(showEndpoint) {
    showEndpoint.addEventListener('click', function(evt){
      evt.preventDefault();
      document.querySelector('.api-endpoint').classList.remove('hidden');
      showEndpoint.classList.add('hidden');
    });
  }
})();
</script>
@endsection
