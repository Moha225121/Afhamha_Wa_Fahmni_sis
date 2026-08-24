<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tutor_conversation_id',
    'role',
    'content',
    'client_request_id',
    'delivery_status',
    'failure_reason',
    'in_reply_to_message_id',
])]
class TutorMessage extends Model
{
    protected $touches = ['conversation'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TutorConversation::class, 'tutor_conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_message_id');
    }

    public function reply(): HasOne
    {
        return $this->hasOne(self::class, 'in_reply_to_message_id');
    }
}
