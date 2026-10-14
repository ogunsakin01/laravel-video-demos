<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Models\Video;
use RuntimeException;
use Throwable;

/**
 * Resolves who a video is recorded as: a guest (signed out), the default user
 * from video-demo.recorder.login_as, or a type of user, such as "Admin" or
 * "Member". At recording time a user of that type is signed in.
 *
 * Types come from the way the app models roles, tried in this order:
 * video-demo.recorder.user_types, spatie/laravel-permission, Laratrust,
 * Bouncer, a role_id column, a role column on the users table, then an
 * admin flag. The choice is stored on the video as "guest", "type:<key>",
 * null for the default, or (videos from 0.4.0) a user id or email.
 */
class RecordAs
{
    public const GUEST = 'guest';

    public const TYPE = 'type:';

    /**
     * Columns on the users table that usually hold a role or user type.
     */
    protected const ROLE_COLUMNS = ['role', 'type', 'user_type', 'account_type', 'role_name', 'user_role'];

    /**
     * Yes/no columns that usually mark an admin.
     */
    protected const ADMIN_FLAGS = ['is_admin', 'admin', 'is_super_admin', 'is_staff', 'is_superuser'];

    /**
     * Get the choices for the form, value => label.
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

        foreach (static::types() as $key => $type) {
            $options[self::TYPE.$key] = $type['label'];
        }

        return $options;
    }

    /**
     * Get the user types the app has, each with a label and a way to find one
     * such user.
     *
     * Each also says how the app gives a user that type (via) and the role's
     * name, so a user of the type can be made in a sample database.
     *
     * @return array<string, array{label: string, users: Closure(): ?Authenticatable, via?: string, role?: string}>
     */
    public static function types(): array
    {
        $strategies = [
            fn () => static::configured(), fn () => static::spatie(), fn () => static::laratrust(), fn () => static::bouncer(),
            fn () => static::roleTable(), fn () => static::roleColumn(), fn () => static::adminFlag(),
        ];
        foreach ($strategies as $find) {
            try {
                if ($types = $find()) {
                    return $types;
                }
            } catch (Throwable) {
                // The app doesn't model roles this way; try the next.
            }
        }

        return [];
    }

    /**
     * Get the user a video is recorded as, or null for a guest.
     */
    public static function user(Video $video): ?Authenticatable
    {
        return static::resolve($video->record_as);
    }

    /**
     * Resolve a stored value to the user to record as, or null for a guest.
     *
     * "guest" is signed out; null or "" the default user; "type:admin" a user
     * of that type; anything else a user id or email.
     */
    public static function resolve(?string $recordAs): ?Authenticatable
    {
        if ($recordAs === self::GUEST) {
            return null;
        }
        if ($recordAs === null || $recordAs === '') {
            return Recorder::loginUser();
        }
        if (str_starts_with($recordAs, self::TYPE)) {
            $key = substr($recordAs, strlen(self::TYPE));
            $type = static::types()[$key] ?? throw new RuntimeException("The user type [$key] wasn't found. Check the app's roles, or set video-demo.recorder.user_types.");

            return ($type['users'])() ?? throw new RuntimeException("No user is a {$type['label']}. Add one to your local database (a seeder is a good place), then try again.");
        }

        return static::find($recordAs);
    }

    /**
     * Describe who a video is recorded as for the planner, such as
     * "a Member user, member@example.com", or null for a guest.
     */
    public static function describe(Video $video): ?string
    {
        $user = static::user($video);
        if (! $user) {
            return null;
        }

        $type = str_starts_with((string) $video->record_as, self::TYPE) ? static::label($video->record_as) : null;
        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->implode(', ') : '';

        return ($type ? "a $type user, " : '').static::name($user).($roles !== '' ? " (role: $roles)" : '');
    }

    /**
     * Get the label for a stored value.
     */
    public static function label(?string $value): string
    {
        $options = static::options();
        if (isset($options[$value ?? ''])) {
            return $options[$value ?? ''];
        }
        if (str_starts_with((string) $value, self::TYPE)) {
            return Str::headline(substr($value, strlen(self::TYPE)));
        }

        // With no default user, the default is signed out.
        return in_array($value, [null, '', self::GUEST], true) ? 'Guest (signed out)' : (string) $value;
    }

