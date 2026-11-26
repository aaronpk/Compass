<?php
/* The history table. $names is null on the page of a single place.
   The day is written once, on its first row, so the days stand apart. */
$where = false;
foreach($events as $event)
  $where = $where || $text->where($event) !== '';
$day = null;
?>
<div class="table-scroll">
<table class="table place-events">
  <thead><tr><th>Day</th><th>Time</th>@if($names)<th>Place</th>@endif<th>Event</th>@if($where)<th>Where</th>@endif<th>Time there</th><th>Web hooks</th></tr></thead>
  <tbody>
    @foreach($events as $event)
      <?php $new = $text->day($event->event_at) !== $day; $day = $text->day($event->event_at); ?>
      <tr class="{{ $new ? 'new-day' : '' }}">
        <td class="day">@if($new){{ $day }}@else<span class="visually-hidden">{{ $day }}</span>@endif</td>
        <td class="time">{{ $text->time($event->event_at) }}@if($event->delayed) <span class="muted small" title="The location reached Compass more than five minutes after it was recorded">late</span>@endif</td>
        @if($names)<td><a href="/places/{{ $database->name }}/{{ $event->geofence_id }}">{{ $names[$event->geofence_id] ?? '' }}</a></td>@endif
        <td><span class="event-kind kind-{{ $event->type }}">{{ $text->event($event) }}</span></td>
        @if($where)<td>{{ $text->where($event) }}</td>@endif
        <td class="stayed">{{ $text->stayed($event) }}</td>
        <td>
          @foreach($text->deliveries($event->deliveries) as [$outcome, $failed])
            <span class="{{ $failed ? 'bad' : 'muted' }}">{{ $outcome }}</span>@if(!$loop->last)<span class="muted">, </span>@endif
          @endforeach
        </td>
      </tr>
    @endforeach
  </tbody>
</table>
</div>
