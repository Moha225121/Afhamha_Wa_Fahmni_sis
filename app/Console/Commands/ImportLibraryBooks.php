<?php

namespace App\Console\Commands;

use App\Models\LibraryResource;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class ImportLibraryBooks extends Command
{
    protected $signature = 'library:import {source : Local folder containing the books} {manifest : Reviewed JSON catalog} {--admin= : Active administrator ID}';

    protected $description = 'Import a reviewed Arabic book catalog into private storage, without modifying source PDFs';

    public function handle(): int
    {
        $source = realpath($this->argument('source'));
        if (! $source || ! is_dir($source)) {
            $this->error('Book source folder does not exist.');

            return self::FAILURE;
        }
        $admin = User::where('role', 'admin')->where('status', 'active')
            ->when($this->option('admin'), fn ($q, $id) => $q->whereKey($id))->first();
        if (! $admin) {
            $this->error('An active administrator is required.');

            return self::FAILURE;
        }
        $entries = json_decode(file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($entries) || ! array_is_list($entries)) {
            $this->error('The manifest must be an array of book records.');

            return self::FAILURE;
        }
        $storage = Storage::disk('local');
        $created = $updated = 0;
        foreach ($entries as $index => $entry) {
            Validator::make($entry, [
                'source_path' => 'required|string', 'title' => 'required|string|max:255',
                'subject_name' => 'required|string|max:100', 'grade_levels' => 'required|array|min:1|max:12',
                'grade_levels.*' => 'required|integer|between:1,12', 'education_stage' => 'required|in:أساسي,ثانوي',
                'track' => 'nullable|in:علمي,أدبي,مشترك', 'book_type' => 'required|in:'.implode(',', array_keys(config('library.book_types'))),
                'audience' => 'required|in:all,teachers', 'sha256' => 'required|string|size:64|regex:/^[a-f0-9]+$/',
                'page_count' => 'nullable|integer|min:1', 'term' => 'nullable|string|max:100',
                'file_size' => 'required|integer|min:1', 'original_name' => 'required|string|max:255',
            ])->validate();
            $sourceFile = realpath($source.DIRECTORY_SEPARATOR.$entry['source_path']);
            $prefix = rtrim(str_replace('\\', '/', $source), '/').'/';
            if (! $sourceFile || ! str_starts_with(str_replace('\\', '/', $sourceFile), $prefix) || ! is_file($sourceFile)
                || strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION)) !== 'pdf' || str_starts_with(basename($sourceFile), '._')) {
                throw new RuntimeException('Invalid source book path: '.$entry['source_path']);
            }
            if (filesize($sourceFile) !== $entry['file_size']) {
                throw new RuntimeException('Source size changed since catalog review: '.$entry['source_path']);
            }
            if (! hash_equals($entry['sha256'], hash_file('sha256', $sourceFile))) {
                throw new RuntimeException('Source integrity check failed: '.$entry['source_path']);
            }
            $handle = fopen($sourceFile, 'rb');
            $header = fread($handle, 1024);
            fclose($handle);
            if (! str_contains($header, '%PDF-')) {
                throw new RuntimeException('Invalid PDF: '.$entry['source_path']);
            }

            $path = 'library/curriculum/'.$entry['sha256'].'.pdf';
            if (! $storage->exists($path)) {
                $temporary = 'library/curriculum/.'.Str::uuid().'.part';
                $stream = fopen($sourceFile, 'rb');
                try {
                    if (! $storage->put($temporary, $stream)) {
                        throw new RuntimeException('Unable to copy book.');
                    }
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($entry['sha256'], hash_file('sha256', $storage->path($temporary)))) {
                    $storage->delete($temporary);
                    throw new RuntimeException('Book integrity check failed: '.$entry['source_path']);
                }
                if (! $storage->move($temporary, $path)) {
                    throw new RuntimeException('Unable to finalize book.');
                }
            } elseif ($storage->size($path) !== $entry['file_size'] || ! hash_equals($entry['sha256'], hash_file('sha256', $storage->path($path)))) {
                throw new RuntimeException('Existing stored book integrity check failed: '.$entry['source_path']);
            }
            $data = Arr::only($entry, ['title', 'subject_name', 'grade_levels', 'education_stage', 'track', 'book_type', 'term', 'audience', 'original_name', 'source_path', 'file_size', 'sha256', 'page_count']);
            if (in_array($data['book_type'], ['teacher_guide', 'assessment'], true)) {
                $data['audience'] = 'teachers';
            }
            $data += ['file_path' => $path, 'disk' => 'local', 'is_public' => true, 'status' => 'active',
                'subject_id' => null, 'classroom_id' => null,
                'created_by' => $admin->id, 'mime_type' => 'application/pdf',
                'category' => config('library.book_types.'.$data['book_type']), 'extracted_text' => $entry['search_text'] ?? null];
            $book = LibraryResource::firstOrNew(['sha256' => $entry['sha256']]);
            if ($book->exists) {
                $data['grade_levels'] = array_values(array_unique(array_merge($book->grade_levels ?? [], $data['grade_levels'])));
                sort($data['grade_levels']);
                if ($book->audience === 'teachers') {
                    $data['audience'] = 'teachers';
                }
                $updated++;
            } else {
                $created++;
            }
            $book->fill($data)->save();
            if (($index + 1) % 25 === 0 || $index === count($entries) - 1) {
                $this->line(($index + 1).'/'.count($entries).' books indexed');
            }
        }
        $this->info("Import complete: {$created} added, {$updated} existing records updated. Source files unchanged.");

        return self::SUCCESS;
    }
}
