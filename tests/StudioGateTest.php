<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Tests who can open the studio outside a local machine.
 */
class StudioGateTest extends TestCase
{
    public function test_open_on_a_local_machine_without_a_gate(): void
    {
        $this->get('/video-demo/studio')->assertOk();
    }

    #[DefineEnvironment('onStaging')]
    public function test_closed_elsewhere_when_no_gate_is_defined(): void
    {
        $this->get('/video-demo/studio')->assertForbidden();
        $this->get('/video-demo/studio/create')->assertForbidden();
    }

    #[DefineEnvironment('onStaging')]
    public function test_elsewhere_only_users_the_gate_allows_get_in(): void
    {
        Gate::define('viewVideoDemoStudio', fn (?User $user) => $user?->getAttribute('email') === 'admin@example.com');

        $this->get('/video-demo/studio')->assertForbidden();
        $this->actingAs((new User)->forceFill(['id' => 2, 'email' => 'someone@example.com']))->get('/video-demo/studio')->assertForbidden();
        $this->actingAs((new User)->forceFill(['id' => 1, 'email' => 'admin@example.com']))->get('/video-demo/studio')->assertOk();
    }

    #[DefineEnvironment('onStaging')]
    public function test_the_recorders_signed_link_is_not_behind_the_gate(): void
    {
        // User 1 doesn't exist, so the controller answers 404; what matters
        // is that the gate is never asked.
        Gate::define('viewVideoDemoStudio', fn () => throw new \LogicException('The gate should not be checked.'));
        $link = URL::temporarySignedRoute('video-demo.studio.recorder-login', now()->addMinutes(5), ['user' => 1], absolute: false);

        $this->get($link)->assertNotFound();
    }

    protected function onStaging($app): void
    {
        $app['config']->set('app.env', 'staging');
        $app->detectEnvironment(fn () => 'staging');
    }
}
