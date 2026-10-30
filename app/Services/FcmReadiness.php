<?php

namespace App\Services;

use App\Http\Controllers\FcmWorkerController;
use Throwable;

/** Local, read-only checks. Never resolve Firebase, cache, queue, or database clients. */
class FcmReadiness
{
    public function report(FcmConfiguration $configuration): array
    {
        $project = config('firebase.projects.'.config('firebase.default'), []);
        $browserProject = config('fcm.web.projectId');
        $browser = [];
        foreach (['apiKey', 'projectId', 'appId', 'messagingSenderId'] as $field) {
            $browser[$field.'_present'] = filled(config('fcm.web.'.$field));
        }
        $browser['vapid_key_present'] = filled(config('fcm.vapid_key'));
        $browser['optional_auth_domain_present'] = filled(config('fcm.web.authDomain'));

        $worker = (new FcmWorkerController)($configuration)->getContent();
        $databaseQueue = config('queue.connections.database', []);
        $applicationDatabase = config('database.default');
        $queueDatabase = ($databaseQueue['connection'] ?? null) ?: $applicationDatabase;
        $batchingDatabase = config('queue.batching.database') ?: $applicationDatabase;
        $defaultQueue = config('queue.connections.'.config('queue.default'), []);

        return [
            'scope' => 'current_cli_process_only',
            'configuration' => [
                'cached' => app()->configurationIsCached(),
                'fcm_flag_enabled' => (bool) config('fcm.enabled'),
                'server_sending_enabled' => $configuration->enabled(),
                'browser_registration_enabled' => $configuration->webEnabled(),
                'app_key_present' => filled(config('app.key')),
            ],
            'browser' => $browser,
            'credentials' => $this->credentials($project['credentials'] ?? null, $browserProject),
            'sending_project' => [
                // AppServiceProvider applies this override before the SDK reads credentials.
                'browser_alignment' => filled($browserProject) ? 'matches_by_application_override' : 'unverified_browser_project_missing',
                'provider_authentication_and_iam' => 'not_tested',
            ],
            'assets' => $this->assets(),
            'generated_worker' => [
                'imports_messaging_bundle' => str_contains($worker, "importScripts('/build/push/firebase-messaging.js')"),
                'supplies_firebase_configuration' => str_contains($worker, 'self.schoolFirebaseConfig = '),
                'https_response_and_browser_execution' => 'not_tested',
            ],
            'queue' => [
                'default_connection_configured' => ! empty($defaultQueue),
                'default_driver' => $this->driver($defaultQueue['driver'] ?? null, ['sync', 'database', 'redis', 'sqs', 'beanstalkd', 'deferred', 'background', 'failover', 'null']),
                // Teacher and conversation jobs explicitly override QUEUE_CONNECTION.
                'required_connection' => 'database',
                'required_queue' => 'notifications',
                'required_connection_uses_database_driver' => ($databaseQueue['driver'] ?? null) === 'database',
                'jobs_table_configured' => filled($databaseQueue['table'] ?? null),
                'database_connection_configured' => filled(config('database.connections.'.$queueDatabase.'.driver')),
                'database_connection_matches_application' => $queueDatabase === $applicationDatabase,
                'retry_after_exceeds_job_timeout' => is_numeric($databaseQueue['retry_after'] ?? null) && $databaseQueue['retry_after'] > 60,
                'batching_database_configured' => filled(config('database.connections.'.$batchingDatabase.'.driver')),
                'batching_database_matches_application' => $batchingDatabase === $applicationDatabase,
                'batching_table_configured' => filled(config('queue.batching.table')),
                'failed_job_storage_configured' => filled(config('queue.failed.driver')) && config('queue.failed.driver') !== 'null',
                'database_schema_and_worker_consumption' => 'not_tested',
            ],
            'cache' => [
                'application' => $this->cache(config('cache.default')),
                'firebase' => $this->cache($project['cache_store'] ?? null),
                'backend_connectivity_and_schema' => 'not_tested',
            ],
            'directories' => [
                'bootstrap_cache' => $this->directory(base_path('bootstrap/cache')),
                'storage_cache' => $this->directory(storage_path('framework/cache/data')),
                'storage_views' => $this->directory(storage_path('framework/views')),
                'storage_logs' => $this->directory(storage_path('logs')),
            ],
            'legacy_web_push' => [
                'subject_present' => filled(config('services.web_push.subject')),
                'public_key_present' => filled(config('services.web_push.public_key')),
                'private_key_present' => filled(config('services.web_push.private_key')),
                'firebase_key_pair_compatibility' => 'not_tested',
            ],
            'limitations' => [
                'permissions' => 'cli_identity_only_no_write_probes',
                'php_fpm_and_running_queue_processes' => 'not_tested',
                'credentials' => 'metadata_only_no_key_validation_or_authentication',
                'production_layout_and_https_assets' => 'not_tested',
                'api_access_and_device_delivery' => 'not_tested',
                'build_integrity' => 'run_node_scripts_verify_push_build_mjs',
            ],
        ];
    }

