<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'content', 'audience', 'classroom_id', 'published_at', 'expires_at', 'status', 'created_by'])] class Announcement extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
