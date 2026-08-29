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
        return ['size' => 'integer', 'sort_order' => 'integer'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function hasValidPrivateMetadata(): bool
    {
        $allowedExtensions = config('student_academic.private_files.allowed_extensions', []);
        $originalExtension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
        $storedPath = $this->path ?: $this->file_path;
        $storedExtension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        $mimeType = strtolower(trim((string) $this->mime_type));
        $size = $this->size ?? $this->file_size;

        return $this->privateDisk() === config('student_academic.private_files.disk', 'local')
            && ($size === null || ($size > 0 && $size <= (int) config('student_academic.private_files.max_bytes', 10 * 1024 * 1024)))
            && ($mimeType === '' || in_array($mimeType, config('student_academic.private_files.allowed_mime_types', []), true))
            && in_array($originalExtension, $allowedExtensions, true)
            && in_array($storedExtension, $allowedExtensions, true);
    }

    public function privateDisk(): string
    {
        $disk = trim((string) $this->disk);

        return $disk === '' ? (string) config('student_academic.private_files.disk', 'local') : $disk;
    }
}
