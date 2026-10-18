<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ogunsakin01\VideoDemo\Ai\AccessRules;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\SampleData\Builder;
use Ogunsakin01\VideoDemo\SampleData\Filler;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;
use Ogunsakin01\VideoDemo\VideoDemo;

/**
 * Recording on sample data: the sandbox database, the cookie that sends the
 * recorder's requests to it, filling it, and the planner's tools for it.
 */
class SampleDataTest extends TestCase
{
    use FakesAnthropic;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-sandbox-'.uniqid();
        config(['filesystems.disks.local.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_the_cookie_only_opens_databases_the_package_made(): void
    {
        File::ensureDirectoryExists(Sandbox::dir());
        $path = Sandbox::dir().'/5-abcdefghijkl.sqlite';
        File::put($path, '');
        $cookie = (new Sandbox($path))->cookie();

        $this->assertSame(Sandbox::COOKIE, $cookie['name']);
        $this->assertSame($path, Sandbox::fromCookie($cookie['value']));

        // Tampered with, not made by the package, a finished base database, or gone.
        $this->assertNull(Sandbox::fromCookie($cookie['value'].'0'));
        $this->assertNull(Sandbox::fromCookie('5-abcdefghijkl.sqlite.'.str_repeat('0', 64)));
        $this->assertNull(Sandbox::fromCookie((new Sandbox(Sandbox::dir().'/../../.env'))->cookie()['value']));
        File::put(Sandbox::dir().'/base-0123456789ab.sqlite', '');
        $this->assertNull(Sandbox::fromCookie((new Sandbox(Sandbox::dir().'/base-0123456789ab.sqlite'))->cookie()['value']));
        File::delete($path);
        $this->assertNull(Sandbox::fromCookie($cookie['value']));
    }

    public function test_activating_points_every_connection_at_the_sandbox(): void
    {
        $before = config()->all();
        $this->beforeApplicationDestroyed(fn () => config($before));
        config(['database.connections.mysql' => ['driver' => 'mysql', 'database' => 'real'], 'session.driver' => 'database']);

        Sandbox::activate('/tmp/5-abcdefghijkl.sqlite');

        $this->assertSame(Sandbox::CONNECTION, config('database.default'));
        // Code that names a connection, as Telescope's migrations do, reaches the sandbox too.
        $this->assertSame('/tmp/5-abcdefghijkl.sqlite', config('database.connections.mysql.database'));
        $this->assertSame('sqlite', config('database.connections.mysql.driver'));
        $this->assertSame(['array', 'sync', 'array', 'null', Sandbox::CONNECTION], [
            config('cache.default'), config('queue.default'), config('mail.default'), config('broadcasting.default'), config('session.connection'),
        ]);
    }

    public function test_required_columns_get_sensible_values(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->enum('status', ['open', 'closed']);
            $table->string('email');
            $table->string('password');
            $table->string('title');
            $table->timestamp('due_at');
            $table->integer('views');
            $table->json('meta');
            $table->string('note')->nullable();
            $table->string('kind')->default('bug');
        });

        // The model's casts say which text columns hold JSON.
        $values = (new Filler)->missing('tickets', ['title' => 'Given'], ['meta' => 'array']);

        $this->assertSame(['team_id', 'status', 'email', 'password', 'due_at', 'views', 'meta'], array_keys($values));
        $this->assertSame('open', $values['status']);
        // A foreign key points at a real row, made in the parent table when it has none.
        $this->assertSame(DB::table('teams')->value('id'), $values['team_id']);
        $this->assertStringEndsWith('@example.com', $values['email']);
        $this->assertTrue(Hash::check('password', $values['password']));
        $this->assertSame([0, '[]'], [$values['views'], $values['meta']]);
        DB::table('tickets')->insert([...$values, 'title' => 'Given']);
    }

