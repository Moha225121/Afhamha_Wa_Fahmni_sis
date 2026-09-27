<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmissionAttachment extends Model
{
    protected $fillable = ['assignment_submission_id', 'disk', 'path', 'original_name', 'mime_type', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }

    public function hasValidPrivateMetadata(): bool
    {
        $allowedExtensions = config('student_academic.private_files.allowed_extensions', []);
        $originalExtension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
        $storedExtension = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
        $mimeType = strtolower(trim((string) $this->mime_type));
        $size = $this->size;

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
