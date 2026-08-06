<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The kind of document the platform issues to a creator for their supply into a commission-chain sale.
 *
 * Two kinds, and the difference is not cosmetic. A self-billed invoice is the platform writing the
 * creator's invoice for them — it can carry a tax statement the creator's own books rely on, so it may only
 * be issued for a party who could have issued that invoice themselves. A settlement note is the honest
 * document for a party who could not: it records what was paid and states no tax at all. A sale whose
 * creator standing is unestablished produces NEITHER — that is a hold, represented by the absence of a
 * document rather than a third case here, so the type never has to carry a "no document" value that a
 * caller could mistake for a real one.
 *
 * The names are the code's own. Which national document a jurisdiction maps each to, and under which
 * statute, lives in that jurisdiction's profile — a consumer elsewhere reads these two words and no law.
 */
enum SettlementDocumentType: string
{
    /** The platform writes the creator's invoice for their supply — the one document that may state tax. */
    case SelfBilledInvoice = 'self_billed_invoice';

    /** A plain record of what was paid, for a party who issues no invoice. It never states tax. */
    case SettlementNote = 'settlement_note';
}
