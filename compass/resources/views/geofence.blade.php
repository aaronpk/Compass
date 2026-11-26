@extends('layouts.master')


@section('head')
<link rel="stylesheet" href="{{ asset_v('/assets/leaflet-1.9.4/leaflet.css') }}">
@endsection

@section('content')

@include('partials/logged-in')

<div class="page wide places">

  <p class="back"><a href="/places/{{ $database->name }}">← All places</a></p>
  <h2 class="place-title">{{ $fence->name }} <span class="muted">@if($fence->kind != 'place'){{ $text->kind($fence) }} · @endif{{ $text->status($fence) }}</span></h2>

  @if($message)
    <p class="alert" role="status">{{ $message }}</p>
  @endif
  @if($error)
    <p class="alert error" role="alert">{{ $error }}</p>
  @endif

  <div class="{{ $fence->kind == 'place' ? 'places-single' : 'places-layout' }}">

    @if($fence->kind != 'place')
    <div class="places-map">
      <div id="places-map" data-mapbox-token="{{ config('compass.mapbox_token') }}" data-mapbox-style="{{ config('compass.mapbox_style') }}"></div>
      @if($fence->kind == 'circle')
        <p class="muted small" id="places-map-hint">Click the map or drag the centre to move the circle, drag the handle on its edge to resize it, then save.</p>
      @endif
    </div>
    @endif

    <div class="places-side">

      @if($fence->kind == 'place')
        <p class="muted">Compass works out the {{ $fence->place_level }} you are in from each location, using its own list of places, and notices when it changes. There is nothing to draw.</p>
      @else
      <form class="card" method="post" action="/places/{{ $database->name }}/{{ $fence->id }}" data-editor="{{ $fence->kind }}" data-fence="{{ $fence->id }}">
        <h3>The place</h3>
        @if($fence->kind == 'circle')
          <input type="hidden" name="lat" value="{{ (float)$fence->lat }}">
          <input type="hidden" name="lng" value="{{ (float)$fence->lng }}">
        @endif
        <div class="fields">
          <div class="field">
            <label for="fence-name">Name</label>
            <input type="text" id="fence-name" name="name" maxlength="100" value="{{ $fence->name }}">
          </div>
          @if($fence->kind == 'circle')
            <div class="field narrow">
              <label for="fence-radius">Radius (metres)</label>
              <input type="number" id="fence-radius" name="radius" min="50" max="100000" step="10" value="{{ (int)$fence->radius }}">
            </div>
          @endif
        </div>
        @if($fence->kind == 'polygon')
          <div class="field">
            <label for="fence-geojson">Replace the shape with new GeoJSON (leave empty to keep it)</label>
            <textarea id="fence-geojson" name="geojson" spellcheck="false"></textarea>
            <input type="file" accept=".json,.geojson,application/json,application/geo+json" aria-label="Or choose a GeoJSON file" data-geojson-file>
            <p class="muted small" data-geojson-status></p>
          </div>
        @endif
        <div class="place-actions">
          <button type="submit" class="button primary">Save</button>
          <button type="submit" class="button danger" formaction="/places/{{ $database->name }}/{{ $fence->id }}/delete" onclick="return confirm('Delete this place and its web hooks?')">Delete place</button>
        </div>
      </form>
      @endif

      <div class="card">
        <h3>Web hooks</h3>
        @if($fence->kind == 'place')
          <p class="muted small">Each web hook is sent whenever the {{ $fence->place_level }} changes, with the one you left and the one you are in now.</p>
        @else
          <p class="muted small">Each web hook is sent for the events you choose, so arriving and leaving can go to different URLs. A place works without any.</p>
        @endif
        @if(count($hooks))
          <ul class="place-list hook-list">
            @foreach($hooks as $hook)
              <li>
                <details>
                  <summary>
                    <span class="place-name">{{ $text->hook($hook, $fence) }}</span>
                    <span class="muted small hook-url">{{ $hook->url }}</span>
                  </summary>
                  <form method="post" action="/places/{{ $database->name }}/{{ $fence->id }}/hooks/{{ $hook->id }}">
                    @include('partials/geofence-hook', ['hook' => $hook, 'prefix' => 'hook-'.$hook->id])
                    <div class="place-actions">
                      <button type="submit" class="button primary">Save</button>
                      <button type="submit" class="button" formaction="/places/{{ $database->name }}/{{ $fence->id }}/hooks/{{ $hook->id }}/test" formnovalidate>Send a test</button>
                      <button type="submit" class="button danger" formaction="/places/{{ $database->name }}/{{ $fence->id }}/hooks/{{ $hook->id }}/delete" formnovalidate onclick="return confirm('Remove this web hook?')">Remove</button>
                    </div>
                  </form>
                </details>
              </li>
            @endforeach
          </ul>
        @endif
        <details class="add-hook" {{ count($hooks) && !old('url') ? '' : 'open' }}>
          <summary class="button">Add a web hook</summary>
          <form method="post" action="/places/{{ $database->name }}/{{ $fence->id }}/hooks">
            @include('partials/geofence-hook', ['hook' => null, 'prefix' => 'hook-new'])
            <button type="submit" class="button primary">Add web hook</button>
          </form>
        </details>
      </div>

    </div>
  </div>

  <div class="card">
    <h3>What happened here</h3>
    @if(count($events))
      @include('partials/geofence-events', ['names' => null])
    @else
      <p class="muted">Nothing yet.</p>
    @endif
  </div>

  <details class="card">
    <summary><h3>How the web hooks work</h3></summary>
    <p>Compass sends a POST request with a JSON body to the web hook's URL:</p>
