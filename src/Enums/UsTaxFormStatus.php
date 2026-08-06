<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a seller's declaration stands, which is not the same question as what it says.
 *
 * A declaration that was asked for and one that arrived look identical in every field except this one, and
 * the difference decides whether anything may be paid out under the regime. Rejected is kept rather than
 * deleted for the same reason a failed payment is: "we asked and got something unusable" and "we never
 * asked" lead to different next steps, and a row that vanished cannot tell them apart.
 */
enum UsTaxFormStatus: string
{
    /** Asked for, nothing back yet. */
    case Requested = 'requested';

    /** Received and usable. */
    case OnFile = 'on_file';

    /** Received and unusable — wrong form, contradicted, or withdrawn. */
    case Rejected = 'rejected';

    /** Whether this status is one anything may act on. */
    public function usable(): bool
    {
        return $this === self::OnFile;
    }
}
