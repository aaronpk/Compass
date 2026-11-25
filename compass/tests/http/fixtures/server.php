<?php
// Router script for `php -S 127.0.0.1:18280 tests/http/fixtures/server.php`.
// Stands in for everything Compass talks to over HTTP: Atlas geocoding, ping
// URLs, a Micropub endpoint, nchan's publisher, and an IndieAuth profile with
// authorization and token endpoints. Every request is appended to $FIXTURE_LOG as JSON so the
// harness can check what Compass sent.

$base = 'http://127.0.0.1:18280';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');

if($log = getenv('FIXTURE_LOG')) {
  $headers = [];
  foreach(['HTTP_AUTHORIZATION' => 'Authorization', 'CONTENT_TYPE' => 'Content-Type', 'HTTP_COMPASS_URL' => 'Compass-Url'] as $k => $name) {
    if(isset($_SERVER[$k])) $headers[$name] = $_SERVER[$k];
  }
  // PHP doesn't populate php://input for multipart requests, so log the parsed parts
  if(stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0) {
    $files = [];
    foreach($_FILES as $field => $f)
      $files[$field] = ['name' => $f['name'], 'type' => $f['type'], 'content' => file_get_contents($f['tmp_name'])];
    $body = ['post' => $_POST, 'files' => $files];
  }
  file_put_contents($log, json_encode([
    'method' => $method,
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => $body,
  ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
}

function respond($code, $body, $type='application/json', $headers=[]) {
  http_response_code($code);
  header('Content-Type: '.$type);
  foreach($headers as $h) header($h);
  echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
  exit;
}

// nchan's publisher location: Compass posts new locations for share viewers here
if(strpos($path, '/pub/') === 0)
  respond(201, ['messages' => 1]);

switch($path) {

  case '/me/':
    respond(200, "<!doctype html><html><head>\n"
      ."<link rel=\"authorization_endpoint\" href=\"$base/auth\">\n"
      ."<link rel=\"token_endpoint\" href=\"$base/token\">\n"
      ."<link rel=\"micropub\" href=\"$base/micropub\">\n"
      ."</head><body>Fixture user</body></html>\n", 'text/html');

  case '/nolinks/':
    respond(200, "<!doctype html><html><body>No endpoints here</body></html>\n", 'text/html');

  case '/auth':
    // Authorization code verification (POST from the client)
    parse_str($body, $params);
    if(($params['code'] ?? '') != 'fixture-code')
      respond(400, ['error' => 'invalid_request']);
    respond(200, 'me='.urlencode("$base/me/"), 'application/x-www-form-urlencoded');

  case '/token':
    parse_str($body, $params);
    if(($params['code'] ?? '') != 'fixture-code')
      respond(400, ['error' => 'invalid_grant']);
    respond(200, ['access_token' => 'mp-new-token', 'token_type' => 'Bearer', 'scope' => 'create', 'me' => "$base/me/"]);

  case '/micropub':
    respond(201, '', 'text/plain', ["Location: $base/posts/trip-1"]);

  case '/ping':
  case '/ping2':
    respond(200, ['result' => 'ok']);

  case '/atlas/api/geocode':
    $lat = (float)($_GET['latitude'] ?? 0);
    $lng = (float)($_GET['longitude'] ?? 0);
    if($lat > 40 && $lat < 50 && $lng > -125 && $lng < -120)
      respond(200, ['latitude' => $lat, 'longitude' => $lng, 'locality' => 'Portland', 'region' => 'Oregon',
        'country' => 'USA', 'full_name' => 'Portland, Oregon, USA', 'timezone' => 'America/Los_Angeles', 'offset' => '-08:00']);
    respond(200, ['latitude' => $lat, 'longitude' => $lng, 'timezone' => 'UTC']);
}

respond(404, "fixture: not found\n", 'text/plain');
