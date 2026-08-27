<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentAttachment extends Model
{
    protected $fillable = [
        'assignment_id', 'path', 'disk', 'file_path', 'original_name', 'mime_type', 'size', 'file_size', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'sort_order' => 'integer'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function hasValidPrivateMetadata(): bool
    {
        $allowedExtensions = config('student_academic.private_files.allowed_extensions', []);
        $originalExtension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
        $storedExtension = strtolower(pathinfo($this->file_path, PATHINFO_EXTENSION));

        return $this->disk === config('student_academic.private_files.disk', 'local')
            && $this->file_size > 0
            && $this->file_size <= (int) config('student_academic.private_files.max_bytes', 10 * 1024 * 1024)
            && in_array(strtolower($this->mime_type), config('student_academic.private_files.allowed_mime_types', []), true)
            && in_array($originalExtension, $allowedExtensions, true)
            && in_array($storedExtension, $allowedExtensions, true);
    }
}
