<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_core_api_routes_are_registered(): void
    {
        $routes = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains('api/photos', $routes);
        $this->assertContains('api/photos/markers', $routes);
        $this->assertContains('api/auth/login', $routes);
        $this->assertContains('api/admin/photos', $routes);
    }
}
