@extends('layouts.master')

<?php
$countries = $world['countries'];
$day = fn($date) => date('M j, Y', strtotime($date));
?>

@section('head')
<link rel="stylesheet" href="{{ asset_v('/assets/leaflet-1.9.4/leaflet.css') }}">
@endsection

@section('content')

@include('partials/logged-in')

<div class="page wide stats">

  <div class="stats-head">
    <h2>{{ $database->name }} <span class="muted">{{ $world['year'] ? 'places visited in '.$world['year'] : 'places visited' }}</span></h2>
    <select aria-label="Year" onchange="location.href='/stats/{{ $database->name }}/world'+(this.value ? '?year='+this.value : '')">
      <option value="">All time</option>
      @foreach($world['years'] as $y)
        <option value="{{ $y }}" {{ $y == $world['year'] ? 'selected' : '' }}>{{ $y }}</option>
      @endforeach
    </select>
  </div>

  <div class="world-bar">
    <div class="tabs" role="group" aria-label="What counts as visited">
      <button type="button" data-world-view="all" aria-pressed="true">Everywhere</button>
      <button type="button" data-world-view="stops" aria-pressed="false">Stops only</button>
    </div>
    <span class="muted small">
      <span data-world-note="all">Anywhere you spent time on the ground, including drives and train rides through.</span>
      <span data-world-note="stops" hidden>Only places where you stopped for at least five minutes.</span>
    </span>
    <span class="world-legend muted small" aria-hidden="true">Fewer days <i class="hex-1"></i><i class="hex-2"></i><i class="hex-3"></i><i class="hex-4"></i> More</span>
  </div>

  <div id="world-map" class="world-map"></div>

  <div class="stats-row">
    <div class="card grow">
      <div class="card-title">{{ count($countries) }} {{ count($countries) == 1 ? 'country' : 'countries' }} <span class="muted">on {{ $world['continents'] }} {{ $world['continents'] == 1 ? 'continent' : 'continents' }}</span></div>
      @if($countries)
        <div class="table-scroll">
        <table class="table world-list">
          <thead><tr><th>Country</th><th class="num">Days</th><th>First visit</th><th>Last visit</th></tr></thead>
          <tbody>
          @foreach($countries as $country)
            <tr data-code="{{ $country['code'] }}">
              <td>{{ $country['name'] ?? $country['code'] }}</td>
              <td class="num">{{ number_format($country['days']) }}</td>
              <td>{{ $day($country['first']) }}</td>
              <td>{{ $day($country['last']) }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
        </div>
      @else
        <p class="muted">No countries recorded yet.</p>
      @endif
      <p class="muted small">A country counts when you spent any time there on the ground, including a layover or a drive through. Flying over doesn't count. Territories are listed separately.</p>
    </div>

    @if($isOwner)
    <div class="card half">
      <div class="card-title">Put this map on your website</div>
      @if($embedURL)
        <p>Anyone with this address can see the list of countries and which hexagons of the map you have been in (down to roughly 50 km across), without how many days you spent in each. Nothing else from this database is shown.</p>
        <div class="field">
          <label for="embed-iframe">As a frame</label>
          <textarea id="embed-iframe" class="embed-code" rows="3" readonly>&lt;iframe src="{{ $embedURL }}" width="100%" height="420" style="border:0" loading="lazy" title="Countries visited"&gt;&lt;/iframe&gt;</textarea>
        </div>
        <div class="field">
          <label for="embed-script">Or as a script, which sizes itself to the page</label>
          <textarea id="embed-script" class="embed-code" rows="3" readonly>&lt;div data-compass-world="{{ $embedURL }}"&gt;&lt;/div&gt;
&lt;script async src="{{ config('compass.base_url') }}assets/world-embed.js"&gt;&lt;/script&gt;</textarea>
        </div>
        <form method="post" action="/stats/{{ $database->name }}/embed">
          <input type="hidden" name="embed" value="disable">
          <button type="submit" class="button">Stop sharing this map</button>
        </form>
      @else
        <p>Create a public address for this map to embed it in another site. It shows the countries visited, with first and last visit dates and day counts, and which hexagons of the map you have been in (down to roughly 50 km across), all in one colour.</p>
        <form method="post" action="/stats/{{ $database->name }}/embed">
          <input type="hidden" name="embed" value="enable">
          <button type="submit" class="button primary">Create a public map</button>
        </form>
      @endif
    </div>
    @endif
  </div>

  <p class="muted small attribution">The hexagons get smaller as you zoom in, down to half a degree of longitude wide: about 55 km at the equator and 35 km at 50° north. Country borders from <a href="https://www.naturalearthdata.com/">Natural Earth</a>.</p>

</div>

<script type="application/json" id="world-data">{!! json_encode(['countries' => $countries, 'hexes' => $world['hexes'], 'flat' => false, 'outlines' => asset_v('/assets/world.geojson'), 'grid' => asset_v('/assets/world-hex.json'), 'fineGrid' => asset_v('/assets/world-hex-fine.json')], JSON_HEX_TAG|JSON_HEX_AMP) !!}</script>

@endsection

@section('scripts')
<script src="{{ asset_v('/assets/leaflet-1.9.4/leaflet.js') }}"></script>
<script src="{{ asset_v('/assets/world.js') }}"></script>
@endsection
