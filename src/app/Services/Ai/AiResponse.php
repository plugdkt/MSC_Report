<?php

namespace App\Services\Ai;

final class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $model,
        public readonly bool $fromCache = false,
        public readonly string $keySource = 'none',
        /** @var array{quota:int, used:int, remaining:int}|null */
        public readonly ?array $quota = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
