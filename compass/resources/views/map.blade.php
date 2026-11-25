@extends('layouts.map')

@section('content')

@include('partials/logged-in')

<div class="map-stage">

<div id="daterange"><div class="in">
  <div class="fields">
    <input type="text" id="range-from" placeholder="from" aria-label="From" value="{{ $range_from }}">
    <input type="text" id="range-to" placeholder="to" aria-label="To" value="{{ $range_to }}">
    <input type="text" id="timezone" aria-label="Timezone" value="{{ $range_tz }}">
    <button class="button primary" id="range-go">Go</button>
  </div>
  @if($database->micropub_endpoint)
  <div class="fields hidden" id="trip-create-form">
    <select id="trip-mode" aria-label="Trip mode">
      @foreach(['walk', 'run', 'bicycle', 'scooter', 'car', 'taxi', 'bus', 'train', 'boat', 'plane'] as $mode)
        <option value="{{ $mode }}">{{ $mode }}</option>
      @endforeach
    </select>
    <button class="button" id="trip-create">Create Trip</button>
  </div>
  @endif
</div></div>

<?php
  // Today and the first month of the calendar, in the database's timezone
  try {
    $tz = new DateTimeZone($database->timezone);
  } catch(Exception $e) {
    $tz = new DateTimeZone('UTC');
  }
  $start = new DateTime($database->created_at);
  $end = new DateTime();
  $start->setTimeZone($tz);
  $end->setTimeZone($tz);
  $first = $start->format('Y-m');
  if($first_stats_year && $first_stats_year < (int)$start->format('Y'))
    $first = $first_stats_year.'-01';
?>
<div id="calendar" data-first="{{ $first }}">
  <div class="cal-bar">
    <button type="button" class="cal-step" id="cal-prev-day" aria-label="Previous day"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
    <button type="button" id="cal-toggle" aria-expanded="false" aria-controls="cal-panel">
      <span id="cal-label">Choose a day</span>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <button type="button" class="cal-step" id="cal-next-day" aria-label="Next day"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>
  </div>
  <div id="cal-panel" hidden>
    <div class="cal-nav">
      <button type="button" class="cal-step" id="cal-prev-month" aria-label="Previous month"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
      <select id="cal-month" aria-label="Month"></select>
      <select id="cal-year" aria-label="Year"></select>
      <button type="button" class="cal-step" id="cal-next-month" aria-label="Next month"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>
    </div>
    <table class="calendar" id="cal-grid"></table>
    <div class="cal-foot">
      <span class="cal-legend" aria-hidden="true">Less <i class="lv1"></i><i class="lv2"></i><i class="lv3"></i><i class="lv4"></i> More <span class="flight">Flight</span></span>
      <button type="button" class="button small" id="cal-today">Today</button>
    </div>
  </div>
</div>

<div id="map" data-mapbox-token="{{ config('compass.mapbox_token') }}" data-mapbox-style="{{ config('compass.mapbox_style') }}"></div>

</div>

<div id="graphs">
  <input id="is-metric" type="hidden" value="{{ $database->metric }}">
  <div id="battery-chart"><canvas id="battery-chart-canvas" role="img" aria-label="Battery level, speed and app events over time"></canvas></div>
  <button class="button hidden" id="chart-reset-zoom">Reset zoom</button>
</div>

<?php /* Today's date in the database's timezone ($end is set above), for selecting the calendar day */ ?>
<div id="database" data-name="{{ $database->name }}" data-token="{{ $database->read_token }}" data-write-token="{{ $database->write_token }}" data-today="{{ $end->format('Y-m-d') }}"></div>

@endsection
