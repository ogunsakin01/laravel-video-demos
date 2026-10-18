<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

/**
 * What the recorder's browser needs to be signed in to a separate frontend:
 * cookies, localStorage and sessionStorage entries on the frontend, and
 * headers on requests to this app's API. Made from a preset
 * (video-demo.frontend.sign_in) or from VideoDemo::signInUsing(), always in
 * this app, so any kind of sign-in works: sessions, Sanctum tokens, JWTs,
 * Passport, or the app's own session after SSO.
 */
final class SignIn
{
    /**
     * The app's own way to sign the recorder in, from VideoDemo::signInUsing().
     *
     * @var ?Closure(Authenticatable): SignIn
     */
    protected static ?Closure $using = null;

    /**
     * @var list<array{name: string, value: string}>
     */
    protected array $cookies = [];

    /**
     * @var array<string, string>
     */
    protected array $localStorage = [];

    /**
     * @var array<string, string>
     */
    protected array $sessionStorage = [];

    /**
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Sign the recorder in the app's own way.
     *
     * @param  ?Closure(Authenticatable): SignIn  $callback
     */
    public static function using(?Closure $callback): void
    {
        self::$using = $callback;
    }

    /**
     * Determine if the app registered its own way to sign in.
     */
    public static function hasCallback(): bool
    {
        return self::$using !== null;
    }

    /**
     * Start with a cookie, sent to this app's API.
     */
    public static function cookie(string $name, string $value): self
    {
        return (new self)->withCookie($name, $value);
    }

    /**
     * Start with a localStorage entry on the frontend.
     */
    public static function localStorage(string $key, string $value): self
    {
        return (new self)->withLocalStorage($key, $value);
    }

    /**
     * Start with a sessionStorage entry on the frontend.
     */
    public static function sessionStorage(string $key, string $value): self
    {
        return (new self)->withSessionStorage($key, $value);
    }

    /**
     * Start with a header on every request to this app's API.
     */
    public static function header(string $name, string $value): self
    {
        return (new self)->withHeader($name, $value);
    }

    /**
     * Add a cookie, sent to this app's API.
     */
    public function withCookie(string $name, string $value): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value];

        return $this;
    }

    /**
     * Add a localStorage entry on the frontend.
     */
    public function withLocalStorage(string $key, string $value): self
    {
        $this->localStorage[$key] = $value;

        return $this;
    }

    /**
     * Add a sessionStorage entry on the frontend.
     */
    public function withSessionStorage(string $key, string $value): self
    {
        $this->sessionStorage[$key] = $value;

        return $this;
    }

    /**
     * Add a header on every request to this app's API.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Get the sign-in for a user, from the app's callback or the configured preset.
     *
     * @throws RuntimeException When the preset can't be used with this app.
     */
    public static function for(Authenticatable $user): self
    {
        $preset = (string) config('video-demo.frontend.sign_in', 'session');
        $key = (string) config('video-demo.frontend.token_key', 'token');

        if ($preset === 'custom') {
            /** @var mixed $signIn The app's callback may return anything. */
            $signIn = self::$using ? (self::$using)($user) : throw new RuntimeException('video-demo.frontend.sign_in is "custom", but no VideoDemo::signInUsing() callback is registered.');

            return $signIn instanceof self ? $signIn : throw new RuntimeException('VideoDemo::signInUsing() must return a SignIn.');
        }

        return match ($preset) {
            'sanctum-token' => self::localStorage($key, self::sanctumToken($user)),
            'jwt' => self::localStorage($key, self::jwt($user)),
            default => throw new RuntimeException("video-demo.frontend.sign_in [$preset] isn't a way to sign in. Use session, sanctum-token, jwt or custom."),
        };
    }

    /**
     * Determine if the recorder signs in with what this class makes, rather
     * than with this app's login session. Only a separate frontend does.
     */
    public static function usesTokens(): bool
    {
        return config('video-demo.frontend.url') && config('video-demo.frontend.sign_in', 'session') !== 'session';
    }

    /**
     * Make a Sanctum token for the user that expires in an hour.
     */
    protected static function sanctumToken(Authenticatable $user): string
    {
        if (! method_exists($user, 'createToken')) {
            throw new RuntimeException('The user model has no createToken(). Add Laravel\Sanctum\HasApiTokens to it, or sign in another way (video-demo.frontend.sign_in).');
        }

        // Each recording, check and look signs in again, so the recorder's expired tokens are removed.
        if (method_exists($user, 'tokens')) {
            $user->tokens()->where('name', 'video-demo')->where('expires_at', '<', now())->delete();
        }

        return $user->createToken('video-demo', ['*'], now()->addHour())->plainTextToken;
    }

    /**
     * Make a JWT for the user with jwt-auth.
     */
    protected static function jwt(Authenticatable $user): string
    {
        // Both jwt-auth packages (php-open-source-saver and tymon) register it under this name.
        if (! app()->bound('tymon.jwt.auth')) {
            throw new RuntimeException('No JWT package was found. Install php-open-source-saver/jwt-auth, or sign in another way (video-demo.frontend.sign_in).');
        }

        return (string) app('tymon.jwt.auth')->fromUser($user);
    }

    /**
     * Get what the browser needs, for the recorder.
     *
     * @return array{cookies: list<array{name: string, value: string}>, localStorage: array<string, string>, sessionStorage: array<string, string>, headers: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'cookies' => $this->cookies,
            'localStorage' => $this->localStorage,
            'sessionStorage' => $this->sessionStorage,
            'headers' => $this->headers,
        ];
    }
}
