<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Tests which routes are registered in each environment.
 */
class RoutesTest extends TestCase
{
    public function test_studio_and_library_routes_are_registered_locally(): void
    {
        $this->assertTrue(Route::has('video-demo.studio.index'));
        $this->assertTrue(Route::has('video-demo.studio.recorder-login'));
        $this->assertTrue(Route::has('video-demo.public.index'));
        $this->assertTrue(Route::has('video-demo.private.index'));
        $this->assertFalse(Route::has('video-demo.api.requests'));
    }

    #[DefineEnvironment('inProduction')]
    public function test_studio_routes_do_not_exist_in_production(): void
    {
        $this->assertFalse(Route::has('video-demo.studio.index'));
        $this->assertFalse(Route::has('video-demo.studio.recorder-login'));
        $this->assertTrue(Route::has('video-demo.public.index'));
        $this->get('/video-demo/studio')->assertNotFound();
    }

    #[DefineEnvironment('withRequestsToken')]
    public function test_requests_api_needs_the_token(): void
    {
        $this->getJson('/admin/videos/api/requests')->assertUnauthorized();
        $this->getJson('/admin/videos/api/requests', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->getJson('/admin/videos/api/requests', ['Authorization' => 'Bearer secret-token'])
            ->assertOk()
            ->assertJsonPath('requests', []);
    }

    protected function inProduction($app): void
    {
        $app['config']->set('app.env', 'production');
        $app->detectEnvironment(fn () => 'production');
    }

    protected function withRequestsToken($app): void
    {
        $app['config']->set('video-demo.library.requests_token', 'secret-token');
    }
}
