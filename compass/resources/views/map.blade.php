@extends('layouts.map')

@section('content')

<div class="corner-logo"><a href="/"><img src="/assets/compass.svg" height="40"/></a></div>

<div id="map"></div>

<div id="database" data-name="{{ $database->name }}" data-token="{{ $database->read_token }}"></div>

@endsection
