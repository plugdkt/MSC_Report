<?php

namespace App\Services\Ai;

final class AiRequest
{
    /**
     * @param  list<array{role:'user'|'assistant', content:string}>  $messages
     */
    public function __construct(
        public readonly string $stage,
        public readonly string $system,
        public readonly array $messages,
        public readonly string $tier = 'smart',
        public readonly ?int $maxTokens = null,
    ) {}

    public function withMessage(string $role, string $content): self
    {
        return new self($this->stage, $this->system, [...$this->messages, ['role' => $role, 'content' => $content]], $this->tier, $this->maxTokens);
    }

    public function estimatedInputTokens(): int
    {
        return TokenEstimator::estimate($this->system.implode("\n", array_column($this->messages, 'content')));
    }
}
