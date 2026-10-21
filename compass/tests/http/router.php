<?php
// Router for `php -S ... tests/http/router.php`: serve real files from public/
// directly, and send everything else through public/index.php like nginx does.
$public = getenv('COMPASS_PUBLIC');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if($path != '/' && is_file($public.$path)) {
  return false;
}
chdir($public);
require $public.'/index.php';
