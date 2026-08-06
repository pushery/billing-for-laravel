<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What happens to a record when its retention runs out — or, for a record with no reason to be kept at
 * all, what should already have happened.
 *
 * Four actions, because "delete it" is not one answer. A row can be worth keeping while the personal data
 * inside it is not; a document can be legally required while its link to a person is not. Collapsing them
 * would force each case into the nearest wrong one, and the nearest wrong one is usually deletion of
 * something the law requires.
 *
 * Neutral by name: no statute appears in a value, so a consumer in another jurisdiction reads the same four
 * actions and fills them with their own reasons.
 */
enum RetentionAction: string
{
    /** The row goes. */
    case Delete = 'delete';

    /** The row stays, stripped of anything that identifies a person. */
    case Anonymize = 'anonymize';

    /** The row stays; named fields inside it are emptied. */
    case Scrub = 'scrub';

    /**
     * The row stays and is cut loose from the person, because keeping it is required and linking it is not.
     * The retention clock then removes it when the requirement ends.
     */
    case RetainUnlinked = 'retain_unlinked';
}
