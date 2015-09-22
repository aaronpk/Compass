@extends('layouts.master')

@section('content')

<div class="splash">

  <div class="logo"><img src="/assets/compass.svg" width="200"></div>

  <form action="/auth/start" method="post" class="pure-form login">
    <fieldset>
      <input type="url" name="me">
      <button type="submit" class="pure-button pure-button-primary">Sign in</button>
    </fieldset>
  </form>

</div>

@endsection