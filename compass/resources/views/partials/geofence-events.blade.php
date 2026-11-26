<?php /* The history table. $names is null on the page of a single place. */ ?>
<table class="table place-events">
  <thead><tr><th>When</th>@if($names)<th>Place</th>@endif<th>Event</th><th>Web hooks</th></tr></thead>
  <tbody>
    @foreach($events as $event)
      <tr>
        <td>{{ $text->when($event->event_at) }}@if($event->delayed) <span class="muted small">(arrived late)</span>@endif</td>
        @if($names)<td><a href="/places/{{ $database->name }}/{{ $event->geofence_id }}">{{ $names[$event->geofence_id] ?? '' }}</a></td>@endif
        <td>{{ $text->event($event) }}</td>
        <td>
          @foreach($text->deliveries($event->deliveries) as [$outcome, $failed])
            <span class="{{ $failed ? 'bad' : 'muted' }}">{{ $outcome }}</span>@if(!$loop->last)<span class="muted">, </span>@endif
          @endforeach
        </td>
      </tr>
    @endforeach
  </tbody>
</table>
