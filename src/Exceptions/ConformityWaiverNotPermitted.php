<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A conformity waiver was asked for and refused.
 *
 * Refused rather than ignored, and refused LOUDLY. A waiver that silently did not take effect would leave a
 * caller believing the obligation had ended while the package believed it had not — and the two beliefs only
 * meet when somebody asks why their fix never arrived, or why one did.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ConformityWaiverNotPermitted extends RuntimeException
{
    /**
     * There is no obligation to waive, because no consumer-rights profile is active.
     *
     * Not a harmless no-op: a caller reaching for this has a jurisdiction in mind that the install has not
     * been told about, and writing the flag anyway would record a waiver of nothing that a later profile
     * change would silently turn into a waiver of something.
     */
    public static function withoutAProfile(): self
    {
        return new self(
            'No consumer-rights profile is active, so there is no conformity obligation to waive. '
            .'Set billing.consumer_rights.profile before recording a waiver — writing the flag without one '
            .'would leave a row that becomes a real waiver the day a profile is configured.'
        );
    }

    /** The active jurisdiction does not allow the obligation to be contracted away this way. */
    public static function notAllowedByTheProfile(): self
    {
        return new self(
            'The active consumer-rights profile does not permit a conformity waiver. A blanket "no updates" '
            .'arrangement is only capable of being valid where it was agreed separately and before the '
            .'contract, and whether security fixes can be waived at all is disputed — so this ships refused '
            .'and billing.consumer_rights.allow_conformity_waiver is an operator decision taken on advice.'
        );
    }

    /**
     * No reference to the agreement was given.
     *
     * The reference is what makes the flag defensible. A `true` with nothing behind it cannot be told apart
     * from a bug that set it, which is exactly the position nobody wants to be in when asked to produce the
     * agreement.
     */
    public static function withoutADeclaration(): self
    {
        return new self(
            'A conformity waiver needs a reference to the agreement it rests on. A flag with nothing behind '
            .'it cannot be distinguished from a defect that set it.'
        );
    }
}
