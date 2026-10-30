<?php

namespace App\Http\Controllers;

use App\Services\FcmConfiguration;
use Illuminate\Http\Response;

class FcmWorkerController extends Controller
{
    public function __invoke(FcmConfiguration $configuration): Response
    {
        $script = '// Firebase is disabled or the worker has not been built.';
        if ($configuration->webEnabled() && is_file(public_path('build/push/firebase-messaging.js'))) {
            $config = json_encode($configuration->publicConfig(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
            $script = 'self.schoolFirebaseConfig = '.$config.";\nimportScripts('/build/push/firebase-messaging.js');";
        }

        return new Response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
