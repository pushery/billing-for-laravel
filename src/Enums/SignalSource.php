<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which of the three place-evidence sources spoke.
 *
 * It exists so a subdivision can be read from the source that settled the COUNTRY, rather than from
 * whichever source happened to know a state. Those are different answers whenever the sources disagree, and
 * a state taken from a source the country did not come from describes a different place than the sale.
 *
 * The order is the evidence's own and is not an arbitrary ranking: the buyer's declaration, the payment
 * instrument, the connection.
 */
enum SignalSource: string
{
    /** What the buyer said about themselves. */
    case Declared = 'declared';

    /** Where the payment instrument is issued, or what its billing address names. */
    case Payment = 'payment';

    /** Where the connection appeared to be, already resolved and never a raw address. */
    case Ip = 'ip';
}
