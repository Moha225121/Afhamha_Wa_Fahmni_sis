<?php

$app = require __DIR__.'/browser-bootstrap.php';
$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$announcement = $id ? App\Models\Announcement::whereKey($id)->where('title', 'like', 'إعلان توقيت الجولة الثانية %')->first() : null;
if (! $announcement) {
    throw new RuntimeException('A synthetic Round Two announcement is required.');
}
$count = fn () => Illuminate\Support\Facades\DB::table('notifications')
    ->where(App\Support\NotificationData::field('announcement_id'), App\Support\NotificationData::id($id))->count();
$result = [
    'serverUTC' => now()->toIso8601String(),
    'createdUTC' => $announcement->created_at->toIso8601String(),
    'publishedUTC' => $announcement->published_at?->toIso8601String(),
    'status' => $announcement->status,
    'currentlyPublished' => App\Models\Announcement::currentlyPublished()->whereKey($id)->exists(),
    'before' => $count(),
];
if (($argv[2] ?? 'notify') === 'inspect') {
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
}
foreach (['first', 'repeated'] as $phase) {
    if (Illuminate\Support\Facades\Artisan::call('announcements:notify-due') !== 0) throw new RuntimeException('Synthetic scheduler failed.');
    $result[$phase] = $count();
}
echo json_encode($result, JSON_THROW_ON_ERROR);
