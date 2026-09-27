<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('library_resources', 'disk')) {
            Schema::table('library_resources', function (Blueprint $table): void {
                $table->string('disk', 30)->default('public');
            });
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        DB::table('library_resources')
            ->where('is_public', false)
            ->orderBy('id')
            ->eachById(function (object $resource) use ($public, $private): void {
                if (! $private->exists($resource->file_path) && $public->exists($resource->file_path)) {
                    $this->copyVerified($public, $private, $resource->file_path);
                }

                if ($private->exists($resource->file_path)) {
                    if ($public->exists($resource->file_path) && ! $this->filesMatch($public, $private, $resource->file_path)) {
                        throw new RuntimeException('Private library file copy could not be verified: '.$resource->file_path);
                    }

                    DB::table('library_resources')->where('id', $resource->id)->update(['disk' => 'local']);

                    if ($public->exists($resource->file_path) && (! $public->delete($resource->file_path) || $public->exists($resource->file_path))) {
                        throw new RuntimeException('Public library file could not be removed: '.$resource->file_path);
                    }
                }
            }, column: 'id');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('library_resources', 'disk')) {
            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        DB::table('library_resources')
            ->where('disk', 'local')
            ->orderBy('id')
            ->eachById(function (object $resource) use ($public, $private): void {
                if (! $public->exists($resource->file_path) && $private->exists($resource->file_path)) {
                    $this->copyVerified($private, $public, $resource->file_path);
                }

                if ($private->exists($resource->file_path)) {
                    if (! $public->exists($resource->file_path) || ! $this->filesMatch($private, $public, $resource->file_path)) {
                        throw new RuntimeException('Public library file copy could not be verified: '.$resource->file_path);
                    }

                    DB::table('library_resources')->where('id', $resource->id)->update(['disk' => 'public']);

                    if (! $private->delete($resource->file_path) || $private->exists($resource->file_path)) {
                        throw new RuntimeException('Private library file could not be removed during rollback: '.$resource->file_path);
                    }
                }
            }, column: 'id');

        Schema::table('library_resources', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }

    private function copyVerified(FilesystemAdapter $source, FilesystemAdapter $target, string $path): void
    {
        $stream = $source->readStream($path);

        if ($stream === false) {
            throw new RuntimeException('Library file could not be read: '.$path);
        }

        try {
            $target->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $target->exists($path) || ! $this->filesMatch($source, $target, $path)) {
            throw new RuntimeException('Library file copy could not be verified: '.$path);
        }
    }

    private function filesMatch(FilesystemAdapter $first, FilesystemAdapter $second, string $path): bool
    {
        return $first->fileSize($path) === $second->fileSize($path)
            && $this->hash($first, $path) === $this->hash($second, $path);
    }

    private function hash(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if ($stream === false) {
            throw new RuntimeException('Library file could not be read for verification: '.$path);
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
};
