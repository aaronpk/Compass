@extends('layouts.master')

@section('head')
<link rel="stylesheet" href="{{ asset_v('/assets/leaflet-1.9.4/leaflet.css') }}">
@endsection

@section('content')

@include('partials/logged-in')

<div class="page wide places">

  <p class="back"><a href="/places/{{ $database->name }}">← All places</a></p>
  <div class="stats-head">
    <h2>Suggested places <span class="muted">{{ $database->name }}</span></h2>
    <select aria-label="Look at" onchange="location.href='/places/{{ $database->name }}/suggested'+(this.value == '12' ? '' : '?span='+this.value)">
      @foreach($spans as $value => $label)
        <option value="{{ $value }}" {{ (string)$value === $span ? 'selected' : '' }}>{{ $label }}</option>
      @endforeach
    </select>
  </div>

  <p class="muted">Spots where you spent a lot of time: where you slept, where your working days went, and anywhere else you kept going back to. Nothing here is a place until you add it.</p>

  @if($message)
    <p class="alert" role="status">{{ $message }}</p>
  @endif
  @if($error)
    <p class="alert error" role="alert">{{ $error }}</p>
  @endif

  @if($no_stats)
    <div class="card">
      <p>Suggestions come from the stats, and there are none for {{ $database->name }} yet. They are built by <code>php artisan stats:backfill</code> on the server; see the README.</p>
    </div>
  @elseif(!count($suggestions))
    <div class="card">
      <p class="muted">Nothing to suggest in this stretch of time{{ count($shapes) ? ' that isn\'t already one of your places' : '' }}.</p>
    </div>
  @else

  <div class="places-layout">

    <div class="places-map">
      <div id="places-map" data-mapbox-token="{{ config('compass.mapbox_token') }}" data-mapbox-style="{{ config('compass.mapbox_style') }}"></div>
      <p class="muted small" id="places-map-hint">Choose a suggestion to see it on the map. Drag its centre or the handle on its edge to adjust it before adding.</p>
    </div>

    <div class="places-side">
      <ol class="suggestions">
        @foreach($suggestions as $spot)
          <li class="card suggestion" data-suggestion="{{ $spot['number'] }}">
            <div class="suggestion-head">
              <span class="suggestion-number">{{ $spot['number'] }}</span>
              <span class="badge kind-{{ $spot['kind'] }}">{{ $text->suggestedKind($spot) }}</span>
              @if($spot['city'])<span class="muted small">{{ $spot['city'] }}</span>@endif
            </div>
            <p class="suggestion-why">{{ $text->evidence($spot) }}<br><span class="muted small">{{ $text->between($spot) }}</span></p>
            <form method="post" action="/places/{{ $database->name }}" data-editor="circle">
              <input type="hidden" name="kind" value="circle">
              <input type="hidden" name="return" value="suggested">
              <input type="hidden" name="span" value="{{ $span }}">
              <input type="hidden" name="lat" value="{{ $spot['lat'] }}">
              <input type="hidden" name="lng" value="{{ $spot['lng'] }}">
              <div class="fields">
                <div class="field">
                  <label for="suggestion-{{ $spot['number'] }}-name">Name</label>
                  <input type="text" id="suggestion-{{ $spot['number'] }}-name" name="name" maxlength="100" value="{{ $spot['name'] }}">
                </div>
                <div class="field narrow">
                  <label for="suggestion-{{ $spot['number'] }}-radius">Radius (metres)</label>
                  <input type="number" id="suggestion-{{ $spot['number'] }}-radius" name="radius" min="50" max="100000" step="1" value="{{ $spot['radius'] }}">
                </div>
              </div>
              <div class="place-actions">
                <button type="submit" class="button primary">Add place</button>
                <button type="submit" class="button dismiss" formaction="/places/{{ $database->name }}/suggested/dismiss" formnovalidate>Not a place</button>
              </div>
            </form>
          </li>
        @endforeach
      </ol>
    </div>

  </div>
  @endif

  @if(count($dismissed))
    <div class="card">
      <h3>Not places</h3>
      <p class="muted small">Spots you said aren't places. They stay off the list until you bring them back.</p>
      <ul class="place-list dismissed-list">
        @foreach($dismissed as $item)
          <li>
            <div>
              @if($item['spot'])
                <span class="place-name">{{ $item['name'] }}</span>
                <span class="muted small">{{ $text->evidence($item['spot']) }} · {{ $text->between($item['spot']) }}</span>
              @else
                <span class="place-name">A spot with nothing in this stretch of time</span>
              @endif
            </div>
            <form method="post" action="/places/{{ $database->name }}/suggested/restore/{{ $item['id'] }}">
              <input type="hidden" name="span" value="{{ $span }}">
              <button type="submit" class="button">Bring back</button>
            </form>
          </li>
        @endforeach
      </ul>
    </div>
  @endif

</div>

@if(count($suggestions))
<script type="application/json" id="places-data">{!! json_encode(['fences' => $shapes, 'center' => $center, 'editing' => null, 'suggestions' => true], JSON_HEX_TAG|JSON_HEX_AMP) !!}</script>
@endif

@endsection

@section('scripts')
@if(count($suggestions))
<script src="{{ asset_v('/assets/leaflet-1.9.4/leaflet.js') }}"></script>
<script src="{{ asset_v('/assets/compass.js') }}"></script>
<script src="{{ asset_v('/assets/geofences.js') }}"></script>
@endif
@endsection
