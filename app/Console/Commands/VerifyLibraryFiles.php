<?php

namespace App\Console\Commands;

use App\Models\LibraryResource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class VerifyLibraryFiles extends Command
{
    protected $signature = 'library:verify-files {--restore : Restore missing files from verified originals} {--source=* : Trusted original book directories} {--hard-link : Share verified immutable PDF bytes on the same filesystem instead of duplicating them}';

    protected $description = 'Verify active library files and optionally restore missing PDFs without changing catalog records';

    public function handle(): int
    {
        $sources = array_filter(array_map('realpath', $this->option('source')));
        $checked = $restored = $failed = 0;
        foreach (LibraryResource::where('status', 'active')->orderBy('id')->cursor() as $book) {
            $checked++;
            try {
                $disk = $book->disk ?: 'public';
                $path = str_replace('\\', '/', $book->file_path);
                if (! in_array($disk, ['local', 'public'], true) || ! $this->relativePath($path)) {
                    throw new RuntimeException('Unsupported disk or unsafe stored path.');
                }
                $storage = Storage::disk($disk);
                $root = realpath($storage->path(''));
                if ($root === false) {
                    throw new RuntimeException('Storage root does not exist.');
                }
                $target = $storage->path($path);
                if (file_exists($target) || is_link($target)) {
                    $file = realpath($target);
                    if (! $file || ! $this->inside($file, $root) || ! $this->matches($file, $book)) {
                        throw new RuntimeException('Stored file is unreadable, outside storage, or fails integrity verification.');
                    }

                    continue;
                }
                if (! $this->option('restore')) {
                    throw new RuntimeException('Stored file is missing.');
                }
                // Recovery requires the reviewed catalog fingerprint. Never guess a file by title.
                if (! preg_match('/^[a-f0-9]{64}$/', $book->sha256 ?? '') || ! $book->file_size
                    || ! $this->relativePath($book->source_path ?? '') || ! str_ends_with(strtolower($path), '.pdf')) {
                    throw new RuntimeException('Missing verified original metadata.');
                }
                $source = null;
                foreach ($sources as $sourceRoot) {
                    $candidate = realpath($sourceRoot.'/'.$book->source_path);
                    if ($candidate && $this->inside($candidate, $sourceRoot) && $this->matches($candidate, $book)) {
                        $source = $candidate;
                        break;
                    }
                }
                if (! $source) {
                    throw new RuntimeException('No matching original found in the supplied source directories.');
                }
                // Check existing ancestors before making directories, including symlinked parents.
                $parent = dirname($target);
                while (! file_exists($parent) && ! is_link($parent)) {
                    $parent = dirname($parent);
                }
                $parent = realpath($parent);
                if (! $parent || ($parent !== $root && ! $this->inside($parent, $root))) {
                    throw new RuntimeException('Destination directory escapes storage.');
                }
                if (! $storage->makeDirectory(dirname($path))) {
                    throw new RuntimeException('Unable to create the book directory.');
                }
                $temporary = dirname($target).'/.'.Str::uuid().'.part';
                try {
                    $linked = (bool) $this->option('hard-link');
                    $free = disk_free_space(dirname($target));
                    if (! $linked && $free !== false && $free < $book->file_size + 10485760) {
                        throw new RuntimeException('Insufficient free space to copy this book.');
                    }
                    $written = $linked ? link($source, $temporary) : copy($source, $temporary);
                    if (! $written || ! $this->matches($temporary, $book)) {
                        throw new RuntimeException('Restored copy failed integrity verification.');
                    }
                    if (! $linked) {
                        chmod($temporary, 0644);
                    }
                    // Another process may have restored this book while we copied it.
                    if (file_exists($target) || is_link($target)) {
                        $current = realpath($target);
                        if (! $current || ! $this->inside($current, $root) || ! $this->matches($current, $book)) {
                            throw new RuntimeException('Destination changed during recovery.');
                        }
                    } elseif (! rename($temporary, $target)) {
                        throw new RuntimeException('Unable to finalize restored book.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
                $restored++;
                $this->line('Restored book #'.$book->id.'.');
            } catch (RuntimeException $error) {
                $failed++;
                $this->error('Book #'.$book->id.': '.$error->getMessage());
            }
        }
        $this->info("Library files: {$checked} checked, {$restored} restored, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function relativePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, ':')
            && ! str_contains($path, "\0") && ! str_contains($path, '\\')
            && ! preg_match('~(^|/)\.{1,2}(/|$)~', $path);
    }

    private function inside(string $file, string $root): bool
    {
        $file = str_replace('\\', '/', $file);
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $file = strtolower($file);
            $prefix = strtolower($prefix);
        }

        return str_starts_with($file, $prefix);
    }

    private function matches(string $file, LibraryResource $book): bool
    {
        clearstatcache(true, $file);
        if (! is_file($file) || ! is_readable($file)) {
            return false;
        }
        if ($book->file_size && filesize($file) !== $book->file_size) {
            return false;
        }

        return ! $book->sha256 || hash_equals($book->sha256, hash_file('sha256', $file));
    }
}
