@extends('layouts.master')

<?php
$when = function($date) use($database) {
  if(!$date) return '';
  $d = new DateTime($date, new DateTimeZone('UTC'));
  try { $d->setTimezone(new DateTimeZone($database->timezone)); } catch(Exception $e) {}
  return $d->format('M j, g:i a');
};
?>

@section('content')

@include('partials/logged-in')

<div class="page dashboard">

  <h2>Share {{ $database->name }}</h2>

  <form class="card" method="post" action="/shares/{{ $database->name }}">
    <p>Create a link that shows where you are on a map as you move. If a trip is in progress, the link shows the trip from its start; otherwise it shows your track from the moment you create it.</p>
    <div class="fields">
      <div class="field">
        <label for="share-duration">Share for</label>
        <select id="share-duration" name="duration">
          @foreach($durations as $seconds => $label)
            <option value="{{ $seconds }}">{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="share-label">Label (only you see this)</label>
        <input type="text" id="share-label" name="label" maxlength="100" placeholder="ride to the coast">
      </div>
    </div>
    <button type="submit" class="button primary">Create link</button>
    @if(!$live)
      <p class="muted small">Live updates aren't set up on this server, so the shared map refreshes every few seconds instead of instantly.</p>
    @endif
  </form>

  <div class="card">
    <h3>Links</h3>
    @if(count($shares))
      <ul class="share-links">
        @foreach($shares as $share)
          <li class="{{ $share->active ? 'active' : 'ended' }}">
            <div class="share-info">
              <strong>{{ $share->label ?: 'Untitled' }}</strong>
              <span class="muted small">
                created {{ $when($share->created_at) }} ·
                @if($share->revoked_at)
                  stopped {{ $when($share->revoked_at) }}
                @elseif(!$share->active)
                  expired {{ $when($share->expires_at) }}
                @elseif($share->expires_at)
                  until {{ $when($share->expires_at) }}
                @else
                  until you stop it
                @endif
                @if($share->last_viewed_at)
                  · last viewed {{ $when($share->last_viewed_at) }}
                @endif
              </span>
              @if($share->active)
                <input type="text" readonly value="{{ $share->url }}" aria-label="Link" onfocus="this.select()">
              @endif
            </div>
            @if($share->active)
              <form method="post" action="/shares/{{ $database->name }}/{{ $share->id }}/stop">
                <button type="submit" class="button">Stop sharing</button>
              </form>
            @endif
          </li>
        @endforeach
      </ul>
    @else
      <p class="muted">No links yet.</p>
    @endif
  </div>

</div>

@endsection
