<?php

use App\Models\LibraryResource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_resources', function (Blueprint $table): void {
            $table->string('audience', 20)->default('all')->index();
            $table->string('subject_name')->nullable()->index();
            $table->json('grade_levels')->nullable();
            $table->string('education_stage', 30)->nullable()->index();
            $table->string('track', 30)->nullable();
            $table->enum('book_type', ['textbook', 'workbook', 'activity', 'teacher_guide', 'assessment', 'handwriting'])->nullable()->index();
            $table->string('term', 100)->nullable();
            $table->string('original_name')->nullable();
            $table->text('source_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->char('sha256', 64)->nullable()->unique();
            $table->unsignedInteger('page_count')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->longText('search_text')->nullable();
        });

        DB::table('library_resources')->orderBy('id')->chunkById(100, function ($resources): void {
            foreach ($resources as $resource) {
                DB::table('library_resources')->where('id', $resource->id)->update([
                    'search_text' => LibraryResource::searchTextFor((array) $resource),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('library_resources', function (Blueprint $table): void {
            $table->dropIndex(['audience']);
            $table->dropIndex(['subject_name']);
            $table->dropIndex(['education_stage']);
            $table->dropIndex(['book_type']);
            $table->dropUnique(['sha256']);
            $table->dropColumn(['audience', 'subject_name', 'grade_levels', 'education_stage', 'track', 'book_type', 'term', 'original_name', 'source_path', 'file_size', 'mime_type', 'sha256', 'page_count', 'extracted_text', 'search_text']);
        });
    }
};
