@extends('layouts.master')

<?php
// Distances are shown in the unit set on the database
$unit = $metric ? 'km' : 'mi';
$distance = fn($metres, $decimals = 0) => number_format($metres / ($metric ? 1000 : 1609.344), $decimals);
$day = fn($date) => $date ? date('M j, Y', strtotime($date)) : '';
$shortDay = fn($date) => $date ? date('M j', strtotime($date)) : '';
$summary = $stats['summary'];
$previous = $stats['previous'];
$year = $stats['year'];
$monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$modeNames = ['' => 'Not recorded', 'plane' => 'Plane', 'car' => 'Car', 'drive' => 'Drive', 'taxi' => 'Taxi', 'bus' => 'Bus', 'train' => 'Train',
  'tram' => 'Tram', 'metro' => 'Metro', 'boat' => 'Boat', 'bicycle' => 'Bicycle', 'scooter' => 'Scooter', 'walk' => 'Walk', 'run' => 'Run'];

// Activity heatmap: one square per day, shaded by ground distance in five steps
$ground = array_filter(array_map(fn($d) => $d['distance_m'], $stats['days']), fn($m) => $m >= 1000);
sort($ground);
$quantile = fn($q) => $ground ? $ground[min(count($ground) - 1, (int)floor($q * count($ground)))] : 0;
$steps = [$quantile(0.25), $quantile(0.5), $quantile(0.75)];
$level = function($d) use($steps) {
  if(!$d || $d['distance_m'] + $d['flight_m'] < 1000) return 0;
  if($d['distance_m'] < $steps[0]) return 1;
  if($d['distance_m'] < $steps[1]) return 2;
  if($d['distance_m'] < $steps[2]) return 3;
  return 4;
};
$first = strtotime("$year-01-01 UTC");
$offset = (int)gmdate('N', $first) - 1; // Monday is row 0
$daysInYear = (int)gmdate('z', strtotime("$year-12-31 UTC")) + 1;
$weeks = (int)ceil(($offset + $daysInYear) / 7);
$cell = 13;
$bar = fn($value, $max) => $max > 0 ? round(100 * $value / $max, 1) : 0;
$change = function($now, $before, $format) {
  if(!$before) return '';
  $diff = $now - $before;
  return ($diff >= 0 ? '+' : '−').$format(abs($diff)).' ('.($diff >= 0 ? '+' : '−').round(100 * abs($diff) / $before).'%)';
};
?>

@section('content')

@include('partials/logged-in')

