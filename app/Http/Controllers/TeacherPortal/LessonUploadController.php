<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Exceptions\LessonVideoUnavailableException;
use App\Http\Controllers\Controller;
use App\Rules\LessonAttachmentSize;
use App\Services\LessonUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LessonUploadController extends Controller
{
    use Concerns\InteractsWithTeacherScope;

    public function handle(Request $request, LessonUploadService $uploads, ?string $upload = null)
    {
        $this->teacher($request);
        $stage = $request->route()->getAction('upload_stage');
        $started = microtime(true);
        try {
            $userId = $request->user()->id;
            $result = match ($stage) {
                'start' => (function () use ($request, $uploads, $userId) {
                    $name = $request->input('name');
                    $name = is_string($name) ? $name : '';
                    $data = $request->validate(['name' => 'required|string|max:255', 'size' => 'required|integer|min:1|max:'.LessonAttachmentSize::maximumForName($name), 'upload_id' => 'nullable|uuid'], ['size.max' => LessonAttachmentSize::messageForName($name)]);

                    return $uploads->start($userId, $data['name'], (int) $data['size'], $data['upload_id'] ?? null);
                })(),
                'status' => $uploads->status($upload, $userId),
                'chunk' => (function () use ($request, $uploads, $upload, $userId) {
                    $request->validate(['index' => 'required|integer|min:0', 'chunk' => 'required|file|max:'.(config('lesson_uploads.chunk_bytes') / 1024)]);

                    return $uploads->chunk($upload, $userId, $request->integer('index'), $request->file('chunk'));
                })(),
                'complete' => $uploads->complete($upload, $userId),
                'cancel' => $uploads->discard($upload, $userId),
            };

            return response()->json($result ?? ['status' => 'cancelled'], $stage === 'start' ? 201 : 200);
        } catch (\Throwable $exception) {
            $reference = (string) Str::uuid();
            $videoReason = $exception instanceof LessonVideoUnavailableException ? $exception->reason : null;
            Log::warning('lesson_upload.failed', ['upload_id' => $upload, 'user_id' => $request->user()->id, 'stage' => $stage,
                'reference' => $reference,
                'reason' => $videoReason,
                'duration_ms' => round((microtime(true) - $started) * 1000), 'exception' => $exception::class,
                'http_status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 500)]);
            $code = null;
            if ($exception instanceof QueryException) {
                $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
                $missingSqliteSchema = $sqlState === 'HY000' && (int) ($exception->errorInfo[1] ?? 0) === 1
                    && preg_match('/no such (?:table|column):|has no column named/i', $exception->getPrevious()?->getMessage() ?? '');
                $code = match ($sqlState) {
                    '42P01', '42703', '42S02', '42S22' => 'UPLOAD_DATABASE_SCHEMA',
                    '42501' => 'UPLOAD_DATABASE_PERMISSION',
                    default => $missingSqliteSchema ? 'UPLOAD_DATABASE_SCHEMA' : null,
                };
            } elseif ($exception instanceof \RuntimeException && in_array($exception->getMessage(), [
                'Unable to create lesson upload root.', 'Unable to lock lesson upload.',
                'Unable to persist complete lesson upload chunk.', 'Unable to remove lesson upload.',
            ], true)) {
                $code = 'UPLOAD_STORAGE_UNAVAILABLE';
            } elseif ($exception instanceof LessonVideoUnavailableException
                || ($stage === 'complete' && $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 503)) {
                $code = 'UPLOAD_VIDEO_SERVICE_UNAVAILABLE';
            }
            if ($code === null && ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface)) {
                throw $exception;
            }
            // Keep SQL, filesystem paths and exception details in the private server log.
            // Repeating a deterministic setup failure only delays the teacher.
            report($exception);

            return response()->json([
                'message' => 'تعذر إكمال رفع الملف بسبب خطأ في الخادم.',
                'code' => $code ?? 'UPLOAD_SERVER_ERROR', 'stage' => $stage,
                'reason' => $videoReason,
                'reference' => $reference, 'retryable' => false,
            ], $code === null ? 500 : 503);
        }
    }
}
