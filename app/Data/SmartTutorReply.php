<?php

namespace App\Data;

final readonly class SmartTutorReply
{
    public function __construct(
        public string $content,
        public ?string $finishReason = null,
    ) {}
}
