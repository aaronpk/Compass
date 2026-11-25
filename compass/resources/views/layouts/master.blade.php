<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Compass</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @yield('head')
    <link rel="stylesheet" href="{{ asset_v('/assets/compass.css') }}">
  </head>
  <body>

    @yield('content')

    @yield('scripts')
  </body>
</html>