<div class="page wide stats">

  <div class="stats-head">
    <h2>{{ $database->name }} <span class="muted">in {{ $year }}</span></h2>
    <form method="get" action="/stats/{{ $database->name }}" class="year-picker">
      <label for="year" class="visually-hidden">Year</label>
      <select id="year" onchange="location.href='/stats/{{ $database->name }}/'+this.value">
        @foreach($stats['years'] as $y)
          <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
        @endforeach
        @if(!in_array($year, $stats['years']))
          <option value="{{ $year }}" selected>{{ $year }}</option>
        @endif
      </select>
    </form>
  </div>

  @if($stats['progress']['files'] == 0)
    <div class="alert">No stats have been computed for this database yet. Run <code>php artisan stats:backfill {{ $database->name }}</code> on the server.</div>
  @elseif($stats['progress']['processed'] < $stats['progress']['files'])
    <div class="alert">Still processing: {{ number_format($stats['progress']['processed']) }} of {{ number_format($stats['progress']['files']) }} days are done, so these numbers are incomplete.</div>
  @endif

  <div class="grid">
    <div class="stat-tile">
      <div class="stat-label">Total distance</div>
      <div class="stat-value">{{ $distance($summary['distance_m']) }}<span class="stat-unit">{{ $unit }}</span></div>
      <div class="stat-note">{{ $distance($summary['ground_m']) }} {{ $unit }} on the ground, {{ $distance($summary['flight_m']) }} {{ $unit }} flown</div>
    </div>
    <div class="stat-tile">
      <div class="stat-label">Countries</div>
      <div class="stat-value">{{ $summary['countries'] }}</div>
      <div class="stat-note"><a href="/stats/{{ $database->name }}/world?year={{ $year }}">See the map</a></div>
    </div>
    <div class="stat-tile">
      <div class="stat-label">Cities</div>
      <div class="stat-value">{{ $summary['cities'] }}</div>
      <div class="stat-note">with at least 20 minutes stopped</div>
    </div>
    <div class="stat-tile">
      <div class="stat-label">Active days</div>
      <div class="stat-value">{{ $summary['active_days'] }}</div>
      <div class="stat-note">days with at least {{ $metric ? '1 km' : '0.6 mi' }} of movement</div>
    </div>
    <div class="stat-tile">
      <div class="stat-label">Days away from home</div>
      <div class="stat-value">{{ $summary['days_away'] }}</div>
      <div class="stat-note">
        @if($summary['home'])
          no time within 50 km of {{ $summary['home']['name'] }}
        @else
          no home city yet
        @endif
      </div>
    </div>
  </div>

  <div class="stats-row">
    <div class="card grow">
      <div class="card-title">Activity <span class="muted">{{ $summary['active_days'] }} active days</span></div>
      <div class="heatmap-scroll">
        <svg class="heatmap" viewBox="0 0 {{ 34 + $weeks * $cell }} {{ 18 + 7 * $cell }}" role="img" aria-label="Activity by day in {{ $year }}">
          @foreach([0 => 'Mon', 2 => 'Wed', 4 => 'Fri'] as $row => $label)
            <text x="0" y="{{ 18 + $row * $cell + 10 }}" class="heatmap-label">{{ $label }}</text>
          @endforeach
          @for($m = 1; $m <= 12; $m++)
            <?php $dayOfYear = (int)gmdate('z', strtotime(sprintf('%d-%02d-01 UTC', $year, $m))); ?>
            <text x="{{ 34 + intdiv($offset + $dayOfYear, 7) * $cell }}" y="10" class="heatmap-label">{{ substr($monthNames[$m - 1], 0, 3) }}</text>
          @endfor
          @for($i = 0; $i < $daysInYear; $i++)
            <?php
              $date = gmdate('Y-m-d', $first + $i * 86400);
              $d = $stats['days'][$date] ?? null;
              $x = 34 + intdiv($offset + $i, 7) * $cell;
              $y = 18 + (($offset + $i) % 7) * $cell;
              $title = $shortDay($date).': '.($d ? $distance($d['distance_m'], 1).' '.$unit.($d['flight_m'] ? ' + '.$distance($d['flight_m']).' '.$unit.' flown' : '') : 'no data');
            ?>
            <a href="/map/{{ $database->name }}?from={{ $date }}%2000:00:00&amp;to={{ $date }}%2023:59:59&amp;tz={{ urlencode($database->timezone) }}"><rect x="{{ $x }}" y="{{ $y }}" width="{{ $cell - 3 }}" height="{{ $cell - 3 }}" rx="2" class="heat-{{ $level($d) }}"><title>{{ $title }}</title></rect></a>
          @endfor
        </svg>
      </div>
      <div class="heatmap-legend muted">Less
        @for($l = 0; $l <= 4; $l++)<svg width="10" height="10"><rect width="10" height="10" rx="2" class="heat-{{ $l }}"/></svg>@endfor
        More
      </div>
    </div>

    <div class="card streaks">
      <div class="card-title">Streaks</div>
      <div class="streak-number">{{ $stats['streaks']['current']['days'] }}</div>
      <div class="muted">current days in a row</div>
      <hr>
      <div class="streak-longest">{{ $stats['streaks']['longest']['days'] }} days</div>
      <div class="muted">longest ever
        @if($stats['streaks']['longest']['from'])
          <br>{{ $day($stats['streaks']['longest']['from']) }} – {{ $day($stats['streaks']['longest']['to']) }}
        @endif
      </div>
    </div>
  </div>

  <div class="stats-row">
    <div class="card half">
      <div class="card-title">{{ $previous['year'] }} vs {{ $year }}</div>
      @foreach([
        ['Distance', $previous['distance_m'], $summary['distance_m'], fn($v) => $distance($v).' '.$unit],
        ['Countries', $previous['countries'], $summary['countries'], fn($v) => $v],
        ['Cities', $previous['cities'], $summary['cities'], fn($v) => $v],
        ['Active days', $previous['active_days'], $summary['active_days'], fn($v) => $v.' days'],
      ] as [$label, $before, $now, $format])
        <?php $max = max($before, $now); ?>
        <div class="compare">
          <div class="compare-head"><span>{{ $label }}</span><span class="muted">{{ $change($now, $before, $format) }}</span></div>
          <div class="compare-row"><span class="compare-year">{{ $previous['year'] }}</span><span class="track"><span class="fill previous" style="width: {{ $bar($before, $max) }}%"></span></span><span class="compare-value">{{ $format($before) }}</span></div>
          <div class="compare-row"><span class="compare-year">{{ $year }}</span><span class="track"><span class="fill" style="width: {{ $bar($now, $max) }}%"></span></span><span class="compare-value">{{ $format($now) }}</span></div>
        </div>
      @endforeach
    </div>

    <div class="card half" id="digest">
      <div class="card-title">
        <span id="digest-title">Monthly digest</span>
        <span class="digest-nav">
          <button type="button" class="button small" id="digest-prev" aria-label="Previous month">‹</button>
          <select id="digest-month" aria-label="Month">
            @foreach($monthNames as $i => $name)
              <option value="{{ $i }}">{{ $name }}</option>
            @endforeach
          </select>
          <button type="button" class="button small" id="digest-next" aria-label="Next month">›</button>
        </span>
      </div>
      <div class="digest-numbers">
        <div><strong id="digest-distance"></strong><span class="muted">Total distance</span></div>
        <div><strong id="digest-active"></strong><span class="muted">Active days</span></div>
        <div><strong id="digest-countries"></strong><span class="muted">Countries</span></div>
        <div><strong id="digest-cities"></strong><span class="muted">Cities</span></div>
      </div>
      <div class="muted small-title">Ground distance by weekday</div>
      <div class="chart-box"><canvas id="digest-weekdays"></canvas></div>
      <div class="muted small-title">Top locations</div>
      <ol id="digest-top" class="plain-list"></ol>
    </div>
  </div>

  <div class="stats-row">
    <div class="card half">
      <div class="card-title">How you travelled</div>
      @if($stats['modes'])
        <table class="table">
          <thead><tr><th>Mode</th><th class="num">Distance</th><th class="num">Time</th><th class="num">Trips</th></tr></thead>
          <tbody>
          @foreach($stats['modes'] as $mode)
            <tr>
              <td>{{ $modeNames[$mode['mode']] ?? ucfirst($mode['mode']) }}</td>
              <td class="num">{{ $distance($mode['distance_m'], $mode['distance_m'] < 100000 ? 1 : 0) }} {{ $unit }}</td>
              <td class="num">{{ number_format($mode['duration_s'] / 3600, $mode['duration_s'] < 36000 ? 1 : 0) }} h</td>
              <td class="num">{{ number_format($mode['moves']) }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
        <p class="muted small">Modes come from trips recorded in Overland. Flights are also detected automatically. Other movement is listed as "Not recorded".</p>
      @else
        <p class="muted">No movement recorded.</p>
      @endif
    </div>

    <div class="card half">
      <div class="card-title">Top locations</div>
      @if($stats['top_cities'])
        <ol class="plain-list ranked">
          @foreach($stats['top_cities'] as $city)
            <li><span>{{ $city['name'] }}<span class="muted">, {{ $city['country'] }}</span></span><span class="muted">{{ $city['days'] }} {{ $city['days'] == 1 ? 'day' : 'days' }}</span></li>
          @endforeach
        </ol>
      @else
        <p class="muted">No stops recorded.</p>
      @endif
    </div>
  </div>

  <div class="stats-row">
    <div class="card half">
      <div class="card-title">Countries <span class="muted">{{ count($stats['countries']) }}</span></div>
      <ol class="plain-list">
        @foreach($stats['countries'] as $country)
          <li><span>{{ $country['name'] ?? $country['code'] }}</span><span class="muted">{{ $country['days'] }} {{ $country['days'] == 1 ? 'day' : 'days' }}</span></li>
        @endforeach
      </ol>
    </div>

    <div class="card half">
      <div class="card-title with-tabs">
        <span>First visits</span>
        <div class="tabs" role="tablist" aria-label="First visits">
          <button type="button" role="tab" id="first-tab-cities" aria-controls="first-cities" aria-selected="true">Cities <span class="count">{{ count($stats['first_visits']['cities']) }}</span></button>
          <button type="button" role="tab" id="first-tab-countries" aria-controls="first-countries" aria-selected="false" tabindex="-1">Countries <span class="count">{{ count($stats['first_visits']['countries']) }}</span></button>
        </div>
      </div>
      <div class="first-visits" role="tabpanel" id="first-cities" aria-labelledby="first-tab-cities">
        <ol class="plain-list">
          @foreach($stats['first_visits']['cities'] as $city)
            <li><span>{{ $city['name'] }}<span class="muted">, {{ $city['country'] }}</span></span><span class="muted">{{ $shortDay($city['first']) }}</span></li>
          @endforeach
        </ol>
        @if(!$stats['first_visits']['cities'])
          <p class="muted">No new cities this year.</p>
        @endif
      </div>
      <div class="first-visits" role="tabpanel" id="first-countries" aria-labelledby="first-tab-countries" hidden>
        <ol class="plain-list">
          @foreach($stats['first_visits']['countries'] as $country)
            <li><span>{{ $country['name'] ?? $country['code'] }}</span><span class="muted">{{ $shortDay($country['first']) }}</span></li>
          @endforeach
        </ol>
        @if(!$stats['first_visits']['countries'])
          <p class="muted">No new countries this year.</p>
        @endif
      </div>
    </div>
  </div>

  <p class="muted small attribution">City data from <a href="https://www.geonames.org/">GeoNames</a> (CC BY 4.0). Country borders from <a href="https://www.naturalearthdata.com/">Natural Earth</a>. Days are counted in local time where you were.</p>

</div>

<script type="application/json" id="stats-data">{!! json_encode(['months' => $stats['months'], 'metric' => $metric, 'year' => $year], JSON_HEX_TAG|JSON_HEX_AMP) !!}</script>

@endsection

@section('scripts')
<script src="{{ asset_v('/assets/chartjs-4.6.1/chart.umd.min.js') }}"></script>
<script src="{{ asset_v('/assets/stats.js') }}"></script>
@endsection
