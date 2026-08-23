<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public static function record(string $action, string $module, ?Model $model = null, array $old = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'module' => $module, 'record_id' => $model?->getKey(), 'ip_address' => request()->ip(), 'old_values' => $old ?: null, 'new_values' => $model?->getAttributes()]);
    }
}
