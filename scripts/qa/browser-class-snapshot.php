<?php

// Read only, synthetic QA database. Never load application .env or real data.
$app = require __DIR__.'/browser-bootstrap.php';
$name = $argv[1] ?? '';
if (! str_starts_with($name, 'QA-CLOSE-')) {
    throw new RuntimeException('Only synthetic QA-CLOSE- classroom names may be inspected.');
}
$rows = App\Models\Classroom::with('subjects')->where('name', $name)->orderBy('id')->get();
echo json_encode([
    'database' => config('database.default') === 'pgsql' ? 'isolated PostgreSQL 17.11 nasbia_qa_browser' : 'isolated SQLite fixture',
    'total_classrooms' => App\Models\Classroom::count(),
    'matching' => $rows->map(fn ($row) => [
        'id' => $row->id, 'name' => $row->name, 'stage' => $row->stage,
        'section' => $row->section, 'academic_year_id' => $row->academic_year_id,
        'subjects' => $row->subjects->pluck('id')->sort()->values()->all(),
    ])->all(),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
