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

<div id="calendar">
  <div class="scroll">
  <?php
  $days = array_fill(1,31,['#']);

  try {
    $tz = new DateTimeZone($database->timezone);
  } catch(Exception $e) {
    $tz = new DateTimeZone('UTC');
  }

  $start = new DateTime($database->created_at);
  $end = new DateTime();
  $start->setTimeZone($tz);
  $end->setTimeZone($tz);

  $i = clone $start;
  while((int)$i->format('Y') <= (int)$end->format('Y') && (int)$i->format('M') <= (int)$end->format('M')) {
    ?>
    @include('partials/calendar', [
      'year' => $i->format('Y'),
      'month' => $i->format('m'),
      'days' => $days,
      'day_name_length' => 3,
      'month_href' => null,
      'first_day' => 1,
      'pn' => []
    ])
    <?php
    $i = $i->add(new DateInterval('P1M'));
  }
  ?>
  </div>
</div>

<div id="map" data-mapbox-token="{{ config('compass.mapbox_token') }}" data-mapbox-style="{{ config('compass.mapbox_style') }}"></div>

</div>

<div id="graphs">
  <input id="is-metric" type="hidden" value="{{ $database->metric }}">
  <div id="battery-chart"><canvas id="battery-chart-canvas" role="img" aria-label="Battery level, speed and app events over time"></canvas></div>
  <button class="button hidden" id="chart-reset-zoom">Reset zoom</button>
</div>

<?php /* Today's date in the database's timezone ($tz and $end are set above), for selecting the calendar day */ ?>
<div id="database" data-name="{{ $database->name }}" data-token="{{ $database->read_token }}" data-write-token="{{ $database->write_token }}" data-today="{{ $end->format('Y-m-d') }}"></div>

@endsection
