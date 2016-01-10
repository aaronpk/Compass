<div class="ui fixed menu">
  <div class="ui container">
    <a href="/" class="header item">
      <img class="logo" src="/assets/compass.svg">
      <span style="padding-left: 0.7em;">Compass</span>
    </a>
    @if(isset($menu))
      @foreach($menu as $u=>$n)
        <a href="{{ $u }}" class="item">{{ $n }}</a>
      @endforeach
    @endif
    <div class="ui simple dropdown item right">
      {{ $displayURL }} <i class="dropdown icon"></i>
      <div class="menu">
        <a class="item" href="/auth/logout">Sign Out</a>
      </div>
    </div>
  </div>
</div>
