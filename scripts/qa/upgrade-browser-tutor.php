<?php

// Only upgrades the existing disposable QA_BROWSER_RUN SQLite fixture.
// The shared bootstrap excludes real .env, rejects other paths and disables HTTP.
require __DIR__.'/browser-bootstrap.php';

if (Illuminate\Support\Facades\Artisan::call('migrate', [
    '--path' => 'database/migrations/2026_11_08_000002_add_teacher_owner_to_tutor_conversations.php',
    '--force' => true,
    '--no-interaction' => true,
]) !== 0) {
    throw new RuntimeException('QA tutor fixture upgrade failed.');
}
echo "Disposable QA tutor schema is ready.\n";
