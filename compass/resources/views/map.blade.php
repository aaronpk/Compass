@extends('layouts.map')

@section('content')

@include('partials/logged-in')

<div id="daterange"><div class="in">
  <div class="ui form">
    <div class="fields">
      <div class="five wide field"><input type="text" id="range-from" placeholder="from" value="<?= $range_from ?>"></div>
      <div class="five wide field"><input type="text" id="range-to" placeholder="to" value="<?= $range_to ?>"></div>
      <div class="five wide field"><input type="text" id="timezone" value="<?= $range_tz ?>"></div>
      <button class="ui submit button" id="range-go">Go</button>
    </div>
  </div>
  @if($database->micropub_endpoint)
  <div class="ui form hidden" style="margin-top: 4px;" id="trip-create-form">
    <div class="fields">
      <div style="display: flex; margin-right: 4px;">
        <select id="trip-mode">
          @foreach(['walk', 'run', 'bicycle', 'scooter', 'car', 'taxi', 'bus', 'train', 'boat', 'plane'] as $mode)
            <option value="{{ $mode }}">{{ $mode }}</option>
          @endforeach
        </select>
      </div>
      <button class="ui submit button" id="trip-create">Create Trip</button>
    </div>
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

<div id="map"></div>
<div id="graphs">
  <input id="is-metric" type="hidden" value="{{ $database->metric }}">
  <div id="battery-chart" width="800" height="160"></div>
</div>

<div id="database" data-name="{{ $database->name }}" data-token="{{ $database->read_token }}" data-write-token="{{ $database->write_token }}"></div>

@endsection