    /**
     * Parse what someone typed into the value stored on a video.
     *
     * The input may be a type's key or label, "guest", or a user id or email.
     */
    public static function parse(string $input): string
    {
        if ($input === self::GUEST) {
            return self::GUEST;
        }

        $wanted = Str::lower(Str::after($input, self::TYPE));
        foreach (static::types() as $key => $type) {
            if (Str::lower($key) === $wanted || Str::lower($type['label']) === $wanted) {
                return self::TYPE.$key;
            }
        }

        return (string) static::find($input)->getAuthIdentifier();
    }

    /**
     * Find a user by id or email.
     *
     * @throws RuntimeException
     */
    public static function find(string $idOrEmail): Authenticatable
    {
        $provider = static::provider();
        $user = ctype_digit($idOrEmail) ? $provider->retrieveById($idOrEmail) : $provider->retrieveByCredentials(['email' => $idOrEmail]);

        return $user ?? throw new RuntimeException("The user [$idOrEmail] to record as was not found.");
    }

    /**
     * Get the user provider of the guard the recorder signs in with.
     */
    public static function provider(): UserProvider
    {
        $guard = static::guard();

        return Auth::createUserProvider(config("auth.guards.$guard.provider"))
            ?? throw new RuntimeException("The [$guard] guard has no user provider. Check video-demo.recorder.guard.");
    }

    /**
     * Get the guard the recorder signs in with.
     */
    protected static function guard(): string
    {
        return config('video-demo.recorder.guard') ?: config('auth.defaults.guard');
    }

    /**
     * Get the Eloquent user model, or null when users aren't Eloquent models.
     */
    public static function model(): ?string
    {
        $model = config('auth.providers.'.config('auth.guards.'.static::guard().'.provider').'.model')
            ?? config('auth.providers.users.model');

        return $model && class_exists($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    /**
     * Build a closure that finds a user the query matches.
     *
     * With $roleCount (a subquery counting a user's roles), users with the
     * fewest roles come first: a "Member" video shouldn't be recorded by
     * someone who is also an admin. The default user is preferred among the
     * best matches, so videos show familiar data.
     *
     * @param  ?Closure(string $table, string $key): \Illuminate\Database\Query\Builder  $roleCount
     */
    protected static function first(Closure $scope, ?Closure $roleCount = null): Closure
    {
        return function () use ($scope, $roleCount): ?Authenticatable {
            $model = static::model();
            $user = new $model;
            /** @var Builder<Model> $query */
            $query = $scope($model::query());
            if ($roleCount) {
                $query->select($user->getTable().'.*')->selectSub($roleCount($user->getTable(), $user->getKeyName()), 'video_demo_roles');
            }

            $best = (clone $query)->when($roleCount !== null, fn ($q) => $q->orderBy('video_demo_roles'))->orderBy($user->getQualifiedKeyName())->first();

            if ($best && ($default = config('video-demo.recorder.login_as'))) {
                $column = ctype_digit((string) $default) ? $user->getQualifiedKeyName() : 'email';
                $match = (clone $query)->where($column, $default)->first();
                if ($match && (! $roleCount || $match->getAttribute('video_demo_roles') <= $best->getAttribute('video_demo_roles'))) {
                    $best = $match;
                }
            }

            return $best instanceof Authenticatable ? $best : null;
        };
    }

    /**
     * Get the types set in video-demo.recorder.user_types (label => user id or email).
     */
    protected static function configured(): array
    {
        $types = [];
        foreach ((array) config('video-demo.recorder.user_types', []) as $label => $user) {
            $types[Str::slug($label)] = ['label' => (string) $label, 'users' => fn () => static::find((string) $user), 'via' => 'configured', 'role' => (string) $user];
        }

        return $types;
    }

    /**
     * Get the types from spatie/laravel-permission roles.
     */
    protected static function spatie(): array
    {
        $roleModel = config('permission.models.role', 'Spatie\\Permission\\Models\\Role');
        $model = static::model();
        // Only models using HasRoles have the role() query scope.
        if (! class_exists($roleModel) || ! $model || ! method_exists($model, 'scopeRole')) {
            return [];
        }

        $types = [];
        foreach ($roleModel::query()->orderBy('name')->pluck('name') as $role) {
            $types[Str::slug($role)] = ['label' => Str::headline($role), 'via' => 'spatie', 'role' => $role, 'users' => static::first(
                fn ($q) => $q->role($role),
                fn ($table, $key) => DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
                    ->selectRaw('count(*)')
                    ->whereColumn(config('permission.column_names.model_morph_key', 'model_id'), "$table.$key")
                    ->where('model_type', (new $model)->getMorphClass()),
            )];
        }

        return $types;
    }

    /**
     * Get the types from Laratrust's roles table and role_user pivot.
     */
    protected static function laratrust(): array
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_user') || ! Schema::hasColumn('role_user', 'user_id')) {
            return [];
        }

        return static::fromRolesTable('laratrust', fn ($q, $id) => $q->whereIn(
            (new (static::model()))->getKeyName(),
            DB::table('role_user')->where('role_id', $id)->select('user_id'),
        ), fn ($table, $key) => DB::table('role_user')->selectRaw('count(*)')->whereColumn('role_user.user_id', "$table.$key"));
    }

