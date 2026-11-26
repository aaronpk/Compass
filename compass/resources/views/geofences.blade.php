@extends('layouts.master')


@section('head')
<link rel="stylesheet" href="{{ asset_v('/assets/leaflet-1.9.4/leaflet.css') }}">
@endsection

@section('content')

@include('partials/logged-in')

<div class="page wide places">

  <h2>Places <span class="muted">{{ $database->name }}</span></h2>

  @if($message)
    <p class="alert" role="status">{{ $message }}</p>
  @endif
  @if($error)
    <p class="alert error" role="alert">{{ $error }}</p>
  @endif

  <div class="places-layout">

    <div class="places-map">
      <div id="places-map" data-mapbox-token="{{ config('compass.mapbox_token') }}" data-mapbox-style="{{ config('compass.mapbox_style') }}"></div>
      <p class="muted small" id="places-map-hint">Click the map to place a circle. Drag its centre to move it and the handle on its edge to resize it.</p>
    </div>

    <div class="places-side">

      <div class="card">
        <h3>Your places</h3>
        @if(count($fences))
          <ul class="place-list">
            @foreach($fences as $fence)
              <li>
                <a href="/places/{{ $database->name }}/{{ $fence->id }}" data-fence="{{ $fence->id }}">
                  <span class="place-name">{{ $fence->name }}</span>
                  <span class="muted small">
                    {{ $text->kind($fence) }} · {{ $text->status($fence) }}
                    @if($n = $hooks[$fence->id] ?? 0) · {{ $n }} web {{ $n == 1 ? 'hook' : 'hooks' }} @endif
                  </span>
                </a>
              </li>
            @endforeach
          </ul>
        @else
          <p class="muted">None yet. Add your home or your office below. Compass then keeps track of whether you are there, and you can add web hooks for arriving and leaving.</p>
        @endif
      </div>

      <div class="card">
        <div class="card-title with-tabs">
          <span>Add a place</span>
          <div class="tabs" role="tablist" aria-label="Kind of place">
            <button type="button" role="tab" id="add-tab-circle" aria-controls="add-circle" aria-selected="true">Circle</button>
            <button type="button" role="tab" id="add-tab-polygon" aria-controls="add-polygon" aria-selected="false" tabindex="-1">Shape</button>
          </div>
        </div>

        <form method="post" action="/places/{{ $database->name }}" id="add-circle" role="tabpanel" aria-labelledby="add-tab-circle" data-editor="circle">
          <input type="hidden" name="kind" value="circle">
          <input type="hidden" name="lat" value="{{ old('lat') }}">
          <input type="hidden" name="lng" value="{{ old('lng') }}">
          <div class="fields">
            <div class="field">
              <label for="new-circle-name">Name</label>
              <input type="text" id="new-circle-name" name="name" maxlength="100" placeholder="Home" value="{{ old('kind') == 'circle' ? old('name') : '' }}">
            </div>
            <div class="field narrow">
              <label for="new-circle-radius">Radius (metres)</label>
              <input type="number" id="new-circle-radius" name="radius" min="50" max="100000" step="1" value="{{ old('radius', 150) }}">
            </div>
          </div>
          <button type="submit" class="button primary">Add circle</button>
        </form>

        <form method="post" action="/places/{{ $database->name }}" id="add-polygon" role="tabpanel" aria-labelledby="add-tab-polygon" data-editor="polygon" hidden>
          <input type="hidden" name="kind" value="polygon">
          <div class="field">
            <label for="new-polygon-name">Name</label>
            <input type="text" id="new-polygon-name" name="name" maxlength="100" placeholder="Campus" value="{{ old('kind') == 'polygon' ? old('name') : '' }}">
          </div>
          <div class="field">
            <label for="new-polygon-geojson">GeoJSON (a Polygon or MultiPolygon) <a href="https://geojson.io" target="_blank" rel="noopener">draw one at geojson.io</a></label>
            <textarea id="new-polygon-geojson" name="geojson" spellcheck="false" placeholder='{"type": "Polygon", "coordinates": [[[lng, lat], …]]}'>{{ old('geojson') }}</textarea>
            <input type="file" accept=".json,.geojson,application/json,application/geo+json" aria-label="Or choose a GeoJSON file" data-geojson-file>
            <p class="muted small" data-geojson-status></p>
          </div>
          <button type="submit" class="button primary">Add shape</button>
        </form>

      </div>

      <div class="card">
        <h3>Where you are</h3>
        <p class="muted small">Compass always keeps track of these. Open one to add a web hook for when it changes.</p>
        <ul class="place-list">
          @foreach($areas as $area)
            <li>
              <a href="/places/{{ $database->name }}/{{ $area->id }}">
                <span class="place-name">{{ $area->name }}</span>
                <span class="muted small">
                  {{ $text->status($area) }}
                  @if($n = $hooks[$area->id] ?? 0) · {{ $n }} web {{ $n == 1 ? 'hook' : 'hooks' }} @endif
                </span>
              </a>
            </li>
          @endforeach
        </ul>
      </div>


    </div>
  </div>

  <div class="card">
    <h3>What happened</h3>
    @if(count($events))
      @include('partials/geofence-events', ['names' => $names])
    @else
      <p class="muted">Nothing yet. Arrivals and departures show up here.</p>
    @endif
  </div>

</div>

<script type="application/json" id="places-data">{!! json_encode(['fences' => $shapes, 'center' => $center, 'editing' => null], JSON_HEX_TAG|JSON_HEX_AMP) !!}</script>

@endsection

@section('scripts')
<script src="{{ asset_v('/assets/leaflet-1.9.4/leaflet.js') }}"></script>
<script src="{{ asset_v('/assets/compass.js') }}"></script>
<script src="{{ asset_v('/assets/geofences.js') }}"></script>
@endsection
