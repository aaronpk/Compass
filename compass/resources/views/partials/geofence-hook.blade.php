<?php /* The fields of one web hook. $hook is null for the "add" form. */ ?>
<div class="field">
  <label for="{{ $prefix }}-url">URL</label>
  <input type="url" id="{{ $prefix }}-url" name="url" maxlength="1024" required placeholder="https://example.com/hook" value="{{ $hook ? $hook->url : old('url') }}">
</div>
<div class="fields hook-options">
  @if($fence->kind != 'place')
    <div class="field checks">
      <label><input type="checkbox" name="on_enter" value="1" {{ $hook && $hook->on_enter ? 'checked' : '' }}> When I arrive</label>
      <label><input type="checkbox" name="on_exit" value="1" {{ $hook && $hook->on_exit ? 'checked' : '' }}> When I leave</label>
      <label><input type="checkbox" name="on_cross" value="1" {{ $hook && $hook->on_cross ? 'checked' : '' }}> When I pass through without stopping</label>
    </div>
  @endif
  <div class="field">
    <label for="{{ $prefix }}-age">If the location arrives late, send it</label>
    <select id="{{ $prefix }}-age" name="max_age">
      @foreach($max_ages as $seconds => $label)
        <option value="{{ $seconds }}" {{ (string)($hook ? $hook->max_age : 900) === (string)$seconds ? 'selected' : '' }}>{{ $label }}</option>
      @endforeach
    </select>
  </div>
</div>
