<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A pointer at one work in the consumer's own content, as this package carries it.
 *
 * Two parts, because one is not enough: an ebook and an episode may both be "17" in their own tables, and a
 * register that keyed on the reference alone would hand somebody the wrong one. The type says which table
 * the consumer should look in; the reference says which row. Neither is ever parsed here — the package
 * stores and compares them, and that is all it is entitled to do with somebody else's identifiers.
 *
 * Not a foreign key, on purpose: deleting a work is exactly when the record of who owned it matters most.
 */
final readonly class ContentReference
{
    public function __construct(
        public string $type,
        public string $reference,
    ) {}

    /**
     * The string this reference is keyed by in a map.
     *
     * `#` rather than `:` so a reference that itself contains a colon — a URN, a namespaced id — cannot make
     * two different works collide onto one key. The separator is an implementation detail of the map; nothing
     * persists it, so it is free to be chosen for safety rather than for looks.
     */
    public function key(): string
    {
        return $this->type.'#'.$this->reference;
    }
}
