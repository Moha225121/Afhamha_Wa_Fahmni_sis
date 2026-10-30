<?php

namespace App\Services;

use App\Models\Lesson;
use App\Rules\LessonAttachmentSize;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LessonUploadService
{
    public function start(int $userId, string $name, int $size, ?string $clientId = null): array
    {
        $started = microtime(true);
        Validator::make(['name' => $name, 'size' => $size], [
            'name' => ['required', 'string', 'max:255', 'regex:/\.(pdf|docx?|pptx?|xlsx?|jpe?g|png|webp|heic|heif|mp4)$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.LessonAttachmentSize::maximumForName($name)],
        ], ['size.max' => LessonAttachmentSize::messageForName($name), 'name.regex' => 'نوع الملف غير مدعوم.'])->validate();

        return DB::transaction(function () use ($userId, $name, $size, $clientId, $started): array {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if ($clientId && DB::table('lesson_uploads')->where('id', $clientId)->exists()) {
                $existing = $this->owned($clientId, $userId);
                abort_unless($existing->name === basename(str_replace('\\', '/', $name)) && $existing->size === $size, 409);

                return $this->snapshot($existing);
            }
            // Reject unavailable video verification before accepting a large transfer.
            // Existing sessions remain resumable and keep every acknowledged chunk.
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'mp4') {
                app(LessonVideoValidator::class)->assertAvailable();
            }
            $active = DB::table('lesson_uploads')->where('user_id', $userId)->whereIn('status', ['uploading', 'ready'])->where('expires_at', '>', now());
            if ((clone $active)->count() >= 10 || (clone $active)->sum('size') + $size > config('lesson_uploads.total_bytes')) {
                throw ValidationException::withMessages(['file' => 'أكمل أو أزل المرفقات السابقة. الحد 10 ملفات ومجموع 1100 MiB.']);
            }
            $id = $clientId ?? (string) Str::uuid();
            abort_unless(Str::isUuid($id), 422);
            $disk = Storage::disk('local');
            $directory = 'lesson-uploads/'.$id;
            if (! $disk->makeDirectory('lesson-uploads')) {
                throw new RuntimeException('Unable to create lesson upload root.');
            }
            if (is_link($disk->path('lesson-uploads')) || dirname(realpath($disk->path('lesson-uploads'))) !== realpath($disk->path(''))) {
                throw new RuntimeException('Unsafe lesson upload root.');
            }
            // A caller-selected id must never reuse an orphan directory or an existing permanent attachment.
            abort_if(DB::table('lesson_attachments')->where('file_path', $directory.'/body.part')->exists(), 409);
            abort_unless(@mkdir($disk->path($directory), 0700), 409, 'معرف الرفع مستخدم. ابدأ رفعًا جديدًا.');
            $free = disk_free_space($disk->path($directory));
            if ($free !== false && $free < $size + config('lesson_uploads.chunk_bytes') + 64 * 1024 * 1024) {
                $disk->deleteDirectory($directory);
                abort(507, 'مساحة التخزين غير كافية. تواصل مع الإدارة.');
            }
            DB::table('lesson_uploads')->insert([
                'id' => $id, 'user_id' => $userId, 'name' => basename(str_replace('\\', '/', $name)), 'size' => $size,
                'chunk_size' => config('lesson_uploads.chunk_bytes'), 'hashes' => '[]', 'status' => 'uploading',
                'expires_at' => now()->addHours(config('lesson_uploads.ttl_hours')), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Log::info('lesson_upload.started', ['upload_id' => $id, 'user_id' => $userId, 'bytes' => $size, 'duration_ms' => round((microtime(true) - $started) * 1000)]);

            return $this->status($id, $userId);
        });
    }

    public function status(string $id, int $userId): array
    {
        return $this->snapshot($this->owned($id, $userId));
    }

    public function chunk(string $id, int $userId, int $index, UploadedFile $chunk): array
    {
        return $this->locked($id, $userId, function ($state, string $directory) use ($chunk, $index): array {
            $started = microtime(true);
            $hashes = json_decode($state->hashes, true, 512, JSON_THROW_ON_ERROR);
            $hash = hash_file('sha256', $chunk->getRealPath());
            if (isset($hashes[$index])) {
                abort_unless(hash_equals($hashes[$index], $hash), 409, 'محتوى الجزء المعاد مختلف. أعد اختيار الملف الأصلي.');

                return $this->snapshot($state);
            }
            $expected = min($state->chunk_size, $state->size - $state->received_bytes);
            abort_unless($state->status === 'uploading' && $index === count($hashes) && $expected > 0 && $chunk->getSize() === $expected, 409, 'ترتيب الأجزاء أو حجمها غير صحيح. استأنف الرفع.');
            $input = fopen($chunk->getRealPath(), 'rb');
            $output = fopen($directory.'/body.part', 'c+b');
            try {
                if (! $input || ! $output || ! ftruncate($output, $state->received_bytes) || fseek($output, $state->received_bytes) !== 0
                    || stream_copy_to_stream($input, $output) !== $expected || ! fflush($output)) {
                    throw new RuntimeException('Unable to persist complete lesson upload chunk.');
                }
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
            }
            // A crash before this update is repaired by truncating to the last acknowledged offset.
            $hashes[] = $hash;
            DB::table('lesson_uploads')->where('id', $state->id)->update([
                'received_bytes' => $state->received_bytes + $expected, 'hashes' => json_encode($hashes, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            Log::info('lesson_upload.chunk', ['upload_id' => $state->id, 'index' => $index, 'bytes' => $expected, 'duration_ms' => round((microtime(true) - $started) * 1000)]);

            return $this->status($state->id, $state->user_id);
        });
    }

    public function complete(string $id, int $userId): array
    {
        return $this->locked($id, $userId, function ($state, string $directory): array {
            abort_unless($state->lesson_id === null && in_array($state->status, ['uploading', 'ready'], true)
                && ! DB::table('lesson_attachments')->where('file_path', 'lesson-uploads/'.$state->id.'/body.part')->exists(), 409, 'الملف مرتبط بدرس بالفعل أو أُلغي رفعه.');
            // Old ready sessions predate structural verification: never trust their previous state alone.
            // A prior attachment transaction may have released its file lock before committing.
            // Recheck the state atomically after any database row-lock wait; never resurrect a claimed upload.
            $checking = DB::table('lesson_uploads')->where('id', $state->id)->whereNull('lesson_id')->whereIn('status', ['uploading', 'ready'])
                ->update(['status' => 'uploading', 'sha256' => null]);
            abort_unless($checking === 1, 409, 'الملف مرتبط بدرس بالفعل أو أُلغي رفعه.');
            $started = microtime(true);
            $file = $directory.'/body.part';
            clearstatcache(true, $file);
            abort_unless($state->received_bytes === $state->size && is_file($file) && filesize($file) === $state->size, 422, 'لم يكتمل رفع الملف. استأنف الرفع أولًا.');
            $upload = new UploadedFile($file, $state->name, null, null, true);
            $extension = strtolower(pathinfo($state->name, PATHINFO_EXTENSION));
            Validator::make(['attachment' => $upload], ['attachment' => ['required', 'file', 'mimes:'.$extension, new LessonAttachmentSize]], [
                'attachment.mimes' => 'محتوى الملف لا يطابق نوعًا مدعومًا.',
            ])->validate();
            // Check each acknowledged chunk on disk without loading the video into memory.
            $stream = fopen($file, 'rb');
            $whole = hash_init('sha256');
            try {
                foreach (json_decode($state->hashes, true, 512, JSON_THROW_ON_ERROR) as $expectedHash) {
                    $bytes = fread($stream, $state->chunk_size);
                    abort_unless(is_string($bytes) && hash_equals($expectedHash, hash('sha256', $bytes)), 422, 'تعذر التحقق من سلامة الملف. أعد رفعه.');
                    hash_update($whole, $bytes);
                }
            } finally {
                fclose($stream);
            }
            $digest = hash_final($whole);
            if ($extension === 'mp4') {
                $verified = app(LessonVideoValidator::class)->validate($file, $directory.'/.video-verified.json');
                abort_unless(hash_equals($digest, $verified), 422, 'تغير الملف أثناء التحقق. أعد رفعه.');
            }
            // It stays private here after attachment; claiming changes only database state, so rollback is safe.
            $path = 'lesson-uploads/'.$state->id.'/body.part';
            DB::table('lesson_uploads')->where('id', $state->id)->update([
                'status' => 'ready', 'file_path' => $path, 'mime_type' => $upload->getMimeType(), 'sha256' => $digest, 'updated_at' => now(),
            ]);
            Log::info('lesson_upload.verified', ['upload_id' => $state->id, 'bytes' => $state->size, 'duration_ms' => round((microtime(true) - $started) * 1000)]);

            return $this->status($state->id, $state->user_id);
        });
    }

    public function attach(string $id, int $userId, Lesson $lesson): void
    {
        $this->locked($id, $userId, function ($state, string $directory) use ($id, $userId, $lesson): void {
            if ($state->size > LessonAttachmentSize::maximumForName($state->name)) {
                throw ValidationException::withMessages(['upload_ids' => LessonAttachmentSize::messageForName($state->name)]);
            }
            // Called inside the lesson transaction. Atomic claim prevents reuse in another lesson.
            abort_unless($state->status === 'ready' && $state->lesson_id === null
                && ! DB::table('lesson_attachments')->where('file_path', 'lesson-uploads/'.$id.'/body.part')->exists()
                && $state->file_path === 'lesson-uploads/'.$id.'/body.part' && is_file(Storage::disk('local')->path($state->file_path))
                && Storage::disk('local')->size($state->file_path) === $state->size, 422, 'المرفق غير مكتمل أو لم يعد متاحًا.');
            $digest = hash_file('sha256', $directory.'/body.part');
            abort_unless(is_string($state->sha256) && hash_equals($state->sha256, $digest), 422, 'تغير محتوى المرفق بعد التحقق. أعد رفعه.');
            if ($state->mime_type === 'video/mp4' || strtolower(pathinfo($state->name, PATHINFO_EXTENSION)) === 'mp4') {
                abort_unless(app(LessonVideoValidator::class)->hasReceipt($directory.'/.video-verified.json', $digest), 422, 'يجب التحقق من سلامة الفيديو قبل ربطه بالدرس.');
            }
            $claimed = DB::table('lesson_uploads')->where('id', $id)->where('user_id', $userId)->whereNull('lesson_id')->where('status', 'ready')->where('expires_at', '>', now())
                ->update(['status' => 'attached', 'lesson_id' => $lesson->id, 'updated_at' => now()]);
            abort_unless($claimed === 1, 409, 'سبق استخدام هذا المرفق.');
            $lesson->attachments()->create([
                'title' => $state->name, 'original_name' => $state->name, 'disk' => 'local', 'file_path' => $state->file_path,
                'mime_type' => $state->mime_type, 'size' => $state->size,
            ]);
            DB::afterCommit(fn () => Log::info('lesson_upload.attached', ['upload_id' => $id, 'lesson_id' => $lesson->id, 'bytes' => $state->size]));
        });
    }

    public function discard(string $id, int $userId): void
    {
        $this->locked($id, $userId, function ($state, string $directory): void {
            abort_unless($state->status !== 'attached' && $state->lesson_id === null
                && ! DB::table('lesson_attachments')->where('file_path', 'lesson-uploads/'.$state->id.'/body.part')->exists(), 409, 'الملف مرتبط بدرس بالفعل.');
            // Claim cancellation before deleting: a concurrent lesson transaction cannot claim it.
            $changed = DB::table('lesson_uploads')->where('id', $state->id)->whereNull('lesson_id')->whereIn('status', ['uploading', 'ready'])->update(['status' => 'cancelled']);
            abort_unless($changed === 1 || $state->status === 'cancelled', 409);
            if (is_file($directory.'/body.part') && ! unlink($directory.'/body.part')) {
                throw new RuntimeException('Unable to remove lesson upload.');
            }
        }, true);
    }

    public function prune(): int
    {
        $count = 0;
        foreach (DB::table('lesson_uploads')->where('expires_at', '<=', now())->where('status', '!=', 'attached')->get(['id', 'user_id']) as $row) {
            try {
                $this->discard($row->id, $row->user_id);
                if (! is_dir(Storage::disk('local')->path('lesson-uploads/'.$row->id)) || Storage::disk('local')->deleteDirectory('lesson-uploads/'.$row->id)) {
                    DB::table('lesson_uploads')->where('id', $row->id)->where('status', 'cancelled')->delete();
                    $count++;
                }
            } catch (\Throwable $exception) {
                if (! $exception instanceof HttpException || $exception->getStatusCode() !== 409) {
                    Log::warning('lesson_upload.cleanup_failed', ['upload_id' => $row->id, 'stage' => 'expired', 'exception' => $exception::class]);
                }
            }
        }
        // Old single-request staging files have no database rows.
        foreach (Storage::disk('local')->allFiles('lesson-staging') as $path) {
            try {
                if (Storage::disk('local')->lastModified($path) < now()->subHours(config('lesson_uploads.ttl_hours'))->timestamp) {
                    Storage::disk('local')->delete($path);
                }
            } catch (\Throwable $exception) {
                Log::warning('lesson_upload.cleanup_failed', ['stage' => 'legacy', 'exception' => $exception::class]);
            }
        }
        foreach (DB::table('lesson_uploads')->where('status', 'attached')->whereNull('lesson_id')->get(['id', 'file_path']) as $row) {
            try {
                if (! DB::table('lesson_attachments')->where('file_path', $row->file_path)->exists()
                    && (! is_dir(Storage::disk('local')->path('lesson-uploads/'.$row->id)) || Storage::disk('local')->deleteDirectory('lesson-uploads/'.$row->id))) {
                    DB::table('lesson_uploads')->where('id', $row->id)->delete();
                }
            } catch (\Throwable $exception) {
                Log::warning('lesson_upload.cleanup_failed', ['upload_id' => $row->id, 'stage' => 'detached', 'exception' => $exception::class]);
            }
        }
        // Recover directories left by a process dying before its start transaction committed.
        $disk = Storage::disk('local');
        $root = $disk->path('lesson-uploads');
        if (is_dir($root) && ! is_link($root)) {
            foreach (new \DirectoryIterator($root) as $entry) {
                try {
                    if ($entry->isDot() || ! $entry->isDir() || $entry->isLink() || ! Str::isUuid($entry->getFilename())) {
                        continue;
                    }
                    if ($entry->getMTime() < now()->subHours(config('lesson_uploads.ttl_hours'))->timestamp
                        && ! DB::table('lesson_uploads')->where('id', $entry->getFilename())->exists()
                        && ! DB::table('lesson_attachments')->where('file_path', 'lesson-uploads/'.$entry->getFilename().'/body.part')->exists()) {
                        $disk->deleteDirectory('lesson-uploads/'.$entry->getFilename());
                    }
                } catch (\Throwable $exception) {
                    Log::warning('lesson_upload.cleanup_failed', ['stage' => 'orphan', 'exception' => $exception::class]);
                }
            }
        }

        return $count;
    }

    private function owned(string $id, int $userId, bool $expired = false): object
    {
        abort_unless(Str::isUuid($id), 404);
        $state = DB::table('lesson_uploads')->where('id', $id)->where('user_id', $userId)->first();
        abort_unless($state, 404);
        abort_if(! $expired && $state->status !== 'attached' && now()->gte($state->expires_at), 410, 'انتهت صلاحية الرفع. أزل الملف وأعد اختياره.');

        return $state;
    }

    private function locked(string $id, int $userId, callable $operation, bool $expired = false): mixed
    {
        $this->owned($id, $userId, $expired);
        $directory = Storage::disk('local')->path('lesson-uploads/'.$id);
        if (is_link($directory) || is_link($directory.'/.lock') || is_link($directory.'/body.part') || is_link($directory.'/.video-verified.json')) {
            throw new RuntimeException('Unsafe lesson upload directory.');
        }
        if (is_dir($directory) && (dirname(realpath($directory)) !== realpath(dirname($directory)) || basename(realpath($directory)) !== $id)) {
            throw new RuntimeException('Unsafe lesson upload directory.');
        }
        if (! is_dir($directory) && $expired) {
            return $operation($this->owned($id, $userId, true), $directory);
        }
        $lock = @fopen($directory.'/.lock', 'c');
        if (! $lock && ! is_dir($directory) && $expired) {
            return $operation($this->owned($id, $userId, true), $directory);
        }
        if (! $lock || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock lesson upload.');
        }
        try {
            return $operation($this->owned($id, $userId, $expired), $directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function snapshot(object $state): array
    {
        return ['upload_id' => $state->id, 'name' => $state->name, 'size' => $state->size, 'received_bytes' => $state->received_bytes,
            'chunk_size' => $state->chunk_size, 'hashes' => json_decode($state->hashes, true, 512, JSON_THROW_ON_ERROR),
            'status' => $state->status, 'sha256' => $state->sha256, 'expires_at' => $state->expires_at];
    }
}
