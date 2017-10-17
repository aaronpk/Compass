<?php

$app->get('/', 'Controller@index');

$app->post('/auth/start', 'IndieAuth@start');
$app->get('/auth/callback', 'IndieAuth@callback');
$app->get('/auth/github', 'IndieAuth@github');
$app->get('/auth/logout', 'IndieAuth@logout');

$app->get('/map/{name:[A-Za-z0-9]+}', 'Controller@map');
$app->get('/settings/{name:[A-Za-z0-9]+}', 'Controller@settings');
$app->post('/settings/{name:[A-Za-z0-9]+}', 'Controller@updateSettings');
$app->post('/settings/{name:[A-Za-z0-9]+}/auth/start', 'Controller@micropubStart');
$app->get('/settings/{name:[A-Za-z0-9]+}/auth/callback', 'Controller@micropubCallback');
$app->get('/settings/{name:[A-Za-z0-9]+}/auth/remove', 'Controller@removeMicropub');
$app->post('/database/create', 'Controller@createDatabase');

$app->get('/api/query', 'Api@query');
$app->get('/api/last', 'Api@last');
$app->get('/api/find-from-localtime', 'LocalTime@find');
$app->get('/api/input', 'Api@account');
$app->post('/api/input', 'Api@input');
$app->post('/api/trip-complete', 'Api@trip_complete');
