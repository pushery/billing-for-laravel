<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\RetentionAction;
use Pushery\Billing\Enums\RetentionClock;

/**
 * One retention rule: what is held, for how long, on whose authority, counted from when, and what happens
 * at the end.
 *
 * The authority is carried as a value rather than left in a comment on purpose. A period without a stated
 * reason is a number somebody will eventually shorten because it looks arbitrary — and the person
 * shortening it will be right that it looks arbitrary and wrong about the consequence. It is also exactly
 * what an audit asks for, so keeping it beside the number means the answer is derived rather than
 * remembered.
 */
final readonly class RetentionRule
{
    public function __construct(
        /** The table, or a field within one, this rule governs. */
        public string $object,
        public RetentionAction $action,
        public RetentionClock $clock,
        /**
         * How long, in days. Null where the clock has no period — a record that goes with its subject, or
         * one that must never have been kept at all.
         */
        public ?int $days,
        /**
         * A translation key naming the authority for this period. A KEY, not the text: the authority is a
         * jurisdiction's answer, and the core must not carry one country's statutes in its own strings.
         */
        public string $basisKey,
        /**
         * For a scrub, the columns whose contents go. Empty for every other action.
         *
         * Typed as literal strings because they are interpolated into a query by name — a column name
         * cannot be bound as a parameter, so requiring a literal keeps that safe by construction.
         *
         * @var list<literal-string>
         */
        public array $columns = [],
    ) {}

    /** Whether this rule describes a duty to discard rather than a period to wait out. */
    public function isImmediate(): bool
    {
        return $this->clock === RetentionClock::Immediate;
    }
}
