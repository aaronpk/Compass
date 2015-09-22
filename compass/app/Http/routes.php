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

$app->post('/database/create', 'Controller@createDatabase');
