<?php

namespace Ogunsakin01\VideoDemo\SampleData;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * A temporary SQLite database a video is recorded against, filled with
 * sample data, so the app's own database is never read or changed.
 *
 * The database is built and filled in a separate PHP process, which uses it
 * as its default connection. The recorder's browser sends a signed cookie,
 * and requests carrying it use the database too, from the start of the
 * request, so sessions, the signed-in user and every query see the sample
 * data. Everyone else's requests use the app's database as usual.
 */
class Sandbox
{
    /**
     * The cookie the recorder's browser sends.
     */
    public const COOKIE = 'video_demo_sandbox';

    /**
     * The environment variable that points a PHP process at a sandbox.
     */
    public const ENV = 'VIDEO_DEMO_SANDBOX';

    /**
     * The name of the database connection the sandbox uses.
     */
    public const CONNECTION = 'video_demo_sandbox';

    /**
     * What preparing the database found: the signed-in user, the tables
     * with rows, and warnings from the app's seeders.
     *
     * @var array{user: ?array<string, mixed>, tables: array<string, int>, seeded: list<string>, warnings: list<string>}
     */
    public array $info = ['user' => null, 'tables' => [], 'seeded' => [], 'warnings' => []];

    /**
     * Create a new sandbox instance for the database at the given path.
     */
    public function __construct(public readonly string $path) {}

    /**
     * Create a video's sandbox: a copy of the base database.
     *
     * @throws RuntimeException When the base database can't be built.
     */
    public static function create(Video $video): self
    {
        $base = static::base();
        $path = static::dir().'/'.$video->id.'-'.Str::lower(Str::random(12)).'.sqlite';
        File::copy($base['path'], $path);

        $sandbox = new self($path);
        $sandbox->info = [...$sandbox->info, 'seeded' => $base['info']['seeded'] ?? [], 'warnings' => $base['info']['warnings'] ?? []];

        return $sandbox;
    }

