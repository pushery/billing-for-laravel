<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Pushery\Billing\Contracts\InternalBalance;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TransferResult;

/**
 * The merchant's share of a sale paid from an internal balance, moved by crediting the merchant's balance.
 *
 * It stands where a provider transfer stands on a separate-transfer sale, so the share is held, retried and
 * released by the same code as a share a provider moves: behind withheld payouts, behind the buyer protection,
 * and after a credit that failed. The destination names the merchant instead of an account at a provider.
 */
final readonly class InternalBalanceShares implements MovesMerchantShare
{
    public function __construct(private InternalBalance $balance) {}

    /** Where the share of a sale this merchant made moves to. */
    public static function destinationFor(Model $merchant): MerchantAccountReference
    {
        $key = $merchant->getKey();

        return new MerchantAccountReference(
            provider: InternalBalance::PROVIDER,
            accountId: $merchant->getMorphClass().'#'.(is_scalar($key) ? (string) $key : ''),
            chargesEnabled: true,
            payoutsEnabled: true,
            detailsSubmitted: true,
        );
    }

    /** Credits the merchant the destination names, under the key of the sale the share comes from. */
    public function transferShare(MerchantAccountReference $destination, Money $amount, string $sourceCharge, ?string $idempotencyKey = null): TransferResult
    {
        return new TransferResult(
            reference: $this->balance->credit($this->merchantOf($destination), $amount, $idempotencyKey ?? 'share:'.$sourceCharge),
            moved: $amount,
        );
    }

    /** The merchant a destination from {@see self::destinationFor()} names. */
    private function merchantOf(MerchantAccountReference $destination): Model
    {
        $separator = strrpos($destination->accountId, '#');

        if ($destination->provider !== InternalBalance::PROVIDER || $separator === false) {
            throw new InvalidArgumentException("A share paid from an internal balance moves to a merchant's balance, not to the [{$destination->provider}] account {$destination->accountId}.");
        }

        $type = substr($destination->accountId, 0, $separator);
        $class = Relation::getMorphedModel($type) ?? $type;
        $merchant = is_a($class, Model::class, true) ? $class::query()->find(substr($destination->accountId, $separator + 1)) : null;

        if (! $merchant instanceof Model) {
            throw new InvalidArgumentException("No merchant answers to {$destination->accountId}, so the share has nowhere to go.");
        }

        return $merchant;
    }
}
