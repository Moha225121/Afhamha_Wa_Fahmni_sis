<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminPublicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PublicationController extends Controller
{
    public function index(Request $request, AdminPublicationService $publications)
    {
        $publication = $publications->index($this->filters($request));
        $publication['activities']->withPath(route('admin.publications.index'));

        return response()->view('admin.publications.index', compact('publication'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(Request $request, AdminPublicationService $publications)
    {
        $knownVersion = $request->validate(['version' => ['nullable', 'string', 'max:128']])['version'] ?? '';
        $publication = $publications->index($this->filters($request));
        $publication['activities']->withPath(route('admin.publications.index'));
        $changed = ! hash_equals($publication['fingerprint'], $knownVersion);

        return response()->json([
            'version' => $publication['fingerprint'],
            'changed' => $changed,
            'html' => $changed ? view('admin.publications._results', compact('publication'))->render() : null,
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $kind, int $id, AdminPublicationService $publications)
    {
        $post = $publications->detail($kind, $id);
        abort_if($post === null, 404);
        // Hash actual detail data, so even an edit within the same second is noticed.
        $post['fingerprint'] = hash('sha256', json_encode($post, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        if ($request->expectsJson()) {
            return response()->json(['version' => $post['fingerprint'], 'checked_at' => now()->toIso8601String()])
                ->header('Cache-Control', 'private, no-store');
        }
        $backUrl = route('admin.publications.index', $this->filters($request));

        return response()->view('admin.publications.show', compact('post', 'backUrl'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, string $kind, int $id, string $attachment, AdminPublicationService $publications)
    {
        $post = $publications->detail($kind, $id);
        abort_if($post === null, 404);
        $file = collect($post['attachments'] ?? [])->first(fn ($item) => (string) $item['id'] === $attachment);
        abort_unless($file, 404);
        $disk = $file['disk'] ?? 'local';
        $relative = $file['path'] ?? '';
        abort_unless(in_array($disk, ['local', 'public'], true)
            && is_string($relative) && $relative !== ''
            && ! preg_match('~[\\\\:\x00]|^/|(?:^|/)\.{1,2}(?:/|$)~', $relative), 404);
        $storage = Storage::disk($disk);
        $root = realpath($storage->path(''));
        $path = realpath($storage->path($relative));
        abort_unless($root && $path && is_file($path) && is_readable($path), 404);
        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalized = str_replace('\\', '/', $path);
        abort_unless(str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized,
            PHP_OS_FAMILY === 'Windows' ? strtolower($rootPrefix) : $rootPrefix), 404);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $inline = ! $request->boolean('download') && in_array($mime, [
            'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'video/mp4', 'audio/mpeg', 'audio/mp4', 'audio/ogg',
        ], true);
        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'file';

        if ($mime === 'application/pdf' && ! $request->boolean('download') && $request->boolean('preview')) {
            $fileUrl = route('admin.publications.download', compact('kind', 'id', 'attachment'));

            return response()->view('admin.publications.attachment', compact('fileUrl', 'name'))
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? 'inline' : 'attachment', $name, 'file'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => $inline ? "frame-ancestors 'self'" : "default-src 'none'; sandbox",
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'type' => ['nullable', Rule::in(['all', 'lesson', 'assignment', 'grade', 'exam'])],
            'status' => ['nullable', Rule::in(['published', 'scheduled', 'draft', 'archived', 'all'])],
            'teacher_id' => ['nullable', 'integer', 'min:1', Rule::exists('teachers', 'id')],
            'subject_id' => ['nullable', 'integer', 'min:1', Rule::exists('subjects', 'id')],
            'classroom_id' => ['nullable', 'integer', 'min:1', Rule::exists('classrooms', 'id')->whereNull('deleted_at')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'view' => ['nullable', Rule::in(['classes', 'feed'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
    }
}
