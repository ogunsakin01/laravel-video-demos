<?php

namespace Ogunsakin01\VideoDemo\SampleData;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use RuntimeException;
use Throwable;

/**
 * Builds and fills a sandbox database. It runs inside the sandbox's own PHP
 * process (the video-demo:sample-data command), where the sandbox is the
 * default connection, so the app's migrations, seeders, factories and
 * models all work on it unchanged.
 */
class Builder
{
    /**
     * Tables that hold the framework's or the package's own data.
     */
    protected const INTERNAL = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'password_resets', 'personal_access_tokens', 'sqlite_sequence', 'telescope_*', 'pulse_*', 'video_demo_*'];

    /**
     * Columns whose values are never shown.
     */
    protected const HIDDEN = ['password', 'remember_token', '*token*', '*secret*', 'two_factor_*'];

    /**
     * Create a new builder instance.
     */
    public function __construct(protected Filler $filler = new Filler) {}

    /**
     * Build the base database: run the migrations and seeders, then make sure
     * there is a user of each type.
     *
     * @param  array{seeders?: list<string>, types?: list<array{key: string, label: string, via: ?string, role: string}>}  $input
     * @return array<string, mixed>
     */
    public function build(array $input): array
    {
        try {
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new RuntimeException(trim(Artisan::output()));
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Your migrations could not run on SQLite, which sample data uses: '.$this->firstLine($e->getMessage())];
        }

        $seeded = [];
        $warnings = [];
        foreach ($input['seeders'] ?? [] as $seeder) {
            if (! class_exists($seeder)) {
                continue;
            }
            try {
                if (Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]) !== 0) {
                    throw new RuntimeException(trim(Artisan::output()));
                }
                $seeded[] = class_basename($seeder);
            } catch (Throwable $e) {
                $warnings[] = class_basename($seeder).' failed, so only part of its data may be there: '.$this->firstLine($e->getMessage());
            }
        }

        // A user of each type, so any video can be recorded as any of them.
        $users = [];
        foreach ($input['types'] ?? [] as $type) {
            try {
                $user = $this->userOfType($type);
                $users[$type['label']] = (string) ($user->email ?? $user->getAuthIdentifier());
            } catch (Throwable $e) {
                $warnings[] = "No {$type['label']} user could be made: ".$this->firstLine($e->getMessage());
            }
        }

        return ['ok' => true, 'seeded' => $seeded, 'warnings' => $warnings, 'users' => $users, 'tables' => $this->counts()];
    }

    /**
     * Find or make the user to record as, in a copy of the base database.
     *
     * @param  array{record_as?: ?string, type?: ?array{key: string, label: string, via: ?string, role: string}, login_as?: mixed}  $input
     * @return array<string, mixed>
     */
    public function prepare(array $input): array
    {
        try {
            $user = $this->user($input);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'The user to record as could not be made in the sample database: '.$this->firstLine($e->getMessage())];
        }

        if ($user) {
            $this->saveRefs(['user' => ['ids' => [$user->getAuthIdentifier()], 'table' => $user instanceof Model ? $user->getTable() : 'users']]);
        }

        return ['ok' => true, 'user' => $user ? $this->describeUser($user) : null, 'tables' => $this->counts()];
    }

    /**
     * Find or make the user a video signs in as partway through (a sign_in
     * step): one of the type, or the default user, or else a new user.
     *
     * @param  array{record_as?: ?string, type?: ?array{key: string, label: string, via: ?string, role: string}, login_as?: mixed}  $input
     * @return array<string, mixed>
     */
    public function signInUser(array $input): array
    {
        $name = self::signInRefName($input['type']['key'] ?? null);
        try {
            $ref = $this->refs()[$name] ?? $this->signInRef($name);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'The user to sign in as could not be made in the sample database: '.$this->firstLine($e->getMessage())];
        }

        return ['ok' => true, 'id' => $ref['ids'][0] ?? null];
    }

    /**
     * Get the ref a sign_in step's user is saved under: "user_member" for a
     * type of user, "user_default" for the default user. The AI uses it to
     * give that user what the pages after the step need.
     */
    public static function signInRefName(?string $typeKey): string
    {
        return 'user_'.(($typeKey ?? '') !== '' ? $typeKey : 'default');
    }

    /**
     * Find or make the user a sign_in ref names, and save it under that ref:
     * one of the type, or the default user (made when there is none). Null
     * for a name that isn't a sign_in user's.
     *
     * @return ?array{ids: list<mixed>, table: string}
     */
    protected function signInRef(string $name): ?array
    {
        if (! preg_match('/^user_([\w-]+)$/', $name, $m)) {
            return null;
        }

        $this->ensureUsersTable();
        if ($m[1] === 'default') {
            $user = $this->user(['record_as' => null, 'login_as' => config('video-demo.recorder.login_as')]) ?? $this->makeUser(null);
        } else {
            $types = RecordAs::types();
            $found = $types[$m[1]] ?? throw new RuntimeException("There is no type of user [{$m[1]}]. Types: ".(implode(', ', array_keys($types)) ?: 'none').'.');
            $user = $this->userOfType(['key' => $m[1], 'label' => $found['label'], 'via' => $found['via'] ?? null, 'role' => $found['role'] ?? $m[1]]);
        }

        $ref = ['ids' => [$user->getAuthIdentifier()], 'table' => $user instanceof Model ? $user->getTable() : 'users'];
        $this->saveRefs([$name => $ref]);

        return $ref;
    }

    /**
     * Add records, each in its own transaction, so one that fails leaves the
     * others in place.
     *
     * @param  array{records?: list<array<string, mixed>>}  $input
     * @return array<string, mixed>
     */
    public function seed(array $input): array
    {
        $refs = $this->refs();
        $results = [];

        foreach ($input['records'] ?? [] as $i => $record) {
            $what = (string) ($record['model'] ?? '') ?: (string) ($record['table'] ?? '');
            try {
                [$table, $ids] = DB::transaction(fn () => $this->create($record, $refs));
                if (($ref = (string) ($record['ref'] ?? '')) !== '') {
                    $refs[$ref] = ['ids' => $ids, 'table' => $table];
                }
                $results[] = ['ok' => true, 'record' => $i + 1, 'what' => $what, 'ref' => $record['ref'] ?? null, 'ids' => $ids, 'first' => $this->row($table, $ids[0] ?? null)];
            } catch (Throwable $e) {
                $results[] = ['ok' => false, 'record' => $i + 1, 'what' => $what, 'error' => $this->firstLine($e->getMessage())];
            }
        }

        $this->saveRefs($refs);

        return ['results' => $results];
    }

    /**
     * Read rows from a table, or list the tables and their row counts.
     *
     * @param  array{table?: string, where?: array<string, mixed>, limit?: int}  $input
     * @return array<string, mixed>
     */
    public function query(array $input): array
    {
        $table = (string) ($input['table'] ?? '');
        if ($table === '') {
            return ['tables' => $this->counts(withEmpty: true)];
        }
        if (! Schema::hasTable($table)) {
            return ['error' => "There is no table [$table]. Leave table empty to list the tables."];
        }

        $query = DB::table($table)->limit(max(1, min(25, (int) ($input['limit'] ?? 10))));
        foreach ((array) ($input['where'] ?? []) as $column => $value) {
            $query->where($column, $this->resolve($value, $this->refs()));
        }

        return [
            'columns' => array_map(fn ($c) => $c['name'].' '.$c['type_name'].($c['nullable'] ? ' nullable' : ''), $this->filler->columns($table)),
            'rows' => $query->get()->map(fn ($row) => $this->visible((array) $row))->all(),
            'total' => DB::table($table)->count(),
        ];
    }

    /**
     * Create one record (or several, with count), returning its table and ids.
     *
     * Values may point at earlier records: "@ref" is the id of the record
     * saved under that ref, "@ref.column" another of its values, "@user" the
     * recorder's user, "@user_member" the user a sign_in step signs in as. "now" and "now +3 days" are dates.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, array{ids: list<mixed>, table: string}>  $refs
     * @return array{0: string, 1: list<mixed>}
     */
    protected function create(array $record, array $refs): array
    {
        $data = [];
        foreach ((array) ($record['data'] ?? []) as $key => $value) {
            $data[$key] = $this->resolve($value, $refs);
        }
        $count = max(1, min(50, (int) ($record['count'] ?? 1)));

        if (! empty($record['where'])) {
            return $this->update($record, $data, $refs);
        }

        if (! empty($record['model'])) {
            $class = $this->modelClass((string) $record['model']);
            $table = (new $class)->getTable();
            $this->checkColumns($table, $data);
            $ids = [];
            for ($n = 0; $n < $count; $n++) {
                if (in_array(HasFactory::class, class_uses_recursive($class), true) && $this->hasFactory($class)) {
                    $model = call_user_func([$class, 'factory'])->create($data);
                } else {
                    $model = new $class;
                    $model->forceFill([...$this->filler->missing($table, $data, $model->getCasts()), ...$data])->save();
                }
                $ids[] = $model->getKey();
            }

            return [$table, $ids];
        }

        $table = (string) ($record['table'] ?? '');
        if ($table === '' || ! Schema::hasTable($table)) {
            throw new RuntimeException($table === '' ? 'Give a model or a table.' : "There is no table [$table].");
        }
        $this->checkColumns($table, $data);
        $ids = [];
        $hasId = in_array('id', $this->filler->names($table), true);
        for ($n = 0; $n < $count; $n++) {
            $row = [...$this->filler->missing($table, $data), ...$data];
            if ($hasId && ! array_key_exists('id', $row)) {
                $ids[] = DB::table($table)->insertGetId($row);
            } else {
                DB::table($table)->insert($row);
                $ids[] = $row['id'] ?? null;
            }
        }

        return [$table, $ids];
    }

    /**
     * Change the rows matching the record's where, such as the signed-in
     * user's own row, returning their table and ids.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     * @param  array<string, array{ids: list<mixed>, table: string}>  $refs
     * @return array{0: string, 1: list<mixed>}
     */
    protected function update(array $record, array $data, array $refs): array
    {
        $where = [];
        foreach ((array) $record['where'] as $column => $value) {
            $where[$column] = $this->resolve($value, $refs);
        }

        $class = ! empty($record['model']) ? $this->modelClass((string) $record['model']) : null;
        $table = $class ? (new $class)->getTable() : (string) ($record['table'] ?? '');
        if (! $class && ($table === '' || ! Schema::hasTable($table))) {
            throw new RuntimeException($table === '' ? 'Give a model or a table.' : "There is no table [$table].");
        }
        $this->checkColumns($table, [...$data, ...$where]);

        if ($class) {
            // Through the model, so its casts, mutators and events apply.
            $models = $class::query()->where($where)->get();
            $models->each(fn (Model $m) => $m->forceFill($data)->save());
            $ids = $models->map(fn (Model $m) => $m->getKey())->all();
        } else {
            $ids = in_array('id', $this->filler->names($table), true) ? DB::table($table)->where($where)->pluck('id')->all() : [];
            $changed = DB::table($table)->where($where)->update($data);
            $ids = $ids ?: array_fill(0, $changed, null);
        }

        if ($ids === []) {
            throw new RuntimeException("No rows in [$table] match ".json_encode($where).'.');
        }

        return [$table, array_values($ids)];
    }

    /**
     * Find or make the user the video is recorded as.
     *
     * @param  array<string, mixed>  $input
     */
    protected function user(array $input): ?Authenticatable
    {
        $recordAs = $input['record_as'] ?? null;
        if ($recordAs === RecordAs::GUEST) {
            return null;
        }

        if ($type = $input['type'] ?? null) {
            return $this->userOfType($type);
        }

        // The default user, or (older videos) a user id or email.
        $wanted = (string) (($recordAs ?? '') === '' ? ($input['login_as'] ?? '') : $recordAs);
        if ($wanted === '') {
            return null;
        }
        $this->ensureUsersTable();
        try {
            return RecordAs::find($wanted);
        } catch (RuntimeException) {
            return $this->makeUser(null, str_contains($wanted, '@') ? $wanted : null);
        }
    }

    /**
     * Find a user of the type, or make one.
     *
     * @param  array{key: string, label: string, via: ?string, role: string}  $type
     */
    protected function userOfType(array $type): Authenticatable
    {
        $this->ensureUsersTable();
        try {
            $found = (RecordAs::types()[$type['key']]['users'] ?? fn () => null)();
        } catch (Throwable) {
            $found = null;
        }

        return $found ?? $this->makeUser($type, $type['via'] === 'configured' && str_contains($type['role'], '@') ? $type['role'] : null);
    }

    /**
     * Stop, saying why, when the sample database has no users table: no
     * user can be found or made in it, and the reason is plainer than the
     * database error that would follow.
     */
    protected function ensureUsersTable(): void
    {
        if ($table = RecordAs::missingUsersTable()) {
            throw new RuntimeException("it has no [$table] table, as the app's migrations don't make one. Record the video as a guest, or add a migration that makes the table.");
        }
    }

    /**
     * Make a user, with the app's user factory when it has one, and give it the type.
     *
     * @param  ?array{key: string, label: string, via: ?string, role: string}  $type
     */
    protected function makeUser(?array $type, ?string $email = null): Authenticatable
    {
        $class = RecordAs::model() ?? throw new RuntimeException('Users are not Eloquent models, so one cannot be made.');
        $data = $email ? ['email' => $email] : [];

        if ($this->hasFactory($class)) {
            $user = $class::factory()->create($data);
        } else {
            $user = new $class;
            $user->forceFill([...$this->filler->missing($user->getTable(), $data, $user->getCasts()), ...$data, 'email_verified_at' => now()])->save();
        }

        if ($type) {
            $this->giveType($user, $type);
        }

        return $user;
    }

    /**
     * Give a user a type, the way the app records it.
     *
     * @param  array{key: string, label: string, via: ?string, role: string}  $type
     */
    protected function giveType(Model $user, array $type): void
    {
        $role = $type['role'];
        $via = (string) $type['via'];

        if ($via === 'spatie') {
            $roleModel = config('permission.models.role', 'Spatie\\Permission\\Models\\Role');
            call_user_func([$user, 'assignRole'], $roleModel::findOrCreate($role, config('video-demo.recorder.guard') ?: config('auth.defaults.guard')));
        } elseif (in_array($via, ['laratrust', 'bouncer', 'role_table'], true)) {
            $roleId = DB::table('roles')->where('name', $role)->value('id')
                ?? DB::table('roles')->insertGetId([...$this->filler->missing('roles', ['name' => $role]), 'name' => $role]);
            match ($via) {
                'laratrust' => DB::table('role_user')->insert(array_filter(['role_id' => $roleId, 'user_id' => $user->getKey(), 'user_type' => Schema::hasColumn('role_user', 'user_type') ? $user->getMorphClass() : null])),
                'bouncer' => DB::table('assigned_roles')->insert([...$this->filler->missing('assigned_roles', ['role_id' => 1, 'entity_id' => 1, 'entity_type' => 'x']), 'role_id' => $roleId, 'entity_id' => $user->getKey(), 'entity_type' => $user->getMorphClass()]),
                default => $user->forceFill(['role_id' => $roleId])->save(),
            };
        } elseif (str_starts_with($via, 'column:')) {
            $user->forceFill([substr($via, 7) => $role])->save();
        } elseif (str_starts_with($via, 'flag:')) {
            $user->forceFill([substr($via, 5) => $role === 'admin'])->save();
        }
    }

    /**
     * Describe a user for the AI model: who they are and what they may do.
     *
     * @return array<string, mixed>
     */
    protected function describeUser(Authenticatable $user): array
    {
        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->all() : [];
        $permissions = method_exists($user, 'getAllPermissions') ? $user->getAllPermissions()->pluck('name')->take(60)->all() : [];

        return array_filter([
            'id' => $user->getAuthIdentifier(),
            'email' => $user->email ?? null,
            'name' => $user->name ?? null,
            'roles' => $roles,
            'permissions' => $permissions,
            'email_verified' => $user instanceof Model && in_array('email_verified_at', $this->filler->names($user->getTable()), true) ? $user->getAttribute('email_verified_at') !== null : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Turn a value the model wrote into the value to save.
     *
     * @param  array<string, array{ids: list<mixed>, table: string}>  $refs
     */
    protected function resolve(mixed $value, array $refs): mixed
    {
        if (! is_string($value)) {
            return is_array($value) ? json_encode($value) : $value;
        }

        if (preg_match('/^@([\w-]+)(?:\.(\w+))?$/', $value, $m)) {
            $ref = $refs[$m[1]] ?? $this->signInRef($m[1]) ?? throw new RuntimeException("Nothing was saved as [@{$m[1]}] yet. Known: ".(implode(', ', array_map(fn ($r) => "@$r", array_keys($refs))) ?: 'none').'.');
            $id = $ref['ids'][0] ?? null;

            return isset($m[2]) ? DB::table($ref['table'])->where('id', $id)->value($m[2]) : $id;
        }

        if (preg_match('/^(now|today)(\s*[+-].+)?$/i', trim($value))) {
            return Carbon::parse(trim($value))->toDateTimeString();
        }

        return $value;
    }

    /**
     * Refuse values for columns the table doesn't have, naming the ones it does.
     *
     * @param  array<string, mixed>  $data
     */
    protected function checkColumns(string $table, array $data): void
    {
        $names = $this->filler->names($table);
        if ($unknown = array_diff(array_keys($data), $names)) {
            throw new RuntimeException("[$table] has no column ".implode(', ', $unknown).'. Its columns: '.implode(', ', $names).'.');
        }
    }

    /**
     * Find a model class from a name such as "SupportTicket" or a full class name.
     *
     * @return class-string<Model>
     */
    protected function modelClass(string $name): string
    {
        $name = ltrim($name, '\\');
        foreach ([$name, "App\\Models\\$name", "App\\$name"] as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return $class;
            }
        }

        throw new RuntimeException("There is no model [$name]. Use its full class name, or give a table instead.");
    }

    /**
     * Determine if a model has a factory class that exists.
     */
    protected function hasFactory(string $class): bool
    {
        if (! method_exists($class, 'factory')) {
            return false;
        }

        try {
            $class::factory();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Count the rows of the app's tables, leaving out empty ones unless asked.
     *
     * @return array<string, int>
     */
    protected function counts(bool $withEmpty = false): array
    {
        $counts = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (Str::is(self::INTERNAL, $name)) {
                continue;
            }
            $rows = DB::table($name)->count();
            if ($rows > 0 || $withEmpty) {
                $counts[$name] = $rows;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Get a saved row, with secrets left out.
     *
     * @return ?array<string, mixed>
     */
    protected function row(string $table, mixed $id): ?array
    {
        if ($id === null || ! in_array('id', $this->filler->names($table), true)) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->first();

        return $row ? $this->visible((array) $row) : null;
    }

    /**
     * Leave out secret columns and shorten long values.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function visible(array $row): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            if (! Str::is(self::HIDDEN, $column)) {
                $out[$column] = is_string($value) ? Str::limit($value, 120) : $value;
            }
        }

        return $out;
    }

    /**
     * Get the records saved under a ref so far.
     *
     * @return array<string, array{ids: list<mixed>, table: string}>
     */
    protected function refs(): array
    {
        $file = $this->refsFile();

        return is_file($file) ? (json_decode(File::get($file), true) ?: []) : [];
    }

    /**
     * Save the refs for later calls.
     *
     * @param  array<string, array{ids: list<mixed>, table: string}>  $refs
     */
    protected function saveRefs(array $refs): void
    {
        File::put($this->refsFile(), json_encode([...$this->refs(), ...$refs]));
    }

    protected function refsFile(): string
    {
        return config('database.connections.'.Sandbox::CONNECTION.'.database').'.refs.json';
    }

    protected function firstLine(string $message): string
    {
        return Str::limit(trim(strtok(trim($message), "\n") ?: $message), 500);
    }
}
