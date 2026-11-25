<header class="site-header">
  <nav class="in">
    <a href="/" class="brand">
      <img class="logo" src="/assets/compass.svg" alt="">
      <span>Compass</span>
    </a>
    @if(isset($menu))
      <?php /* The year pages under /stats/name/2025 belong to Stats */ ?>
      <?php $current = preg_replace('#^(/stats/[^/]+)/\d{4}$#', '$1', '/'.request()->path()); ?>
      @foreach($menu as $u=>$n)
        <a href="{{ $u }}" class="item{{ $u == $current ? ' current' : '' }}"@if($u == $current) aria-current="page"@endif>{{ $n }}</a>
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
