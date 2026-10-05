<?php

namespace mindtwo\LaravelAiSpark\Server;

/**
 * Result of vLLM's /tokenize endpoint.
 */
final readonly class TokenCount
{
    /**
     * @param  list<int>  $tokens
     */
    public function __construct(
        public int $count,
        public int $maxModelLength,
        public array $tokens = [],
    ) {}

    /**
     * Tokens left in the context window after this input.
     */
    public function remaining(): int
    {
        return max(0, $this->maxModelLength - $this->count);
    }

    /**
     * Whether the input plus $reserve tokens for the answer fit into the context window.
     */
    public function fits(int $reserve = 0): bool
    {
        return $this->count + $reserve <= $this->maxModelLength;
    }
}
