<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public static function record(string $action, string $module, ?Model $model = null, array $old = []): void
    {
        $hidden = array_merge(['password', 'remember_token'], $model?->getHidden() ?? []);
        $old = array_diff_key($old, array_flip($hidden));
        $new = $model ? array_diff_key($model->getAttributes(), array_flip($hidden)) : null;

        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'module' => $module, 'record_id' => $model?->getKey(), 'ip_address' => request()->ip(), 'old_values' => $old ?: null, 'new_values' => $new]);
    }
}
