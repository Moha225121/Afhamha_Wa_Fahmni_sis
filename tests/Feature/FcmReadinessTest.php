<?php

namespace Tests\Feature;

use App\Http\Controllers\FcmWorkerController;
use App\Services\FcmConfiguration;
use App\Services\FcmReadiness;
use Illuminate\Filesystem\Filesystem;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Symfony\Component\Process\Process;
use Tests\Concerns\ConfiguresFcm;
use Tests\TestCase;

class FcmReadinessTest extends TestCase
{
    use ConfiguresFcm;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFcm();
        $this->fixture = sys_get_temp_dir().'/school-fcm-readiness-'.bin2hex(random_bytes(8));
        mkdir($this->fixture.'/public/build/assets', 0777, true);
        mkdir($this->fixture.'/public/build/push', 0777, true);
        mkdir($this->fixture.'/public/js', 0777, true);
        mkdir($this->fixture.'/public/css', 0777, true);
        $this->app->usePublicPath($this->fixture.'/public');
        file_put_contents(public_path('build/manifest.json'), json_encode([
            'resources/js/app.js' => ['file' => 'assets/app.js'],
        ]));
        foreach (['build/assets/app.js', 'build/push/firebase-messaging.js', 'build/push-build.json', 'app-sw.js', 'parent-sw.js', 'js/push-notification-display.js', 'js/push-activation-status.js', 'css/push-notifications.css'] as $file) {
            file_put_contents(public_path($file), 'synthetic nonempty test asset');
        }
        $this->credentialFile();