    public function test_records_are_added_updated_and_read(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('subject');
            $table->string('status')->default('open');
            $table->timestamp('due_at')->nullable();
            $table->string('secret_token')->nullable();
        });
        File::ensureDirectoryExists($this->root);
        config(['database.connections.'.Sandbox::CONNECTION.'.database' => $this->root.'/1-abcdefghijkl.sqlite']);
        File::put($this->root.'/1-abcdefghijkl.sqlite.refs.json', json_encode(['user' => ['ids' => [4], 'table' => 'users']]));
        $builder = new Builder;

        $result = $builder->seed(['records' => [
            ['ref' => 'ticket', 'table' => 'tickets', 'data' => ['user_id' => '@user', 'subject' => 'Passport upload stuck', 'due_at' => 'now +3 days', 'secret_token' => 'abc']],
            ['table' => 'tickets', 'data' => ['user_id' => '@user', 'colour' => 'red']],
            ['table' => 'tickets', 'data' => ['user_id' => '@nobody', 'subject' => 'x']],
            ['table' => 'tickets', 'where' => ['id' => '@ticket'], 'data' => ['status' => 'closed']],
        ]])['results'];

        $this->assertTrue($result[0]['ok']);
        $this->assertSame([1], $result[0]['ids']);
        $this->assertSame(4, (int) $result[0]['first']['user_id']);
        // Secrets are never shown back.
        $this->assertArrayNotHasKey('secret_token', $result[0]['first']);
        $this->assertSame(now()->addDays(3)->toDateString(), substr($result[0]['first']['due_at'], 0, 10));
        // A wrong column names the real ones; an unknown ref names the known ones.
        $this->assertStringContainsString('[tickets] has no column colour. Its columns: id, user_id, subject', $result[1]['error']);
        $this->assertStringContainsString('Nothing was saved as [@nobody] yet. Known: @user, @ticket', $result[2]['error']);
        // where updates the rows it matches instead of adding one.
        $this->assertTrue($result[3]['ok']);
        $this->assertSame('closed', DB::table('tickets')->value('status'));
        $this->assertSame(1, DB::table('tickets')->count());

        $read = $builder->query(['table' => 'tickets', 'where' => ['user_id' => '@user']]);
        $this->assertSame(1, $read['total']);
        $this->assertContains('subject varchar', $read['columns']);
        $this->assertSame('No rows in [tickets] match {"id":99}.', $builder->seed(['records' => [['table' => 'tickets', 'where' => ['id' => 99], 'data' => ['status' => 'x']]]])['results'][0]['error']);
    }

    public function test_the_user_a_sign_in_step_uses_can_be_given_what_pages_need(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->boolean('profile_complete')->default(false);
            $table->timestamps();
        });
        config(['auth.providers.users.model' => User::class]);
        User::forceCreate(['email' => 'admin@example.com', 'role' => 'admin']);
        User::forceCreate(['email' => 'member@example.com', 'role' => 'member']);
        File::ensureDirectoryExists($this->root);
        config(['database.connections.'.Sandbox::CONNECTION.'.database' => $this->root.'/1-abcdefghijkl.sqlite']);
        $builder = new Builder;

        // "@user_member" is the member a sign_in step signs in as: here, its profile is finished first.
        $result = $builder->seed(['records' => [
            ['table' => 'users', 'where' => ['id' => '@user_member'], 'data' => ['profile_complete' => true]],
            ['table' => 'users', 'where' => ['id' => '@user_owner'], 'data' => ['profile_complete' => true]],
        ]])['results'];
        $this->assertTrue($result[0]['ok'], (string) ($result[0]['error'] ?? ''));
        $this->assertTrue((bool) User::where('email', 'member@example.com')->value('profile_complete'));
        $this->assertStringContainsString('There is no type of user [owner]. Types: admin, member', $result[1]['error']);

        // The recorder signs in as that same user.
        $this->assertSame(['ok' => true, 'id' => 2], $builder->signInUser(['record_as' => 'type:member', 'type' => ['key' => 'member', 'label' => 'Member', 'via' => 'column:role', 'role' => 'member']]));
    }

    public function test_a_base_database_is_built_once_and_copied_for_each_video(): void
    {
        $package = realpath(__DIR__.'/..');
        // Symfony Process passes on only variables also in $_SERVER or $_ENV.
        putenv("TESTBENCH_WORKING_PATH=$package");
        $_SERVER['TESTBENCH_WORKING_PATH'] = $_ENV['TESTBENCH_WORKING_PATH'] = $package;
        config([
            'video-demo.sample_data.artisan' => "$package/vendor/bin/testbench",
            'video-demo.sample_data.seeders' => [Seeders\SampleUsersSeeder::class, 'Missing\\Seeder'],
            'video-demo.recorder.login_as' => 'demo@example.com',
        ]);

        try {
            $base = Sandbox::base();
            $this->assertTrue($base['built']);
            $this->assertFileExists($base['path']);
            $this->assertSame(Sandbox::dir().'/base-'.Sandbox::fingerprint().'.sqlite', $base['path']);
            // Seeders that don't exist are skipped.
            $this->assertSame(['SampleUsersSeeder'], $base['info']['seeded']);
            // The second time it is reused.
            $this->assertFalse(Sandbox::base()['built']);

            $video = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Queued]);
            $sandbox = Sandbox::create($video);
            $this->assertNotSame($base['path'], $sandbox->path);

            // The default user doesn't exist in the sample data, so it is made.
            $info = $sandbox->prepare(null);
            $this->assertSame('demo@example.com', $info['user']['email']);
            $this->assertSame($info['user']['id'], $sandbox->userId());

            $saved = $sandbox->seed([['table' => 'users', 'ref' => 'teammate', 'data' => ['email' => 'sam@example.com', 'name' => 'Sam']]]);
            $this->assertTrue($saved[0]['ok'], json_encode($saved));
            $this->assertSame(3, $sandbox->query('users')['total']);

            // Only the video's copy changed; the app's own database has neither user.
            $this->assertFalse(Schema::hasTable('users') && DB::table('users')->where('email', 'sam@example.com')->exists());
            $sandbox->delete();
            $this->assertFileDoesNotExist($sandbox->path);
            $this->assertFileExists($base['path']);
        } finally {
            putenv('TESTBENCH_WORKING_PATH');
            unset($_SERVER['TESTBENCH_WORKING_PATH'], $_ENV['TESTBENCH_WORKING_PATH']);
        }
    }

    public function test_the_planner_adds_sample_data_and_hears_about_failing_pages(): void
    {
        $this->startFakeAnthropic();
        $this->app->instance(CodeTools::class, new CodeTools(sys_get_temp_dir()));
        Route::get('/deadlines', fn () => 'ok')->middleware(['web', 'auth', 'permission:view deadlines']);

        $sandbox = new class('/tmp/1-abcdefghijkl.sqlite') extends Sandbox
        {
            public array $sent = [];

            public function seed(array $records): array
            {
                $this->sent = $records;

                return [
                    ['ok' => true, 'what' => 'SupportTicket', 'ref' => 'ticket', 'ids' => [7], 'first' => ['id' => 7, 'subject' => 'Passport upload stuck']],
                    ['ok' => true, 'what' => 'User', 'ref' => null, 'ids' => [4], 'first' => null],
                    ['ok' => false, 'what' => 'Nope', 'error' => 'There is no model [Nope].'],
                ];
            }

            public function query(string $table = '', array $where = [], int $limit = 10): array
            {
                return ['tables' => ['users' => 3]];
            }
        };
        $sandbox->info = [
            'user' => ['id' => 4, 'email' => 'member@example.com', 'roles' => ['member'], 'permissions' => ['view deadlines'], 'email_verified' => true],
            'tables' => ['users' => 3], 'seeded' => ['DatabaseSeeder'], 'warnings' => [],
        ];

        $record = fn ($ref, $model, $data, $where = '') => ['ref' => $ref, 'model' => $model, 'table' => '', 'count' => 1, 'data_json' => $data, 'where_json' => $where];
        $this->respond(1, [
            ['type' => 'tool_use', 'id' => 'tu_q', 'name' => 'query_data', 'input' => ['table' => '', 'where_json' => '{}', 'limit' => 10]],
            ['type' => 'tool_use', 'id' => 'tu_s', 'name' => 'seed_data', 'input' => ['records' => [
                $record('ticket', 'SupportTicket', '{"user_id": "@user", "subject": "Passport upload stuck"}'),
                $record('', 'User', '{"avatar": "/images/avatar.jpg"}', '{"id": "@user"}'),
                $record('', 'Nope', '{}'),
                $record('', 'Bad', 'not json'),
            ]]],
        ]);
        $plan = ['type' => 'tool_use', 'id' => 'tu_plan', 'name' => 'submit_plan', 'input' => ['title' => 'Tickets', 'summary' => 'x', 'steps' => [
            ['action' => 'goto', 'why' => 'x', 'url' => '/support/tickets/7', 'value' => null, 'title' => null, 'text' => null, 'ms' => null, 'chapter' => null, 'target' => null],
        ]]];
        $this->respond(2, [$plan]);
        $this->respond(3, [[...$plan, 'id' => 'tu_plan2']]);

        $video = Video::create(['prompt' => 'How a member reads a ticket', 'target_seconds' => 30, 'model' => 'claude-opus-5-5', 'effort' => 'high', 'status' => VideoStatus::Planning, 'record_as' => 'type:member', 'sample_data' => true]);

        try {
            $planner = app(Planner::class);
            $session = $planner->plan($video, $sandbox, [['path' => '/dashboard/talents', 'problem' => 'The page returned HTTP 403', 'status' => 403, 'url' => null, 'detail' => 'Add your photo to meet people here']]);

            $first = $this->request(1)['body'];
            $this->assertSame(['list_files', 'read_file', 'search_code', 'seed_data', 'query_data', 'look_at_page', 'submit_plan'], array_column($first['tools'], 'name'));
            $brief = $first['messages'][0]['content'];
            $this->assertStringContainsString('<sample_data>', $brief);
            $this->assertStringContainsString('then these seeders: DatabaseSeeder', $brief);
            $this->assertStringContainsString('user #4 (member@example.com), roles: member, permissions: view deadlines, email verified', $brief);
            $this->assertStringContainsString('Tables with rows: users 3', $brief);
            $this->assertStringContainsString('- /dashboard/talents: The page returned HTTP 403: Add your photo to meet people here', $brief);
            // Every middleware is listed, so the model sees what each page requires.
            $this->assertStringContainsString('GET /deadlines  middleware=auth,permission:view deadlines', $brief);

            // Only well-formed records went to the database, and each result came back.
            $this->assertSame(['SupportTicket', 'User', 'Nope'], array_column($sandbox->sent, 'model'));
            $this->assertSame(['id' => '@user'], $sandbox->sent[1]['where']);
            $results = collect($this->request(2)['body']['messages'][2]['content'])->keyBy('tool_use_id');
            $this->assertStringContainsString('"users": 3', $results['tu_q']['content']);
            $this->assertStringContainsString("1. Saved SupportTicket as @ticket: id 7\n", $results['tu_s']['content']);
            $this->assertStringContainsString('2. Updated User: id 4', $results['tu_s']['content']);
            $this->assertStringContainsString('3. Not saved (Nope): There is no model [Nope].', $results['tu_s']['content']);
            $this->assertStringContainsString('4. Not saved: data_json is not a JSON object.', $results['tu_s']['content']);

            // The records that saved are kept, so recording again rebuilds the same data.
            $this->assertSame(['SupportTicket', 'User'], array_column($video->fresh()->sample_records, 'model'));
            $this->assertContains('Added sample data: SupportTicket, User, Nope, Bad', array_column($video->fresh()->log, 'message'));

            // Pages that fail the check before recording go back to the model.
            $planner->repairPages($session, $video, [['path' => '/support/tickets/7', 'problem' => 'The page returned HTTP 403', 'status' => 403, 'url' => null, 'detail' => null]]);
            $last = collect($this->request(3)['body']['messages'])->last()['content'][0];
            $this->assertSame('tu_plan', $last['tool_use_id']);
            $this->assertTrue($last['is_error']);
            $this->assertStringContainsString('- /support/tickets/7: The page returned HTTP 403', $last['content']);
            $this->assertSame(1, $session->repairs);
        } finally {
            $this->stopFakeAnthropic();
        }
    }

    public function test_access_rules_list_the_apps_middleware_policies_and_gates(): void
    {
        Route::aliasMiddleware('subscribed', SampleDataTest::class);
        Route::get('/reports', fn () => 'ok')->middleware('subscribed:pro');
        Gate::policy(Video::class, VideoStyle::class);
        Gate::define('export-reports', fn () => true);

        $rules = (new AccessRules)->describe();

        $this->assertStringContainsString('- subscribed: '.SampleDataTest::class, $rules);
        $this->assertStringContainsString('- Video: '.VideoStyle::class, $rules);
        $this->assertStringContainsString('export-reports', $rules);
    }

    public function test_the_form_and_facade_record_on_sample_data(): void
    {
        Queue::fake();

        $this->post('/video-demo/studio', [
            'style_id' => VideoStyle::options()->first()->id, 'devices' => ['desktop'], 'prompt' => 'How a member reads a ticket',
            'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'visibility' => 'public', 'sample_data' => '1',
        ])->assertRedirect();
        $this->assertTrue(Video::firstOrFail()->sample_data);

        $this->get('/video-demo/studio/create')->assertSee('Sample data')->assertSee('name="sample_data" value="1"', false);
        $this->assertTrue(app(VideoDemo::class)->make('x', ['sample_data' => true])->sample_data);

        // Sample data is the default, in the shipped config and so for the form, the facade and the CLI.
        $this->assertTrue((require __DIR__.'/../config/video-demo.php')['sample_data']['default']);
        config(['video-demo.sample_data.default' => true]);
        $this->get('/video-demo/studio/create')->assertSee('name="sample_data" value="1" checked', false);
        $this->assertTrue(app(VideoDemo::class)->make('y')->sample_data);
        $this->assertFalse(app(VideoDemo::class)->make('z', ['sample_data' => false])->sample_data);
        $this->artisan('video-demo:generate', ['description' => 'How a member reads a ticket', '--queue' => true, '--json' => true])->assertSuccessful();
        $this->assertTrue(Video::latest('id')->first()->sample_data);
        $this->artisan('video-demo:generate', ['description' => 'How a member reads a ticket', '--own-data' => true, '--queue' => true, '--json' => true])->assertSuccessful();
        $this->assertFalse(Video::latest('id')->first()->sample_data);
    }

    public function test_a_video_whose_page_failed_can_try_again_on_sample_data(): void
    {
        Queue::fake();
        $video = Video::create([
            'prompt' => 'How a member finds people', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Failed, 'record_as' => 'type:member', 'error' => 'A page isn\'t working',
            'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/people']]],
            'issues' => [['path' => '/dashboard/talents', 'problem' => 'The page returned HTTP 403', 'status' => 403, 'url' => null, 'detail' => 'Add your photo to meet people here']],
        ]);

        $this->get("/video-demo/studio/{$video->id}")->assertSee('Try again on sample data')->assertSee('Your local database');

        // Switching to sample data always gets a new plan, even when asked to record the old one.
        $this->post("/video-demo/studio/{$video->id}/retry", ['mode' => 'record', 'sample_data' => '1'])->assertRedirect();
        $video->refresh();
        $this->assertTrue($video->sample_data);
        $this->assertNull($video->issues);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->replan);

        $video->update(['status' => VideoStatus::Failed]);
        $this->get("/video-demo/studio/{$video->id}")->assertDontSee('Try again on sample data')->assertSee('Sample data');

        // "Make it again" can switch it off and on.
        $form = ['prompt' => 'x', 'target_seconds' => 30, 'devices' => ['desktop'], 'model' => $video->model, 'effort' => 'high'];
        $this->post("/video-demo/studio/{$video->id}/again", [...$form, 'sample_data' => '0'])->assertRedirect();
        $this->assertFalse($video->fresh()->sample_data);
        $video->refresh()->update(['status' => VideoStatus::Failed]);
        $this->post("/video-demo/studio/{$video->id}/again", [...$form, 'sample_data' => '1'])->assertRedirect();
        $this->assertTrue($video->fresh()->sample_data);

        // And from code.
        $video->refresh()->update(['status' => VideoStatus::Failed]);
        $this->assertFalse(app(VideoDemo::class)->retry($video->fresh(), newPlan: false, sampleData: false)->sample_data);
    }

    public function test_install_sets_everything_up_in_one_command(): void
    {
        // The sample database can't be built here, which is only a warning.
        config(['video-demo.sample_data.artisan' => $this->root.'/missing-artisan']);
        $video = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Ready, 'cost' => 0.3727, 'output_tokens' => 1200]);

        try {
            $this->artisan('video-demo:install', ['--no-browser' => true])
                ->expectsOutputToContain('Running the package\'s migrations')
                ->expectsOutputToContain("Recording on sample data won't work until this is fixed")
                ->expectsOutputToContain('Run this command again after each upgrade')
                ->expectsOutputToContain('a star on GitHub helps others find it: https://github.com/ogunsakin01/laravel-video-demos')
                ->assertSuccessful();
            // The config stays in the package: a copy would keep the app on these defaults.
            $this->assertFileDoesNotExist(config_path('video-demo.php'));

            // The package's tools in storage are kept out of the app's git repository.
            $this->assertSame("*\n!.gitignore\n", File::get(storage_path('video-demo/.gitignore')));

            // Running it again, as after an upgrade, keeps what videos have cost so far.
            $this->artisan('video-demo:install', ['--no-browser' => true, '--no-sample-data' => true])->assertSuccessful();
            $this->assertSame([0.3727, 1200], [$video->fresh()->cost, $video->fresh()->output_tokens]);
        } finally {
            File::delete([config_path('video-demo.php'), storage_path('video-demo/.gitignore')]);
        }
    }

    public function test_install_removes_a_copy_of_the_config_nobody_edited(): void
    {
        // A copy an older version of install made, never edited.
        File::put(config_path('video-demo.php'), File::get(__DIR__.'/../config/video-demo.php'));

        try {
            $this->artisan('video-demo:install', ['--no-browser' => true, '--no-sample-data' => true])
                ->expectsOutputToContain('Removed config/video-demo.php: an unedited copy of the package\'s config')
                ->assertSuccessful();
            $this->assertFileDoesNotExist(config_path('video-demo.php'));

            // An edited copy is the app's, and stays.
            File::put(config_path('video-demo.php'), File::get(__DIR__.'/../config/video-demo.php')."\n// Our own change\n");
            $this->artisan('video-demo:install', ['--no-browser' => true, '--no-sample-data' => true])
                ->doesntExpectOutputToContain('Removed config/video-demo.php')
                ->assertSuccessful();
            $this->assertFileExists(config_path('video-demo.php'));
        } finally {
            File::delete([config_path('video-demo.php'), storage_path('video-demo/.gitignore')]);
        }
    }

    public function test_install_keeps_an_existing_gitignore(): void
    {
        File::ensureDirectoryExists(storage_path('video-demo'));
        File::put(storage_path('video-demo/.gitignore'), "recorder/\n");

        try {
            $this->artisan('video-demo:install', ['--no-browser' => true, '--no-sample-data' => true])->assertSuccessful();
            $this->assertSame("recorder/\n", File::get(storage_path('video-demo/.gitignore')));
        } finally {
            File::delete([config_path('video-demo.php'), storage_path('video-demo/.gitignore')]);
        }
    }

    public function test_the_base_database_is_kept_until_a_file_really_changes(): void
    {
        $folder = $this->root.'/migrations';
        File::ensureDirectoryExists($folder);
        File::put("$folder/2026_01_01_000000_create_things.php", '<?php // things');
        app('migrator')->path($folder);
        $before = Sandbox::fingerprint();

        // composer update rewrites files unchanged, with a new modification time.
        touch("$folder/2026_01_01_000000_create_things.php", time() + 3600);
        clearstatcache();
        $this->assertSame($before, Sandbox::fingerprint());

        File::put("$folder/2026_01_01_000000_create_things.php", '<?php // things, changed');
        $this->assertNotSame($before, Sandbox::fingerprint());
    }

    public function test_clean_keeps_the_current_base_and_databases_in_use(): void
    {
        File::ensureDirectoryExists(Sandbox::dir());
        $working = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Recording]);
        $done = Video::create(['prompt' => 'y', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Ready]);
        $files = [
            'base' => Sandbox::dir().'/base-'.Sandbox::fingerprint().'.sqlite',
            'old' => Sandbox::dir().'/base-0123456789ab.sqlite',
            'working' => Sandbox::dir()."/{$working->id}-abcdefghijkl.sqlite",
            'done' => Sandbox::dir()."/{$done->id}-abcdefghijkl.sqlite",
        ];
        foreach ($files as $file) {
            File::put($file, 'x');
        }

        $this->artisan('video-demo:clean')->assertSuccessful();

        $this->assertFileExists($files['base']);
        $this->assertFileExists($files['working']);
        $this->assertFileDoesNotExist($files['old']);
        $this->assertFileDoesNotExist($files['done']);
    }
}
