<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Controllers\IndieAuth;
use App\Http\Controllers\LocalTime;
use App\Http\Controllers\Share;
use App\Http\Controllers\Stats;
use Illuminate\Support\Facades\Route;

Route::get('/', [Controller::class, 'index']);

Route::get('/s/{token}', [Share::class, 'view'])->where('token', '[A-Za-z0-9]+');
Route::get('/share/current.json', [Share::class, 'current_location']);
Route::get('/share/history.json', [Share::class, 'history']);
Route::get('/share/authorize', [Share::class, 'authorize']);
Route::get('/share/viewer-connected', [Share::class, 'viewerConnected']);
Route::get('/shares/{name}', [Share::class, 'index'])->where('name', '[A-Za-z0-9]+');
Route::post('/shares/{name}', [Share::class, 'create'])->where('name', '[A-Za-z0-9]+');
Route::post('/shares/{name}/{id}/stop', [Share::class, 'revoke'])->where('name', '[A-Za-z0-9]+')->where('id', '[0-9]+');

Route::post('/auth/start', [IndieAuth::class, 'start']);
Route::get('/auth/callback', [IndieAuth::class, 'callback']);
Route::get('/auth/github', [IndieAuth::class, 'github']);
Route::get('/auth/logout', [IndieAuth::class, 'logout']);

Route::get('/map/{name}', [Controller::class, 'map'])->where('name', '[A-Za-z0-9]+');
Route::get('/settings/{name}', [Controller::class, 'settings'])->where('name', '[A-Za-z0-9]+');
Route::post('/settings/{name}', [Controller::class, 'updateSettings'])->where('name', '[A-Za-z0-9]+');
Route::post('/settings/{name}/auth/start', [Controller::class, 'micropubStart'])->where('name', '[A-Za-z0-9]+');
Route::get('/settings/{name}/auth/callback', [Controller::class, 'micropubCallback'])->where('name', '[A-Za-z0-9]+');
Route::get('/settings/{name}/auth/remove', [Controller::class, 'removeMicropub'])->where('name', '[A-Za-z0-9]+');
Route::post('/database/create', [Controller::class, 'createDatabase']);

Route::get('/stats/{name}/world', [Stats::class, 'world'])->where('name', '[A-Za-z0-9]+');
Route::post('/stats/{name}/embed', [Stats::class, 'embedSetting'])->where('name', '[A-Za-z0-9]+');
Route::get('/stats/{name}/{year?}', [Stats::class, 'year'])->where('name', '[A-Za-z0-9]+')->where('year', '[0-9]{4}');
Route::get('/embed/world/{token}.json', [Stats::class, 'embedJson'])->where('token', '[A-Za-z0-9]+');
Route::get('/embed/world/{token}', [Stats::class, 'embed'])->where('token', '[A-Za-z0-9]+');

Route::get('/api/query', [Api::class, 'query']);
Route::get('/api/last', [Api::class, 'last']);
Route::get('/api/trip', [Api::class, 'trip']);
Route::get('/api/find-from-localtime', [LocalTime::class, 'find']);
Route::get('/api/input', [Api::class, 'account']);
Route::post('/api/input', [Api::class, 'input']);
Route::post('/api/trip-complete', [Api::class, 'trip_complete']);
Route::post('/api/share', [Api::class, 'share']);
Route::get('/api/stats', [Stats::class, 'yearJson']);
Route::get('/api/countries', [Stats::class, 'worldJson']);
