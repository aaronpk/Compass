<?php

$legacyErrorReporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED;

error_reporting($legacyErrorReporting);

require_once __DIR__.'/../vendor/autoload.php';

if (file_exists(__DIR__.'/../.env')) {
    Dotenv::load(__DIR__.'/../');
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Here we will load the environment and create the application instance
| that serves as the central piece of this framework. We'll use this
| application as an "IoC" container and router for this framework.
|
*/

$app = new Laravel\Lumen\Application(
    realpath(__DIR__.'/../')
);

error_reporting($legacyErrorReporting);

Illuminate\Support\Facades\Facade::setFacadeApplication($app);

// Lumen 5.1 aliases Event globally, which can collide with PHP's event extension.
foreach ([
    'App' => Illuminate\Support\Facades\App::class,
    'Auth' => Illuminate\Support\Facades\Auth::class,
    'Bus' => Illuminate\Support\Facades\Bus::class,
    'DB' => Illuminate\Support\Facades\DB::class,
    'Cache' => Illuminate\Support\Facades\Cache::class,
    'Cookie' => Illuminate\Support\Facades\Cookie::class,
    'Crypt' => Illuminate\Support\Facades\Crypt::class,
    'Hash' => Illuminate\Support\Facades\Hash::class,
    'Log' => Illuminate\Support\Facades\Log::class,
    'Mail' => Illuminate\Support\Facades\Mail::class,
    'Queue' => Illuminate\Support\Facades\Queue::class,
    'Request' => Illuminate\Support\Facades\Request::class,
    'Schema' => Illuminate\Support\Facades\Schema::class,
    'Session' => Illuminate\Support\Facades\Session::class,
    'Storage' => Illuminate\Support\Facades\Storage::class,
    'Validator' => Illuminate\Support\Facades\Validator::class,
] as $alias => $class) {
    if (!class_exists($alias, false)) {
        class_alias($class, $alias);
    }
}

$aliasesRegistered = new ReflectionProperty(Laravel\Lumen\Application::class, 'aliasesRegistered');
$aliasesRegistered->setAccessible(true);
$aliasesRegistered->setValue(true);

// $app->withEloquent();

/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
|
| Now we will register a few bindings in the service container. We will
| register the exception handler and the console kernel. You may add
| your own bindings here if you like or you can make another file.
|
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| Register Middleware
|--------------------------------------------------------------------------
|
| Next, we will register the middleware with the application. These can
| be global middleware that run before and after each request into a
| route or middleware that'll be assigned to some specific routes.
|
*/

$app->middleware([
    Illuminate\Cookie\Middleware\EncryptCookies::class,
    Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    Illuminate\Session\Middleware\StartSession::class,
    // Illuminate\View\Middleware\ShareErrorsFromSession::class,
    // Laravel\Lumen\Http\Middleware\VerifyCsrfToken::class,
]);

// $app->routeMiddleware([

// ]);

/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
|
| Here we will register all of the application's service providers which
| are used to bind services into the container. Service providers are
| totally optional, so you are not required to uncomment this line.
|
*/

// $app->register(App\Providers\AppServiceProvider::class);
// $app->register(App\Providers\EventServiceProvider::class);

/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
|
| Next we will include the routes file so that they can all be added to
| the application. This will provide all of the URLs the application
| can respond to, as well as the controllers that may handle them.
|
*/

$app->group(['namespace' => 'App\Http\Controllers'], function ($app) {
    require __DIR__.'/../app/Http/routes.php';
});

return $app;
