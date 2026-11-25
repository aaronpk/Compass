@extends('layouts.master')

@section('content')

<div class="splash h-x-app h-app">

  <a class="p-name u-url" href="" style="display: none;">Compass</a>
  <div class="logo"><img src="/assets/compass.svg" width="200" class="u-logo" alt="Compass"></div>

  <form action="/auth/start" method="post" class="login">
    <div class="input-group">
      <input type="url" name="me" placeholder="https://example.com/" aria-label="Your web address">
      <button type="submit" class="button primary">Sign in</button>
    </div>
  </form>

</div>

@endsection
