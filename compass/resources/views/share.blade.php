@extends('layouts.share')

@section('content')

<div id="map"></div>

<input type="hidden" id="share_token" value="{{ $share_token }}">

@endsection
