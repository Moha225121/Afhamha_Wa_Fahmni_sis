<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LibraryUploadService
{
    public function start(int $userId, string $name, int $size): array
    {
        $maximum = (int) config('library.max_upload_mb', 0) * 1024 * 1024;
        if ($maximum > 0 && $size > $maximum) {
            throw ValidationException::withMessages(['file' => 'يتجاوز الملف الحد المسموح للمكتبة.']);
        }
        $free = disk_free_space(Storage::disk('local')->path(''));
        if ($free !== false && $size * 2 > $free) {
            throw ValidationException::withMessages(['file' => 'لا توجد مساحة كافية لحفظ هذا الكتاب على الخادم.']);
        }
        $id = (string) Str::uuid();
        $storage = Storage::disk('local');
        $storage->makeDirectory('library-uploads/'.$id);
        $state = ['user_id' => $userId, 'name' => basename(str_replace('\\', '/', $name)), 'size' => $size,
            'received_bytes' => 0, 'hashes' => [], 'created_at' => time(), 'chunk_size' => (int) config('library.chunk_bytes')];
        $storage->put('library-uploads/'.$id.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));

        return ['upload_id' => $id, 'chunk_size' => $state['chunk_size']];
    }

    public function chunk(string $id, int $userId, int $index, UploadedFile $chunk): array
    {
        return $this->locked($id, $userId, function (array $state, string $directory) use ($chunk, $index) {
            $hash = hash_file('sha256', $chunk->getRealPath());
            if (isset($state['hashes'][$index])) {
                if (! hash_equals($state['hashes'][$index], $hash)) {
                    throw ValidationException::withMessages(['chunk' => 'اختلف محتوى الجزء المعاد رفعه. أعد المحاولة.']);
                }

                return ['received_bytes' => $state['received_bytes'], 'total_bytes' => $state['size']];
            }
            $expected = min($state['chunk_size'], $state['size'] - $state['received_bytes']);
            if ($index !== count($state['hashes']) || $expected <= 0 || $chunk->getSize() !== $expected) {
                throw ValidationException::withMessages(['chunk' => 'أجزاء الملف غير مكتملة أو خارج الترتيب.']);
            }
            $input = fopen($chunk->getRealPath(), 'rb');
            $output = fopen($directory.'/body.part', 'c+b');
            if (! $input || ! $output) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Unable to open upload streams.');
            }
            try {
                // Truncate a partial append left by an interrupted request before retrying.
                ftruncate($output, $state['received_bytes']);
                fseek($output, $state['received_bytes']);
                $written = stream_copy_to_stream($input, $output);
                if ($written !== $expected) {
                    ftruncate($output, $state['received_bytes']);
                    throw new RuntimeException('Unable to write complete upload chunk.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $state['received_bytes'] += $expected;
            $state['hashes'][] = $hash;
            file_put_contents($directory.'/state.json', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);

            return ['received_bytes' => $state['received_bytes'], 'total_bytes' => $state['size']];
        });
    }

    public function file(string $id, int $userId): UploadedFile
    {
        return $this->locked($id, $userId, function (array $state, string $directory) {
            clearstatcache(true, $directory.'/body.part');
            if ($state['received_bytes'] !== $state['size'] || ! is_file($directory.'/body.part') || filesize($directory.'/body.part') !== $state['size']) {
                throw ValidationException::withMessages(['file' => 'لم يكتمل رفع الكتاب. يرجى إعادة المحاولة.']);
            }

            return new UploadedFile($directory.'/body.part', $state['name'], null, null, true);
        });
    }

    public function discard(string $id, int $userId): void
    {
        $this->locked($id, $userId, fn () => null);
        Storage::disk('local')->deleteDirectory('library-uploads/'.$id);
    }

    public function pruneExpired(): int
    {
        $storage = Storage::disk('local');
        $root = realpath($storage->path('library-uploads'));
        $diskRoot = realpath($storage->path(''));
        // Refuse a redirected staging root and inspect only immediate UUID directories.
        if ($root === false || $diskRoot === false || dirname($root) !== $diskRoot || is_link($storage->path('library-uploads'))) {
            return 0;
        }
        $cutoff = time() - (int) config('library.upload_ttl_hours', 24) * 3600;
        $removed = 0;
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || ! $entry->isDir() || $entry->isLink() || ! Str::isUuid($entry->getFilename())) {
                continue;
            }
            $directory = realpath($entry->getPathname());
            if ($directory === false || dirname($directory) !== $root) {
                continue;
            }
            $statePath = $directory.'/state.json';
            $lockPath = $directory.'/.lock';
            if (! is_file($statePath) || is_link($statePath) || is_link($lockPath)) {
                continue;
            }
            $lock = @fopen($lockPath, 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }

                continue;
            }
            $ready = false;
            try {
                // A request may have completed between the directory listing and the lock.
                if (! is_file($statePath)) {
                    continue;
                }
                $state = json_decode(file_get_contents($statePath), true);
                if (! is_array($state) || ! is_int($state['created_at'] ?? null) || $state['created_at'] >= $cutoff) {
                    continue;
                }
                $entries = array_diff(scandir($directory), ['.', '..']);
                if (array_diff($entries, ['state.json', 'body.part', '.lock'])) {
                    continue;
                }
                $body = $directory.'/body.part';
                if (is_link($body) || (file_exists($body) && ! is_file($body))) {
                    continue;
                }
                if (is_file($body) && ! @unlink($body)) {
                    continue;
                }
                // Removing state under the lock makes queued requests fail before any write.
                $ready = @unlink($statePath);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            if ($ready && $storage->deleteDirectory('library-uploads/'.$entry->getFilename())) {
                $removed++;
            }
        }

        return $removed;
    }

    private function locked(string $id, int $userId, callable $operation): mixed
    {
        abort_unless(Str::isUuid($id), 404);
        $directory = Storage::disk('local')->path('library-uploads/'.$id);
        abort_unless(is_file($directory.'/state.json'), 404);
        $lock = fopen($directory.'/.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock upload.');
        }
        try {
            abort_unless(is_file($directory.'/state.json'), 404);
            $state = json_decode(file_get_contents($directory.'/state.json'), true, 512, JSON_THROW_ON_ERROR);
            abort_unless($state['user_id'] === $userId, 404);
            if (time() - $state['created_at'] > (int) config('library.upload_ttl_hours') * 3600) {
                throw ValidationException::withMessages(['file' => 'انتهت صلاحية الرفع. ابدأ رفع الكتاب من جديد.']);
            }

            return $operation($state, $directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