<pre class="code">{
  "event": "enter",
  "id": 1234,
  "time": "2026-11-26T18:04:09Z",
  "delayed": false,
  "database": "{{ $database->name }}",
  "fence": { "id": {{ $fence->id }}, "name": {!! json_encode($fence->name, JSON_HEX_TAG|JSON_HEX_AMP) !!}, "kind": "{{ $fence->kind }}" },
  "location": {
    "type": "Feature",
    "geometry": { "type": "Point", "coordinates": [-122.66, 45.51] },
    "properties": { "horizontal_accuracy": 12 }
  }
}</pre>
    <ul>
      <li><code>event</code> is <code>enter</code>, <code>exit</code>, <code>cross</code> (passed through without stopping) or, for an area change, <code>change</code> with <code>from</code> and <code>to</code>, each a <code>code</code> and a <code>name</code>.</li>
      <li><code>time</code> is when it happened, which is earlier than when the request is sent. <code>delayed</code> is true when the device delivered the location more than five minutes late.</li>
      <li>Answer with any 2xx status. Anything else is tried again five times over about an hour and a half.</li>
      <li>Arriving and leaving are reported once a second location confirms the first, so expect a few seconds' wait.</li>
    </ul>
    <p>Every request is signed, so the receiver can check it came from here: the <code>Compass-Signature</code> header is <code>sha256=</code> followed by the HMAC-SHA256 of the request body, in hex, using this secret as the key. The secret is the same for every place in {{ $database->name }}.</p>
    <div class="field">
      <label for="webhook-secret">Signing secret</label>
      <input type="text" id="webhook-secret" readonly value="{{ $secret }}" onfocus="this.select()">
    </div>
  </details>

</div>

@if($fence->kind != 'place')
<script type="application/json" id="places-data">{!! json_encode(['fences' => $shapes, 'center' => $center, 'editing' => (int)$fence->id], JSON_HEX_TAG|JSON_HEX_AMP) !!}</script>

@endif

@endsection

@section('scripts')
@if($fence->kind != 'place')
<script src="{{ asset_v('/assets/leaflet-1.9.4/leaflet.js') }}"></script>
<script src="{{ asset_v('/assets/compass.js') }}"></script>
<script src="{{ asset_v('/assets/geofences.js') }}"></script>
@endif
@endsection
