<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use Ogunsakin01\VideoDemo\Models\Video;
use RuntimeException;
use Throwable;

/**
 * Who a video is recorded as: a guest (signed out), the default user from
 * video-demo.recorder.login_as, or any user, offered by role when the app
 * uses spatie/laravel-permission. Stored on the video as "guest", a user id,
 * or null for the default.
 */
class RecordAs
{
    public const GUEST = 'guest';

    /**
     * Choices for the form, value => label
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        if ($default = config('video-demo.recorder.login_as')) {
            $options[''] = "The default user ($default)";
        }
        $options[self::GUEST] = 'Guest (signed out)';

        try {
            foreach (static::usersByRole() as $role => $users) {
                foreach ($users as $user) {
                    $options[(string) $user->getAuthIdentifier()] = ($role !== '' ? ucfirst($role).': ' : '').static::name($user);
                }
            }
        } catch (Throwable) {
            // No users table yet, or an unusual user model: offer guest and the default only
        }

        return $options;
    }

    /** The signed-in user a video is recorded as, or null for a guest */
    public static function user(Video $video): ?Authenticatable
    {
        return static::resolve($video->record_as);
    }

    /** "guest" is signed out, null or "" the default user, anything else a user id or email */
    public static function resolve(?string $recordAs): ?Authenticatable
    {
        return match ($recordAs) {
            self::GUEST => null,
            null, '' => Recorder::loginUser(),
            default => static::find($recordAs),
        };
    }

    /** "admin@example.com (admin)", for the planner; null for a guest */
    public static function describe(Video $video): ?string
    {
        $user = static::user($video);
        if (! $user) {
            return null;
        }

        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->implode(', ') : '';

        return static::name($user).($roles !== '' ? " (role: $roles)" : '');
    }

    /** The label stored with a video */
    public static function label(?string $value): string
    {
        $options = static::options();
        if (isset($options[$value ?? ''])) {
            return $options[$value ?? ''];
        }

        // With no default user, the default is signed out
        return in_array($value, [null, '', self::GUEST], true) ? 'Guest (signed out)' : (string) $value;
    }

    public static function find(string $idOrEmail): Authenticatable
    {
        $provider = static::provider();
        $user = ctype_digit($idOrEmail) ? $provider->retrieveById($idOrEmail) : $provider->retrieveByCredentials(['email' => $idOrEmail]);

        return $user ?? throw new RuntimeException("The user [$idOrEmail] to record as was not found.");
    }

    public static function provider(): UserProvider
    {
        $guard = config('video-demo.recorder.guard') ?: config('auth.defaults.guard');

        return Auth::createUserProvider(config("auth.guards.$guard.provider"))
            ?? throw new RuntimeException("The [$guard] guard has no user provider. Check video-demo.recorder.guard.");
    }

    /**
     * A few users for each role (spatie/laravel-permission), or the first
     * users when the app has no roles
     *
     * @return array<string, iterable<Authenticatable>>
     */
    protected static function usersByRole(): array
    {
        $model = config('auth.providers.users.model');
        if (! $model || ! class_exists($model)) {
            return [];
        }

        $roleModel = '\\Spatie\\Permission\\Models\\Role';
        if (class_exists($roleModel) && method_exists($model, 'role')) {
            $groups = [];
            foreach ($roleModel::query()->orderBy('name')->pluck('name') as $role) {
                $groups[$role] = $model::role($role)->limit(5)->get();
            }

            return $groups;
        }

        return ['' => $model::query()->limit(20)->get()];
    }

    protected static function name(Authenticatable $user): string
    {
        return (string) ($user->email ?? $user->name ?? $user->getAuthIdentifier());
    }
}
