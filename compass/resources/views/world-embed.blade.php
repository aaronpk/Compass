<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Countries visited</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="{{ asset_v('/assets/leaflet-1.9.4/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset_v('/assets/compass.css') }}">
  </head>
  <body class="world-embed">
    <div id="world-map" class="world-map"></div>
    <div class="world-summary">{{ count($world['countries']) }} {{ count($world['countries']) == 1 ? 'country' : 'countries' }} on {{ $world['continents'] }} {{ $world['continents'] == 1 ? 'continent' : 'continents' }}</div>
    <script type="application/json" id="world-data">{!! json_encode(['countries' => $world['countries'], 'outlines' => asset_v('/assets/world.geojson')], JSON_HEX_TAG|JSON_HEX_AMP) !!}</script>
    <script src="{{ asset_v('/assets/leaflet-1.9.4/leaflet.js') }}"></script>
    <script src="{{ asset_v('/assets/world.js') }}"></script>
  </body>
</html>
