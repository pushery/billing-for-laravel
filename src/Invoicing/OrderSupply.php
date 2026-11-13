<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\VoucherInstrumentType;
use Pushery\Billing\Models\CreditLedgerEntry;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\OrderItem;
use Pushery\Billing\Support\CreditConsumption;
use Pushery\Billing\ValueObjects\Money;

/**
 * What a locally billed order supplied, as its document states it: the lines, and the gross they come to.
 *
 * ## The credit pays the document and leaves its price alone
 *
 * The order states what the provider is asked to collect, so a cycle that spends credit carries a negative credit
 * line and a total lower by as much. The document states the supply. The credit is part of what paid for it, beside
 * the amount the provider collected, and the price, the net and the tax are those of the lines without it.
 *
 * Every credit this package grants is of that kind. A paid top-up is a multi-purpose voucher, whose tax falls on
 * what it pays for (§ 3 Abs. 13 and 15 UStG). A proration credit gives back part of an earlier supply, and that
 * supply's document is the one corrected for it ({@see ProrationCreditCorrectionIssuer}). A referral reward pays for
 * the referral (Abschn. 10.3 Abs. 2 UStAE). A document that took any of them off its price stated less than was
 * supplied, and taxed less.
 *
 * ## The one share that lowers the price
 *
 * An installation that sells its top-ups as single-purpose vouchers ({@see VoucherInstrumentType::SinglePurpose})
 * taxed each top-up when it was bought (§ 3 Abs. 14 UStG), so the part of a cycle a top-up pays for is not taxed
 * again. That share stays on the document as the negative line it is on the order, and the tax is shared over it as
 * over a discount.
 *
 * The ledger says which share that is. The spend this order made is replayed against the owner's history
 * ({@see CreditConsumption}), and the top-ups among the credits it consumed are the share. Where the spend cannot be
 * found, the credit line stays as it was billed, since nothing about it can be read.
 */
final readonly class OrderSupply
{
    /** @param  list<OrderItem>  $lines */
    private function __construct(
        /** The lines the document states, in the order they were billed. */
        public array $lines,
        /** What the lines come to, never below zero, as the order's own total never is. */
        public Money $gross,
    ) {}

    /** The supply of this order, with top-ups of the given kind. */
    public static function of(Order $order, VoucherInstrumentType $topUps): self
    {
        /** @var list<OrderItem> $items */
        $items = array_values($order->items()->orderBy('id')->get()->all());
        $lines = [];
        $sum = 0;

        foreach ($items as $item) {
            $line = $item->type === OrderItemType::Credit ? self::taxedAtIssue($order, $item, $topUps) : $item;

            if ($line instanceof OrderItem) {
                $lines[] = $line;
                $sum += $line->total_minor;
            }
        }

        return new self($lines, Money::of(max(0, $sum), $order->currency));
    }

    /**
     * The part of a credit line the document keeps: what top-ups taxed when they were bought paid for, or nothing.
     *
     * A copy that is never saved, because the order keeps the line as it was spent.
     */
    private static function taxedAtIssue(Order $order, OrderItem $credit, VoucherInstrumentType $topUps): ?OrderItem
    {
        if (! $topUps->taxedAtIssue()) {
            return null;
        }

        // The newest, because a cycle retried after a refused charge spent its credit again: the earlier spend was
        // given back, and its line went with it.
        $spend = CreditLedgerEntry::model()::query()
            ->where('reason', CreditReason::ChargeOffset)
            ->where('source_type', $order->getMorphClass())
            ->where('source_id', $order->getKey())
            ->orderByDesc('id')
            ->first();

        if (! $spend instanceof CreditLedgerEntry) {
            return $credit;
        }

        /** @var list<CreditLedgerEntry> $history */
        $history = array_values(CreditLedgerEntry::model()::query()
            ->where('owner_type', $spend->owner_type)
            ->where('owner_id', $spend->owner_id)
            ->where('currency', $spend->currency)
            ->orderBy('id')
            ->get()
            ->all());

        $paid = min(
            CreditConsumption::topUpShare(CreditConsumption::of($spend, $history), $spend->currency)->minorUnits,
            abs($credit->total_minor),
        );

        if ($paid < 1) {
            return null;
        }

        $line = $credit->replicate();
        $line->forceFill(['quantity' => 1, 'unit_price_minor' => -$paid, 'total_minor' => -$paid]);

        return $line;
    }
}