    /**
     * Get the types from Bouncer's roles and assigned_roles tables.
     */
    protected static function bouncer(): array
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('assigned_roles')) {
            return [];
        }

        return static::fromRolesTable('bouncer', fn ($q, $id) => $q->whereIn(
            (new (static::model()))->getKeyName(),
            DB::table('assigned_roles')->where('role_id', $id)->where('entity_type', static::model())->select('entity_id'),
        ), fn ($table, $key) => DB::table('assigned_roles')->selectRaw('count(*)')->whereColumn('assigned_roles.entity_id', "$table.$key"));
    }

    /**
     * Get the types from a role_id column on users pointing at a roles table.
     */
    protected static function roleTable(): array
    {
        $model = static::model();
        if (! $model || ! Schema::hasTable('roles') || ! Schema::hasColumn((new $model)->getTable(), 'role_id')) {
            return [];
        }

        return static::fromRolesTable('role_table', fn ($q, $id) => $q->where('role_id', $id));
    }

    /**
     * Get a type for each row of the roles table, finding its users with the scope.
     *
     * @param  Closure(Builder<Model>, mixed): Builder<Model>  $scope
     */
    protected static function fromRolesTable(string $via, Closure $scope, ?Closure $roleCount = null): array
    {
        if (! static::model()) {
            return [];
        }

        $label = Schema::hasColumn('roles', 'display_name') ? 'display_name' : (Schema::hasColumn('roles', 'title') ? 'title' : 'name');
        $types = [];
        foreach (DB::table('roles')->orderBy('name')->limit(30)->get() as $role) {
            $types[Str::slug($role->name)] = ['label' => $label !== 'name' && $role->{$label} ? $role->{$label} : Str::headline($role->name), 'via' => $via, 'role' => $role->name, 'users' => static::first(fn ($q) => $scope($q, $role->id), $roleCount)];
        }

        return $types;
    }

    /**
     * Get the types from a role or type column on the users table, one per value.
     */
    protected static function roleColumn(): array
    {
        $model = static::model();
        if (! $model) {
            return [];
        }

        $table = (new $model)->getTable();
        foreach (self::ROLE_COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $values = DB::table($table)->whereNotNull($column)->distinct()->orderBy($column)->limit(30)->pluck($column);
            $types = [];
            foreach ($values as $value) {
                $value = (string) $value;
                $types[Str::slug($value) ?: $value] = ['label' => Str::headline($value), 'via' => "column:$column", 'role' => $value, 'users' => static::first(fn ($q) => $q->where($column, $value))];
            }

            if ($types) {
                return $types;
            }
        }

        return [];
    }

    /**
     * Get two types from an is_admin style flag: admins and everyone else.
     */
    protected static function adminFlag(): array
    {
        $model = static::model();
        if (! $model) {
            return [];
        }

        $table = (new $model)->getTable();
        foreach (self::ADMIN_FLAGS as $column) {
            if (Schema::hasColumn($table, $column)) {
                return [
                    'admin' => ['label' => 'Admin', 'via' => "flag:$column", 'role' => 'admin', 'users' => static::first(fn ($q) => $q->where($column, true))],
                    'user' => ['label' => 'Other users', 'via' => "flag:$column", 'role' => 'user', 'users' => static::first(fn ($q) => $q->where(fn ($w) => $w->where($column, false)->orWhereNull($column)))],
                ];
            }
        }

        return [];
    }

    /**
     * Get a short name for a user: their email, name or id.
     */
    protected static function name(Authenticatable $user): string
    {
        return (string) ($user->email ?? $user->name ?? $user->getAuthIdentifier());
    }
}
