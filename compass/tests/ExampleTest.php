<?php

class ExampleTest extends TestCase
{
    public function testHomePageLoads()
    {
        $response = $this->request('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Compass', $response->getContent());
    }

    public function testApiInputRequiresToken()
    {
        $response = $this->request('GET', '/api/input');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(['error' => 'no token provided'], json_decode($response->getContent(), true));
    }
}
