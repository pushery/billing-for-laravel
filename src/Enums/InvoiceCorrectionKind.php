<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The role of an invoice correction — which of the two distinct correcting documents it is. They are not
 * a single "credit note" state: EN 16931 gives each its own type code and its own rules, and the tax
 * chain turns ambiguous the moment they share a name.
 *
 * - Cancellation (Storno / kaufmännische Gutschrift) → EN 16931 type code 381. Reverses a booked turnover;
 *   its amounts are POSITIVE and the code, not a sign, inverts the meaning.
 * - Amendment (Rechnungsberichtigung) → type code 384. Corrects a specific earlier invoice and therefore
 *   MUST reference it (BG-3). This is the role the invariant guards: an amendment with no origin reference
 *   is not a valid document and cannot be constructed.
 *
 * The mapping from this role to the written type code (389/384/381) and the EN 16931 rule-set validation
 * live in the e-invoice writers; this enum is the neutral role those branches select on, so the type code
 * hangs on the document's role rather than on a boolean.
 */
enum InvoiceCorrectionKind: string
{
    case Cancellation = 'cancellation';
    case Amendment = 'amendment';
}
