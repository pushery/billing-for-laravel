<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\ConformityUpdatePolicy;

/**
 * The German reading of the conformity-update obligation for digital works.
 *
 * A seller owes the buyer the updates needed to keep a digital work conforming — defect fixes, security
 * fixes, staying compatible — for as long as the buyer may reasonably expect, and this holds for a ONE-OFF
 * purchase, not only for a subscription. That is why a frozen sale here is a sale whose ENRICHMENT stopped,
 * never a sale whose obligations stopped.
 *
 * ## The period is not in this file, and that is deliberate
 *
 * "As long as the buyer may reasonably expect" is a judgement about a kind of product — a game, a font, an
 * ebook — and the law states no number. Hard-coding one would be inventing a legal answer and then hiding it
 * in a library. So the period is configuration, it ships EMPTY, and empty means no end has been established:
 * the updates keep flowing. That direction is chosen because it is the one that cannot harm a buyer, and
 * because a package quietly deciding when somebody's rights run out is the outcome worth ruling out.
 *
 * ## The waiver is refused by default, and the reason is unsettled law
 *
 * A blanket "no updates" arrangement is only capable of being valid where it was agreed separately and
 * before the contract, with the buyer told what they are giving up — never a config default, never an
 * order-form checkbox. Whether SECURITY updates can be waived at all is genuinely disputed, and this package
 * does not resolve it: the flag ships off, and turning it on is an operator's decision taken on their own
 * advice. Even then it only makes a waiver POSSIBLE — recording one still needs a reference to the actual
 * agreement.
 */
final readonly class GermanConformityUpdatePolicy implements ConformityUpdatePolicy
{
    public function __construct(private Repository $config) {}

    public function updatesUntil(CarbonInterface $acquiredAt): ?CarbonInterface
    {
        $days = $this->config->get('billing.consumer_rights.conformity_update_period_days');

        // Anything that is not a positive whole number of days is "no period established" rather than an
        // error: an operator who has not taken advice has nothing to state, and a zero would be a statement —
        // that the obligation ended the day it began — which nobody means to make by leaving a field blank.
        return is_int($days) && $days > 0 ? $acquiredAt->copy()->addDays($days) : null;
    }

    public function waiverPermitted(): bool
    {
        return $this->config->get('billing.consumer_rights.allow_conformity_waiver') === true;
    }
}
