<?php

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function request($method, $uri)
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        return $app->handle(Request::create($uri, $method));
    }
}
