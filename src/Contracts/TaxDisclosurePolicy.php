<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\CreatorTaxStatus;

/**
 * Answers one question for a jurisdiction: may a settlement document to a creator in this standing STATE
 * tax at all?
 *
 * Not which rate, and not how much — only whether a tax statement is permitted on a document the platform
 * writes for the creator. It is a profile's answer because the consequence of getting it wrong is a
 * jurisdiction's rule: in some, a self-billed document that wrongly states tax makes the RECIPIENT owe that
 * tax, which turns a classification slip on the platform's side into a real liability for a creator who
 * never wrote the document.
 *
 * The answer is a POSITIVE list, never a negative one. A profile names the standings for which tax may be
 * shown and blocks everything else — so a standing added later is blocked until someone deliberately admits
 * it, rather than slipping through a "block the known-bad ones" list that never heard of it.
 */
interface TaxDisclosurePolicy
{
    public function permitsTaxDisclosure(CreatorTaxStatus $status): bool;
}
