<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Who is named as the SELLER on a document — a different question from the seller-of-record POSTURE.
 *
 * The posture ({@see SellerOfRecordPosture}) answers which ROLE the platform plays;
 * this answers which concrete party — name, address, tax ids — that role resolves to. They are one decision
 * seen twice, so the resolved party must agree with the frozen posture: when the platform is the deemed
 * supplier the seller is the platform itself, and when it merely arranges or the merchant sells in their own
 * name the seller is the merchant. A document that named the merchant as seller under a deemed-supplier
 * posture would let a creator face the buyer as the seller — the exact thing the deemed-supplier rule exists
 * to prevent.
 *
 * The default resolves the platform, so a single-seller install renders exactly as before. A marketplace
 * consumer binds a resolver that returns the merchant where the posture calls for it.
 */
interface SellerPartyResolver
{
    public function sellerFor(InvoiceRecord $invoice): Party;
}