    private function credentials(mixed $credentials, mixed $browserProject): array
    {
        $credentials = is_array($credentials) ? ($credentials['file'] ?? $credentials) : $credentials;
        $result = [
            'selection' => $credentials ? 'effective_laravel_configuration' : 'application_default_credentials_not_inspected',
            'environment_precedence' => 'FIREBASE_CREDENTIALS_then_GOOGLE_APPLICATION_CREDENTIALS_then_ADC',
            'original_environment_source' => 'not_inferable_from_effective_config',
            'format' => 'not_inspected',
            'file_exists' => null,
            'file_readable_by_cli' => null,
            'json_valid' => null,
            'service_account_fields_present' => null,
            'project_alignment' => 'unverified',
        ];
        if (! $credentials) {
            // Resolving ADC can contact metadata servers. Never do that in this diagnostic.
            return $result;
        }

        $json = null;
        if (is_array($credentials)) {
            $result['format'] = 'inline_array';
            $json = $credentials;
        } elseif (is_string($credentials) && str_starts_with($credentials, '{')) {
            $result['format'] = 'inline_json';
            $json = json_decode($credentials, true);
        } elseif (is_string($credentials) && $this->localPath($credentials)) {
            $result['format'] = 'local_file';
            // Match FirebaseProjectManager::resolveJsonCredentials, including relative paths.
            $path = str_starts_with($credentials, '/') || str_contains($credentials, ':\\')
                ? $credentials : base_path($credentials);
            $result['file_exists'] = @is_file($path);
            $result['file_readable_by_cli'] = $result['file_exists'] && @is_readable($path);
            if (! $result['file_readable_by_cli']) {
                return $result;
            }
            $json = $this->readJson($path);
        } else {
            $result['format'] = 'unsupported_not_inspected';

            return $result;
        }

        $result['json_valid'] = is_array($json);
        $result['service_account_fields_present'] = is_array($json)
            && ($json['type'] ?? null) === 'service_account'
            && filled($json['client_email'] ?? null) && filled($json['private_key'] ?? null)
            && filled($json['project_id'] ?? null);
        if (is_string($json['project_id'] ?? null) && filled($browserProject)) {
            // Different credential projects can work with cross-project IAM; do not claim failure.
            $result['project_alignment'] = $json['project_id'] === $browserProject ? 'matches' : 'differs_review_cross_project_iam';
        }

        return $result;
    }

