@extends('layouts.map')

@section('content')

@include('partials/logged-in')

<div id="daterange"><div class="in">
  <div class="ui form"><div class="fields">
    <div class="five wide field"><input type="text" id="range-from" placeholder="from" value="<?= $range_from ?>"></div>
    <div class="five wide field"><input type="text" id="range-to" placeholder="to" value="<?= $range_to ?>"></div>
    <div class="five wide field"><input type="text" id="timezone" value="<?= $range_tz ?>"></div>
    <button class="ui submit button" id="range-go">Go</button>
  </div></div>
</div></div>

<div id="calendar">
  <div class="scroll">
  <?php
  $days = array_fill(1,31,['#']);
  $start = new DateTime('2008-05-30T00:00:00-0800');
  $end = new DateTime();
  $end->setTimeZone(new DateTimeZone('America/Los_Angeles'));
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
  <div id="battery-chart" width="800" height="160"></div>
</div>

<div id="database" data-name="{{ $database->name }}" data-token="{{ $database->read_token }}"></div>

@endsection
