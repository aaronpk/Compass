<header class="site-header">
  <nav class="in">
    <a href="/" class="brand">
      <img class="logo" src="/assets/compass.svg" alt="">
      <span>Compass</span>
    </a>
    @if(isset($menu))
      @foreach($menu as $u=>$n)
        <a href="{{ $u }}" class="item">{{ $n }}</a>
      @endforeach
    @endif
    <span class="spacer"></span>
    <details class="menu">
      <summary>
        <span class="label">{{ $displayURL }}</span>
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
      </summary>
      <div class="menu-items">
        <a class="item" href="/auth/logout">Sign Out</a>
      </div>
    </details>
  </nav>
</header>
