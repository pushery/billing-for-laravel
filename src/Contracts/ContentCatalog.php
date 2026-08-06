<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\ContentAvailability;
use Pushery\Billing\ValueObjects\ContentReference;

/**
 * The consumer's answer to "is this work there right now" — asked in BULK, never one work at a time.
 *
 * The package holds the ownership rows and does not hold the works, so it cannot tell a taken-down work from
 * a live one. It asks. What it does with the answer is fixed: availability never changes whether somebody
 * owns something, only whether it can be handed over.
 *
 * ## The signature is a list because the caller has a list
 *
 * A library screen resolves every row a person owns at once. A per-reference method would turn that into one
 * call per row — and because the implementation is the consumer's own catalog, that is one QUERY per row, in
 * their code, invisible from here. Taking the whole set makes the efficient implementation the natural one
 * and the N+1 impossible to write by accident.
 */
interface ContentCatalog
{
    /**
     * Availability for each reference, keyed by `ContentReference::key()`.
     *
     * A reference the implementation does not recognize may be omitted; the caller treats a missing key as
     * `ContentGone`, which is the honest reading — the register says somebody owns it and the catalog has
     * never heard of it, so from a reader's side it is gone.
     *
     * @param  list<ContentReference>  $references
     * @return array<string, ContentAvailability>
     */
    public function availabilityOf(array $references, CarbonInterface $at): array;
}
