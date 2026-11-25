<?php

if(!function_exists('asset_v')) {
  /**
   * URL of a file under public/ with its modification time appended, so that
   * browsers fetch the new file after a deploy instead of using a cached copy.
   *
   *   asset_v('/assets/compass.css') => /assets/compass.css?v=1764050000
   */
  function asset_v(string $path): string {
    $file = public_path(ltrim($path, '/'));
    $mtime = is_file($file) ? filemtime($file) : false;
    return $mtime ? $path.'?v='.$mtime : $path;
  }
}
