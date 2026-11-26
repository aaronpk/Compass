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

if(!function_exists('database_menu')) {
  /**
   * The top menu for the pages of one database: the same links on every
   * page, so the menu doesn't change as you move between them. Settings is
   * only there for the person that created the database, who is the only one
   * that can open it.
   */
  function database_menu(object $database): array {
    $name = $database->name;
    $menu = [
      '/map/'.$name => 'Map',
      '/stats/'.$name => 'Stats',
      '/stats/'.$name.'/world' => 'World',
      '/places/'.$name => 'Places',
      '/shares/'.$name => 'Share',
    ];
    if($database->created_by == session('user_id'))
      $menu['/settings/'.$name] = 'Settings';
    return $menu;
  }
}

