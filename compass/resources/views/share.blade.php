@extends('layouts.share')

@section('content')

<div id="map" data-mapbox-token="{{ config('compass.mapbox_token') }}" data-mapbox-style="{{ config('compass.mapbox_style') }}"></div>

<input type="hidden" id="share_token" value="{{ $share_token }}">

@endsection
