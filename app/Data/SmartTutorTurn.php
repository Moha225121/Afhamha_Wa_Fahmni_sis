<?php

namespace App\Data;

final readonly class SmartTutorTurn
{
    public function __construct(
        public string $role,
        public string $content,
    ) {}
}