        // A readiness check must not instantiate any client capable of reads, writes, or sends.
        foreach ([Messaging::class, Factory::class, 'firebase.manager', 'db', 'cache', 'queue'] as $service) {
            $this->app->bind($service, function () use ($service) {
                $this->fail('Readiness must not resolve '.$service);
            });
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->fixture);
        parent::tearDown();
    }

    public function test_read_only_report_distinguishes_local_evidence_from_unverified_runtime(): void
    {
        $before = $this->fixtureHashes();
        $report = $this->report();

        $this->assertSame('current_cli_process_only', $report['scope']);
        $this->assertTrue($report['configuration']['browser_registration_enabled']);
        $this->assertTrue($report['credentials']['file_exists']);
        $this->assertTrue($report['credentials']['file_readable_by_cli']);
        $this->assertTrue($report['credentials']['service_account_fields_present']);
        $this->assertSame('matches', $report['credentials']['project_alignment']);
        $this->assertSame('matches_by_application_override', $report['sending_project']['browser_alignment']);
        $this->assertTrue($report['assets']['manifest_app_entry_nonempty']);
        $this->assertTrue($report['assets']['manifest_assets_nonempty_and_dependencies_present']);
        $this->assertTrue($report['generated_worker']['imports_messaging_bundle']);
        $this->assertTrue($report['generated_worker']['supplies_firebase_configuration']);
        $this->assertSame('sync', $report['queue']['default_driver']);
        $this->assertSame('database', $report['queue']['required_connection']);
        $this->assertSame('notifications', $report['queue']['required_queue']);
        $this->assertSame('not_tested', $report['queue']['database_schema_and_worker_consumption']);
        $this->assertSame('not_tested', $report['limitations']['php_fpm_and_running_queue_processes']);
        $this->assertSame('metadata_only_no_key_validation_or_authentication', $report['limitations']['credentials']);
        $this->assertSame($before, $this->fixtureHashes());
    }

    public function test_report_never_discloses_values_paths_or_raw_errors(): void
    {
        config()->set('services.web_push.private_key', 'synthetic-legacy-private-secret');
        config()->set('queue.default', 'synthetic-sensitive-connection');
        config()->set('queue.connections.synthetic-sensitive-connection.driver', 'synthetic-sensitive-driver');
        $output = json_encode($this->report(), JSON_THROW_ON_ERROR);
        foreach ([$this->fixture, 'synthetic-private-key', 'private@example.invalid', 'test-project', 'public-test-key', 'public-vapid-key', 'synthetic-legacy-private-secret', 'synthetic-sensitive-connection', 'synthetic-sensitive-driver'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $output);
        }
        $this->assertStringContainsString('current_cli_process_only', $output);
    }

    public function test_queue_and_batching_must_use_application_database_with_null_fallback(): void
    {
        config()->set('database.connections.alternate', ['driver' => 'sqlite']);
        config()->set('queue.connections.database.connection', 'alternate');
        config()->set('queue.batching.database', 'alternate');
        $report = $this->report();
        $this->assertTrue($report['queue']['database_connection_configured']);
        $this->assertTrue($report['queue']['batching_database_configured']);
        $this->assertFalse($report['queue']['database_connection_matches_application']);
        $this->assertFalse($report['queue']['batching_database_matches_application']);

        config()->set('queue.connections.database.connection', null);
        config()->set('queue.batching.database', null);
        $report = $this->report();
        $this->assertTrue($report['queue']['database_connection_configured']);
        $this->assertTrue($report['queue']['batching_database_configured']);
        $this->assertTrue($report['queue']['database_connection_matches_application']);
        $this->assertTrue($report['queue']['batching_database_matches_application']);
    }

    public function test_disabled_or_incomplete_configuration_returns_a_placeholder_despite_existing_assets(): void
    {
        config()->set('fcm.enabled', false);
        $report = $this->report();
        $this->assertFalse($report['configuration']['server_sending_enabled']);
        $this->assertTrue($report['assets']['messaging_bundle_nonempty']);
        $this->assertFalse($report['generated_worker']['imports_messaging_bundle']);

        config()->set('fcm.enabled', true);
        config()->set('fcm.vapid_key', null);
        $report = $this->report();
        $this->assertTrue($report['configuration']['server_sending_enabled']);
        $this->assertFalse($report['configuration']['browser_registration_enabled']);
        $this->assertFalse($report['browser']['vapid_key_present']);
        $this->assertFalse($report['generated_worker']['imports_messaging_bundle']);
    }

    public function test_an_import_statement_does_not_make_an_empty_bundle_ready(): void
    {
        file_put_contents(public_path('build/push/firebase-messaging.js'), '');
        file_put_contents(public_path('build/assets/app.js'), '');
        clearstatcache();
        $report = $this->report();
        $this->assertTrue($report['generated_worker']['imports_messaging_bundle']);
        $this->assertFalse($report['assets']['messaging_bundle_nonempty']);
        $this->assertFalse($report['assets']['manifest_app_entry_nonempty']);
        unlink(public_path('build/push/firebase-messaging.js'));
        $this->assertFalse($this->report()['generated_worker']['imports_messaging_bundle']);
    }

    public function test_different_credential_project_requires_iam_review_without_changing_the_send_destination(): void
    {
        $this->credentialFile('other-synthetic-project');
        $report = $this->report();
        $this->assertSame('differs_review_cross_project_iam', $report['credentials']['project_alignment']);
        $this->assertSame('matches_by_application_override', $report['sending_project']['browser_alignment']);
        $this->assertSame('not_tested', $report['sending_project']['provider_authentication_and_iam']);
    }

    public function test_missing_malformed_and_adc_credentials_are_not_authenticated_or_disclosed(): void
    {
        config()->set('firebase.projects.app.credentials', $this->fixture.'/missing-private-path.json');
        $report = $this->report();
        $this->assertFalse($report['credentials']['file_exists']);
        $this->assertNull($report['credentials']['json_valid']);
        $this->assertSame('unverified', $report['credentials']['project_alignment']);

        file_put_contents($this->fixture.'/malformed.json', '{"private_key":"synthetic-private-key"');
        config()->set('firebase.projects.app.credentials', $this->fixture.'/malformed.json');
        $this->assertFalse($this->report()['credentials']['json_valid']);

        config()->set('firebase.projects.app.credentials', null);
        $report = $this->report();
        $this->assertSame('application_default_credentials_not_inspected', $report['credentials']['selection']);
        $this->assertNull($report['credentials']['file_exists']);
        config()->set('firebase.projects.app.credentials', 'https://credentials.invalid/private.json');
        $this->assertSame('unsupported_not_inspected', $this->report()['credentials']['format']);
        config()->set('firebase.projects.app.credentials', '\\\\credentials.invalid\\private.json');
        $this->assertSame('unsupported_not_inspected', $this->report()['credentials']['format']);
    }

    public function test_unsafe_or_incomplete_manifest_cannot_be_reported_as_complete(): void
    {
        foreach ([
            ['file' => '../private.json'],
            ['file' => 'assets/app.js', 'dynamicImports' => ['missing-entry']],
            ['file' => 'assets/app.js', 'css' => ['missing.css']],
        ] as $entry) {
            file_put_contents(public_path('build/manifest.json'), json_encode(['resources/js/app.js' => $entry]));
            $this->assertFalse($this->report()['assets']['manifest_assets_nonempty_and_dependencies_present']);
        }
    }

    public function test_controller_response_headers_and_body_remain_valid_without_provider_bootstrap(): void
    {
        $response = (new FcmWorkerController)(new FcmConfiguration);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/javascript; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("importScripts('/build/push/firebase-messaging.js')", $response->getContent());
    }

    public function test_standalone_command_never_creates_bootstrap_manifests_logs_or_caches(): void
    {
        $this->prepareStandaloneFixture();
        $before = $this->fixtureHashes();
        foreach ([['--json'], []] as $arguments) {
            $process = $this->standalone($arguments);
            $this->assertSame(0, $process->getExitCode(), $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
            $this->assertStringContainsString('current_cli_process_only', $process->getOutput());
            $this->assertStringNotContainsString('synthetic-private-key', $process->getOutput());
            $this->assertStringNotContainsString($this->fixture, $process->getOutput());
        }
        $this->assertSame($before, $this->fixtureHashes());
        $this->assertDirectoryDoesNotExist($this->fixture.'/bootstrap/cache');
        $this->assertDirectoryDoesNotExist($this->fixture.'/storage');
    }

    public function test_standalone_command_suppresses_malformed_environment_secret_and_error_text(): void
    {
        $this->prepareStandaloneFixture();
        file_put_contents($this->fixture.'/.env', 'PRIVATE KEY=synthetic-secret-invalid-variable-name');
        $process = $this->standalone(['--json']);
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(['diagnostic' => 'failed_without_disclosing_details'], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_standalone_command_uses_cached_configuration_instead_of_current_environment(): void
    {
        $this->prepareStandaloneFixture();
        mkdir($this->fixture.'/bootstrap/cache');
        // A malformed .env must not be read when Laravel's effective config is cached.
        file_put_contents($this->fixture.'/.env', 'PRIVATE KEY=synthetic-secret-invalid-variable-name');
        $cached = [
            'app' => ['env' => 'testing', 'timezone' => 'UTC'],
            'fcm' => ['enabled' => true, 'web' => ['projectId' => 'synthetic-cached-project']],
            'firebase' => ['default' => 'app', 'projects' => ['app' => ['credentials' => $this->fixture.'/private-credential.json']]],
        ];
        file_put_contents($this->fixture.'/bootstrap/cache/config.php', '<?php return '.var_export($cached, true).';');
        $before = $this->fixtureHashes();
        $process = $this->standalone(['--json']);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['configuration']['cached']);
        $this->assertTrue($report['configuration']['fcm_flag_enabled']);
        $this->assertTrue($report['configuration']['server_sending_enabled']);
        $this->assertFalse($report['configuration']['browser_registration_enabled']);
        $this->assertSame('effective_laravel_configuration', $report['credentials']['selection']);
        $this->assertSame($before, $this->fixtureHashes());
        $this->assertFileDoesNotExist($this->fixture.'/bootstrap/cache/packages.php');
        $this->assertFileDoesNotExist($this->fixture.'/bootstrap/cache/services.php');
        $this->assertDirectoryDoesNotExist($this->fixture.'/storage');
    }

    public function test_runtime_credential_environment_precedence_and_relative_path_resolution(): void
    {
        $this->prepareStandaloneFixture();
        file_put_contents($this->fixture.'/.env', '');
        $process = $this->standalone(['--json'], [
            'FIREBASE_CREDENTIALS' => 'private-credential.json',
            'GOOGLE_APPLICATION_CREDENTIALS' => $this->fixture.'/missing-private-path.json',
        ]);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['credentials']['file_exists']);
        $this->assertTrue($report['credentials']['service_account_fields_present']);

        $process = $this->standalone(['--json'], [
            'FIREBASE_CREDENTIALS' => '',
            'GOOGLE_APPLICATION_CREDENTIALS' => $this->fixture.'/private-credential.json',
        ]);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['credentials']['file_exists']);
        $this->assertTrue($report['credentials']['service_account_fields_present']);
        $this->assertStringNotContainsString('synthetic-private-key', $process->getOutput());
    }

    private function prepareStandaloneFixture(): void
    {
        mkdir($this->fixture.'/scripts');
        mkdir($this->fixture.'/vendor');
        mkdir($this->fixture.'/bootstrap');
        copy(base_path('scripts/fcm-readiness.php'), $this->fixture.'/scripts/fcm-readiness.php');
        file_put_contents($this->fixture.'/vendor/autoload.php', '<?php require '.var_export(base_path('vendor/autoload.php'), true).';');
        file_put_contents($this->fixture.'/bootstrap/app.php', '<?php return new Illuminate\\Foundation\\Application(dirname(__DIR__));');
        (new Filesystem)->copyDirectory(config_path(), $this->fixture.'/config');
        file_put_contents($this->fixture.'/.env', 'FIREBASE_CREDENTIALS="'.str_replace('\\', '/', $this->fixture).'/private-credential.json"'.PHP_EOL);
    }

    private function standalone(array $arguments, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file() ?: '', $this->fixture.'/scripts/fcm-readiness.php', ...$arguments], $this->fixture, array_replace([
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
            'APP_PACKAGES_CACHE' => 'bootstrap/cache/packages.php',
            'APP_SERVICES_CACHE' => 'bootstrap/cache/services.php',
            'LARAVEL_STORAGE_PATH' => $this->fixture.'/storage',
            'FIREBASE_CREDENTIALS' => false,
            'GOOGLE_APPLICATION_CREDENTIALS' => false,
        ], $environment));
        $process->run();

        return $process;
    }

    private function credentialFile(string $project = 'test-project'): void
    {
        $path = $this->fixture.'/private-credential.json';
        file_put_contents($path, json_encode(['type' => 'service_account', 'project_id' => $project, 'client_email' => 'private@example.invalid', 'private_key' => 'synthetic-private-key']));
        config()->set('firebase.projects.app.credentials', $path);
    }

    private function report(): array
    {
        return (new FcmReadiness)->report(new FcmConfiguration);
    }

    private function fixtureHashes(): array
    {
        $hashes = [];
        foreach ((new Filesystem)->allFiles($this->fixture) as $file) {
            $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }

        return $hashes;
    }
}
