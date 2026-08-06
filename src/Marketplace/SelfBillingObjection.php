<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Exceptions\NotASelfBilledDocument;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Records a creator's objection to a self-billed document — the one-click, no-questions defense the law gives
 * them, and the platform's own protection against a § 14c liability from a mis-classified creator.
 *
 * The objection is UNCONDITIONAL by design: no reason, no deadline, no form, and it works even against a
 * document that is arithmetically correct (BFH XI R 25/11). The only thing it checks is that the target is a
 * self-billed document at all — an ordinary invoice carries no objection right. Recording it takes away the
 * document's effect as an invoice from the taxation period of the objection forward (ex nunc); the document
 * itself is never touched, because it is frozen and retained. The receipt time and channel are kept as the
 * evidence for "without delay" should a § 14c question ever arise.
 *
 * What FOLLOWS an objection — the input-tax lock in the DATEV path, the payout hold, the re-issue or fallback
 * lane — reads this state; it does not live here. This is only the act of recording it.
 */
final readonly class SelfBillingObjection
{
    /**
     * @param  string  $channel  how the objection arrived (e.g. 'one_click', 'email', 'support') — kept as evidence
     */
    public function record(InvoiceRecord $document, CarbonInterface $receivedAt, string $channel): void
    {
        if (! $document->settlement_document_type instanceof SettlementDocumentType) {
            throw NotASelfBilledDocument::make();
        }

        // Idempotent and unconditional: the FIRST objection's receipt time is what fixes the taxation period,
        // so a later re-click never moves it, and no age, correctness or deadline check stands in the way.
        if ($document->invoiceEffectRevoked()) {
            return;
        }

        $document->forceFill([
            'invoice_effect_revoked_at' => $receivedAt,
            'invoice_effect_revoked_channel' => $channel,
        ])->save();
    }
}
