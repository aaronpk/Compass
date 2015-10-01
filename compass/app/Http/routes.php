<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$app->get('/', 'Controller@index');

$app->post('/auth/start', 'IndieAuth@start');
$app->get('/auth/callback', 'IndieAuth@callback');
$app->get('/auth/github', 'IndieAuth@github');
$app->get('/auth/logout', 'IndieAuth@logout');

$app->get('/map/{name:[A-Za-z0-9]+}', 'Controller@map');
$app->get('/settings/{name:[A-Za-z0-9]+}', 'Controller@settings');
$app->post('/settings/{name:[A-Za-z0-9]+}', 'Controller@updateSettings');
$app->post('/database/create', 'Controller@createDatabase');

$app->get('/api/query', 'Api@query');
$app->get('/api/input', 'Api@account');
$app->post('/api/input', 'Api@input');

// Event::listen('illuminate.query', function($query){
//   Log::debug($query);
// });

