<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Compass</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{{ asset_v('/assets/leaflet-1.9.4/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset_v('/assets/compass.css') }}">
  </head>
  <body class="map-page">

    @yield('content')

    <script src="{{ asset_v('/assets/leaflet-1.9.4/leaflet.js') }}"></script>
    <script src="{{ asset_v('/assets/chartjs-4.6.1/chart.umd.min.js') }}"></script>
    <script src="{{ asset_v('/assets/compass.js') }}"></script>
    <script src="{{ asset_v('/assets/graph.js') }}"></script>
    <script src="{{ asset_v('/assets/map.js') }}"></script>
  </body>
</html>
