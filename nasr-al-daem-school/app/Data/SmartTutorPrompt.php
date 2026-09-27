<?php

namespace App\Data;

final readonly class SmartTutorPrompt
{
    /**
     * @param  list<SmartTutorTurn>  $turns
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public array $turns,
        public string $locale = 'ar',
        public array $context = [],
    ) {}
}
