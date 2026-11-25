@extends('layouts.share')

@section('content')

<div id="map" data-mapbox-token="{{ config('compass.mapbox_token') }}" data-mapbox-style="{{ config('compass.mapbox_style') }}"></div>

<div id="share-status" class="share-status" data-live="{{ $live ? '/share/live/'.$share_token : '' }}" data-metric="{{ $database->metric ? 1 : 0 }}">
  <span id="share-updated">Waiting for a location…</span>
  <span id="share-speed"></span>
  <button type="button" id="share-follow" class="button small hidden">Follow</button>
</div>

<input type="hidden" id="share_token" value="{{ $share_token }}">

@endsection
