<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessageReceipt extends Model
{
    protected $primaryKey = 'notification_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['notification_id', 'message_id', 'recipient_user_id', 'delivered_at', 'viewed_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'viewed_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
