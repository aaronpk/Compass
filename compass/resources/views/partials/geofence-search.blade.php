<?php /* The search box above the map on the Places pages. Hidden when no search server is set. */ ?>
@if(config('compass.place_search_url'))
<form class="place-search" id="place-search" role="search" data-url="{{ config('compass.place_search_url') }}">
  <div class="input-group">
    <input type="search" name="q" aria-label="Search for a place or an address" placeholder="Search for a place or an address" autocomplete="off" enterkeyhint="search">
    <button type="submit" class="button">Search</button>
  </div>
  <p class="muted small" id="place-search-status" role="status" hidden></p>
  <ul class="place-results" id="place-results" hidden></ul>
</form>
@endif
