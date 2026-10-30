<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherMessageReceipt extends Model
{
    protected $primaryKey = 'notification_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['notification_id', 'teacher_user_id', 'recipient_user_id', 'dispatch_id', 'delivered_at', 'viewed_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'viewed_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
