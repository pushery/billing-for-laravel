<?php

declare(strict_types=1);

namespace Pushery\Billing\Ai;

use InvalidArgumentException;

/**
 * The tokens one model call used, in the five buckets a provider bills.
 *
 * The meaning follows `laravel/ai`'s text usage, which normalizes every provider to it: `input` is every input token,
 * the ones read from and written to a prompt cache included, and `output` is every output token, reasoning included.
 * `cacheRead`, `cacheWrite` and `reasoning` are the subsets a provider prices on their own. Gemini, which reports its
 * thought tokens beside the output, therefore arrives with them counted in the output once, and Anthropic, which
 * reports its cache tokens beside the input, with them counted in the input once.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $input,
        public int $output,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $reasoning = 0,
    ) {
        if (min($input, $output, $cacheRead, $cacheWrite, $reasoning) < 0) {
            throw new InvalidArgumentException('A token count is never negative.');
        }

        if ($cacheRead + $cacheWrite > $input) {
            throw new InvalidArgumentException("The cache tokens ({$cacheRead} read, {$cacheWrite} written) are part of the input, and the input is only {$input}.");
        }

        if ($reasoning > $output) {
            throw new InvalidArgumentException("The reasoning tokens ({$reasoning}) are part of the output, and the output is only {$output}.");
        }
    }

    /**
     * The usage `laravel/ai` reports for a text response, read through its public properties.
     *
     * Read by name rather than by type, so the package needs `laravel/ai` only where an application has installed it.
     * A count the provider did not report is zero.
     */
    public static function fromLaravelAi(object $usage): self
    {
        $public = get_object_vars($usage);
        $count = static function (string $property) use ($public): int {
            $value = $public[$property] ?? null;

            return is_int($value) ? $value : 0;
        };

        return new self(
            input: $count('inputTokens'),
            output: $count('outputTokens'),
            cacheRead: $count('cacheReadInputTokens'),
            cacheWrite: $count('cacheWriteInputTokens'),
            reasoning: $count('reasoningTokens'),
        );
    }

    /** The input tokens that were neither read from nor written to a prompt cache. */
    public function uncachedInput(): int
    {
        return $this->input - $this->cacheRead - $this->cacheWrite;
    }

    /** The output tokens that were not spent on reasoning. */
    public function plainOutput(): int
    {
        return $this->output - $this->reasoning;
    }
}
