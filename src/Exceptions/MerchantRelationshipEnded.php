<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Onboarding was asked for a merchant whose relationship with this platform has already ended.
 *
 * ## The state this exists to stop being silent
 *
 * A merchant who disconnects their provider account — by accident, while tidying up app connections, or
 * because a collaboration ended — is terminated here and cannot receive money. Asking to onboard them again
 * used to hand back the OLD reference: no second provider account, no exception, no change to the row, and
 * exit 0. The operator did exactly what the code told them to do, got output that looked like a successful
 * onboarding, and had a link to an account the provider no longer releases funds through.
 *
 * That is the worse half of two defects. Missing a way back is a gap; promising one and answering "fine" is
 * a false statement made at the moment somebody is trying to fix something.
 *
 * The way back exists now — `MerchantLifecycle::reopen()`, driven by `billing:merchant:reopen` — and it is
 * deliberately an operator's act rather than something an onboarding call performs on its own. Reopening
 * decides that a relationship somebody ended should begin again, and that is not a decision to make as a
 * side effect of a retry.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MerchantRelationshipEnded extends RuntimeException
{
    public static function forOnboarding(string $provider, string $reference): self
    {
        return new self(
            "This merchant's relationship with the platform has ended ({$provider} account {$reference}), so "
            .'onboarding cannot simply continue with it — the provider no longer releases funds through that '
            .'account. Reopen the relationship first with `billing:merchant:reopen`, which is a deliberate '
            .'act rather than something a retry does quietly, and then let the provider report its '
            .'capabilities again.'
        );
    }
}