    /**
     * Get the base database every video's sandbox is copied from, building
     * it when it is missing, out of date, or $fresh is set.
     *
     * Building runs the app's migrations and seeders and makes a user of
     * each type, once. It is rebuilt when a migration, seeder or factory
     * changes. A lock keeps two workers from building it at once.
     *
     * @return array{path: string, info: array<string, mixed>, built: bool}
     *
     * @throws RuntimeException When the migrations fail.
     */
    public static function base(bool $fresh = false): array
    {
        File::ensureDirectoryExists(static::dir());
        $path = static::dir().'/base-'.static::fingerprint().'.sqlite';

        $lock = fopen(static::dir().'/base.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            if (! $fresh && is_file($path) && is_file("$path.json")) {
                return ['path' => $path, 'info' => json_decode(File::get("$path.json"), true) ?: [], 'built' => false];
            }

            $building = substr($path, 0, -7).'-building.sqlite';
            File::put($building, '');
            $types = [];
            foreach (RecordAs::types() as $key => $type) {
                $types[] = ['key' => (string) $key, 'label' => $type['label'], 'via' => $type['via'] ?? null, 'role' => $type['role'] ?? (string) $key];
            }

            try {
                $result = (new self($building))->call('build', [
                    'seeders' => array_values((array) config('video-demo.sample_data.seeders', [])),
                    'types' => $types,
                ]);
                if (! ($result['ok'] ?? false)) {
                    throw new RuntimeException($result['error'] ?? 'The sample database could not be built.');
                }
            } catch (RuntimeException $e) {
                File::delete(File::glob($building.'*'));

                throw $e;
            }

            // Older bases, from before a migration or seeder changed, are no longer used.
            File::delete(array_filter(File::glob(static::dir().'/base-*'), fn ($f) => ! str_starts_with($f, $building)));
            File::move($building, $path);
            File::delete(File::glob($building.'*'));
            $info = [...array_intersect_key($result, array_flip(['seeded', 'warnings', 'users', 'tables'])), 'built_at' => now()->toIso8601String()];
            File::put("$path.json", json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ['path' => $path, 'info' => $info, 'built' => true];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Get a short hash of what the base database is built from: the
     * migrations, seeders and factories, the seeders to run and the user
     * types, so a change to any of them builds it again.
     */
    public static function fingerprint(): string
    {
        $parts = [json_encode(config('video-demo.sample_data.seeders')), json_encode(array_keys(RecordAs::types()))];
        $folders = [...app('migrator')->paths(), database_path('migrations'), database_path('seeders'), database_path('factories')];

        foreach (array_unique($folders) as $folder) {
            if (! is_dir($folder)) {
                continue;
            }
            foreach (File::allFiles($folder) as $file) {
                $parts[] = $file->getRealPath().':'.$file->getSize().':'.$file->getMTime();
            }
        }
        sort($parts);

        return substr(sha1(implode("\n", $parts)), 0, 12);
    }

    /**
     * Set up the user to record as, finding or making one in this copy.
     *
     * @return array{user: ?array<string, mixed>, tables: array<string, int>, seeded: list<string>, warnings: list<string>}
     *
     * @throws RuntimeException
     */
    public function prepare(?string $recordAs): array
    {
        $type = null;
        if (str_starts_with((string) $recordAs, RecordAs::TYPE)) {
            $key = substr($recordAs, strlen(RecordAs::TYPE));
            $found = RecordAs::types()[$key] ?? null;
            $type = ['key' => $key, 'label' => $found['label'] ?? Str::headline($key), 'via' => $found['via'] ?? null, 'role' => $found['role'] ?? $key];
        }

        $result = $this->call('prepare', [
            'record_as' => $recordAs,
            'type' => $type,
            'login_as' => config('video-demo.recorder.login_as'),
        ]);

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException($result['error'] ?? 'The sample database could not be made.');
        }

        return $this->info = [...$this->info, 'user' => $result['user'] ?? null, 'tables' => $result['tables'] ?? []];
    }

    /**
     * Add records to the database.
     *
     * Each record names a model or a table, how many to make, its values,
     * and a ref that later values can point at, such as "@ticket".
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public function seed(array $records): array
    {
        return $this->call('seed', ['records' => $records])['results'] ?? [];
    }

    /**
     * Read rows from a table, or list the tables when none is given.
     *
     * @param  array<string, mixed>  $where
     * @return array<string, mixed>
     */
    public function query(string $table = '', array $where = [], int $limit = 10): array
    {
        return $this->call('query', ['table' => $table, 'where' => $where, 'limit' => $limit]);
    }

    /**
     * Get the id of the user the recorder signs in as, or null for a guest.
     */
    public function userId(): int|string|null
    {
        return $this->info['user']['id'] ?? null;
    }

    /**
     * Get the cookie that sends the recorder's requests to this database.
     *
     * @return array{name: string, value: string}
     */
    public function cookie(): array
    {
        $name = basename($this->path);

        return ['name' => self::COOKIE, 'value' => $name.'.'.static::sign($name)];
    }

    /**
     * Delete the database and its files.
     */
    public function delete(): void
    {
        File::delete(File::glob($this->path.'*'));
    }

    /**
     * Run an action of the video-demo:sample-data command in a PHP process
     * that uses this database.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function call(string $action, array $input): array
    {
        $file = $this->path.'.'.$action.'.json';
        File::put($file, json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $process = Process::timeout((int) config('video-demo.sample_data.timeout', 900))
                ->path(base_path())
                ->env([self::ENV => $this->path])
                ->run([(new PhpExecutableFinder)->find(false) ?: 'php', config('video-demo.sample_data.artisan') ?: base_path('artisan'), 'video-demo:sample-data', $action, $file]);

            $output = File::exists("$file.out") ? json_decode(File::get("$file.out"), true) : null;
        } finally {
            File::delete([$file, "$file.out"]);
        }

        if (! is_array($output)) {
            $said = trim($process->errorOutput() ?: $process->output());

            throw new RuntimeException('The sample database could not be made'.($said !== '' ? ': '.Str::limit($said, 600) : '.'));
        }

        return $output;
    }

    /**
     * Point this PHP process at a sandbox, when it is one the package made.
     *
     * Called while the app registers its services, before anything reads
     * the database: from the environment in the sample-data command, and
     * from the recorder's cookie on web requests while the studio is enabled.
     */
    public static function activateFor(Application $app): void
    {
        if ($app->runningInConsole()) {
            // Only the package sets this, for a process it starts.
            $path = getenv(self::ENV);
            if (is_string($path) && static::isName(basename($path)) && is_file($path)) {
                static::activate($path);
            }

            return;
        }

        if (! $app->bound('request') || ! VideoDemoServiceProvider::studioEnabled()) {
            return;
        }

        $value = $app['request']->cookies->get(self::COOKIE);
        if (is_string($value) && ($path = static::fromCookie($value))) {
            static::activate($path);
        }
    }

    /**
     * Get the database a cookie points at, or null when the cookie is not
     * one the package signed or its database is gone.
     */
    public static function fromCookie(string $value): ?string
    {
        $dot = strrpos($value, '.');
        if ($dot === false) {
            return null;
        }

        $name = substr($value, 0, $dot);

        return hash_equals(static::sign($name), substr($value, $dot + 1)) ? static::fromName($name) : null;
    }

    /**
     * Switch the default database to the sandbox, with the other services
     * that would reach outside it made local: an in-memory cache, jobs run
     * straight away, and mail and broadcasts kept from going out.
     */
    public static function activate(string $path): void
    {
        $sandbox = [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ];

        // Every connection points at the sandbox, so code that names one
        // (Telescope's migrations use "sqlite" or "mysql", say) never
        // reaches the app's own database either.
        $connections = array_map(fn () => $sandbox, (array) config('database.connections', []));

        config([
            'database.connections' => [...$connections, self::CONNECTION => $sandbox],
            'database.default' => self::CONNECTION,
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'broadcasting.default' => 'null',
        ]);

        if (config('session.driver') === 'database') {
            config(['session.connection' => self::CONNECTION]);
        }
    }

    /**
     * Get the folder sandbox databases are kept in, on the work disk.
     */
    public static function dir(): string
    {
        $disk = config('video-demo.storage.work_disk');

        return rtrim((string) config("filesystems.disks.$disk.root"), '/').'/'.trim((string) config('video-demo.storage.work_path'), '/').'/sandbox';
    }

    /**
     * Get the path of a sandbox database from its file name, or null when
     * the name isn't one the package makes or the file doesn't exist. A
     * video's copy, or a base database while it is built; never a finished
     * base, which stays as it was built.
     */
    protected static function fromName(string $name): ?string
    {
        if (! static::isName($name)) {
            return null;
        }

        $path = static::dir().'/'.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * Determine if a file name is one the package gives a sandbox database.
     */
    protected static function isName(string $name): bool
    {
        return (bool) preg_match('/^(\d+-[a-z0-9]{12}|base-[a-f0-9]{12}-building)\.sqlite$/', $name);
    }

    /**
     * Sign a database name with the app's key.
     */
    protected static function sign(string $name): string
    {
        return hash_hmac('sha256', $name, (string) config('app.key'));
    }
}
