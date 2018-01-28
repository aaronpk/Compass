@extends('layouts.master')

@section('content')

<div class="splash h-x-app">

  <a class="p-name u-url" href="" style="display: none;">Compass</a>
  <div class="logo"><img src="/assets/compass.svg" width="200" class="u-logo"></div>

  <form action="/auth/start" method="post" class="ui form login" style="margin-top: 40px;">
    <div class="ui action input">
      <input type="url" name="me">
      <button type="submit" class="ui button primary">Sign in</button>
    </div>
  </form>

</div>

@endsection
