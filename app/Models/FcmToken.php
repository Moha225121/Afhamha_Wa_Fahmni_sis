<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FcmToken extends Model
{
    protected $fillable = ['user_id', 'device_id', 'token', 'token_hash', 'endpoint_hash', 'last_seen_at'];

    protected $hidden = ['token', 'token_hash', 'endpoint_hash'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
