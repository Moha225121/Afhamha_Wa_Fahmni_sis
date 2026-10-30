<?php

use App\Services\FcmConfiguration;
use App\Services\FcmReadiness;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Arr;

// Intentionally bypass the Artisan kernel and provider bootstrap: they can regenerate
// package/services manifests before a command runs. This entrypoint only loads config.
$json = in_array('--json', $argv, true);
$bufferLevel = ob_get_level();
set_error_handler(static function (int $severity): bool {
    if (! (error_reporting() & $severity)) {
        // Dotenv intentionally suppresses missing-file warnings during safeLoad().
        return true;
    }
    throw new RuntimeException('Diagnostic failed without disclosing details.');
});
ob_start();
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $environment = new class extends LoadEnvironmentVariables
    {
        protected function writeErrorAndDie(InvalidFileException $e)
        {
            // Laravel's default handler prints the malformed .env line, possibly a secret.
            throw new RuntimeException('Diagnostic failed without disclosing details.');
        }
    };
    $environment->bootstrap($app);
    (new LoadConfiguration)->bootstrap($app);
    $report = (new FcmReadiness)->report(new FcmConfiguration);
    ob_end_clean();
    restore_error_handler();
    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        echo 'Read-only local report; permissions apply only to this CLI process. Exit 0 means the report completed, not that production is ready.'.PHP_EOL;
        foreach (Arr::dot($report) as $check => $status) {
            echo $check.': '.(is_bool($status) ? ($status ? 'yes' : 'no') : ($status ?? 'not_checked')).PHP_EOL;
        }
    }
} catch (Throwable) {
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    restore_error_handler();
    echo $json ? '{"diagnostic":"failed_without_disclosing_details"}'.PHP_EOL : 'Diagnostic failed without disclosing details.'.PHP_EOL;
    exit(1);
}