    private function assets(): array
    {
        $manifest = $this->readJson(public_path('build/manifest.json'));
        $entry = $manifest['resources/js/app.js']['file'] ?? null;
        $complete = is_array($manifest) && ! empty($manifest);
        foreach ($manifest ?? [] as $item) {
            if (! is_array($item) || ! $this->buildAsset($item['file'] ?? null)) {
                $complete = false;

                continue;
            }
            foreach (['css', 'assets'] as $list) {
                if (isset($item[$list]) && ! is_array($item[$list])) {
                    $complete = false;

                    continue;
                }
                foreach ($item[$list] ?? [] as $file) {
                    $complete = $this->buildAsset($file) && $complete;
                }
            }
            foreach (['imports', 'dynamicImports'] as $list) {
                if (isset($item[$list]) && ! is_array($item[$list])) {
                    $complete = false;

                    continue;
                }
                foreach ($item[$list] ?? [] as $dependency) {
                    $complete = is_string($dependency) && isset($manifest[$dependency]) && $complete;
                }
            }
        }

        return [
            'manifest_valid' => is_array($manifest),
            'manifest_app_entry_nonempty' => $this->buildAsset($entry),
            'manifest_assets_nonempty_and_dependencies_present' => $complete,
            'messaging_bundle_nonempty' => $this->nonemptyFile(public_path('build/push/firebase-messaging.js')),
            'build_inventory_nonempty' => $this->nonemptyFile(public_path('build/push-build.json')),
            'app_worker_nonempty' => $this->nonemptyFile(public_path('app-sw.js')),
            'parent_worker_nonempty' => $this->nonemptyFile(public_path('parent-sw.js')),
            'display_helper_nonempty' => $this->nonemptyFile(public_path('js/push-notification-display.js')),
            'activation_helper_nonempty' => $this->nonemptyFile(public_path('js/push-activation-status.js')),
            'push_styles_nonempty' => $this->nonemptyFile(public_path('css/push-notifications.css')),
            'vite_hot_file_absent' => ! file_exists(public_path('hot')),
        ];
    }

    private function buildAsset(mixed $file): bool
    {
        return is_string($file) && $file !== '' && ! str_starts_with($file, '/')
            && ! str_contains($file, ':') && ! str_contains($file, '\\') && ! str_contains($file, "\0")
            && ! preg_match('~(^|/)\.{1,2}(/|$)~', $file)
            && $this->nonemptyFile(public_path('build/'.$file));
    }

    private function nonemptyFile(string $path): bool
    {
        return @is_file($path) && @is_readable($path) && @filesize($path) > 0;
    }

    private function localPath(string $path): bool
    {
        return $path !== '' && ! str_contains($path, "\0") && ! str_contains($path, '://')
            && ! str_starts_with($path, '//') && ! str_starts_with($path, '\\\\');
    }

    private function readJson(string $path): ?array
    {
        if (! $this->nonemptyFile($path) || @filesize($path) > 1048576) {
            return null;
        }
        try {
            $contents = @file_get_contents($path);
            $decoded = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            // Never expose malformed credential contents, paths, or PHP warning text.
            return null;
        }
    }

    private function cache(mixed $store): array
    {
        $config = is_string($store) ? config('cache.stores.'.$store, []) : [];
        $result = [
            'store_configured' => ! empty($config),
            'driver' => $this->driver($config['driver'] ?? null, ['array', 'database', 'file', 'redis', 'memcached', 'dynamodb', 'storage', 'octane', 'session', 'failover', 'null']),
            'shared_persistence' => in_array($config['driver'] ?? null, ['array', 'null'], true) ? 'not_persistent' : 'not_tested',
        ];
        if (($config['driver'] ?? null) === 'file') {
            $result['directory'] = $this->directory($config['path'] ?? null);
            $result['lock_directory'] = $this->directory($config['lock_path'] ?? $config['path'] ?? null);
        }

        return $result;
    }

    private function directory(mixed $path): array
    {
        $local = is_string($path) && $this->localPath($path);

        return [
            'exists' => $local && @is_dir($path),
            'readable_by_cli' => $local && @is_dir($path) && @is_readable($path),
            'writable_by_cli' => $local && @is_dir($path) && @is_writable($path),
        ];
    }

    private function driver(mixed $driver, array $allowed): string
    {
        return in_array($driver, $allowed, true) ? $driver : 'other_or_missing';
    }
}
