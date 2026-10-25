<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Shopify;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Models\SubscriptionIntent;

/**
 * What the app knows about one Shopify subscription, found by its id: whose it is, the tier it was bought for,
 * the declarations made for it, and the end a cancellation set.
 *
 * A Shopify subscription carries nothing of the app's but a name, so this is the only way from a webhook back to
 * the owner and the tier. The local subscription row answers once the subscription has been reported; before
 * that, the intent the checkout recorded under the same id does.
 */
final readonly class ShopifySubscriptionRecord
{
    private function __construct(
        public Model $owner,
        public ?string $tierKey,
        public ?string $declarationReference,
        public ?CarbonInterface $endsAt,
    ) {}

    /** The record of the subscription with this id, or null where the app never opened it. */
    public static function find(string $id): ?self
    {
        $row = Subscription::model()::query()
            ->where('provider', 'shopify')
            ->where('provider_id', $id)
            ->first();

        if ($row instanceof Subscription) {
            $owner = self::ownerOf($row->owner_type, $row->owner_id);

            return $owner instanceof Model ? new self($owner, $row->tier_key, $row->declaration_reference, $row->ends_at) : null;
        }

        $intent = SubscriptionIntent::model()::query()
            ->where('provider', 'shopify')
            ->where('payment_reference', $id)
            ->orderByDesc('id')
            ->first();

        if (! $intent instanceof SubscriptionIntent) {
            return null;
        }

        $owner = self::ownerOf($intent->owner_type, $intent->owner_id);

        return $owner instanceof Model ? new self($owner, $intent->tier_key, $intent->declaration_reference, null) : null;
    }

    /** The owner a row of the app names by its morph columns, or null where that owner no longer exists. */
    public static function ownerOf(string $type, int|string $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = $class::query()->find($id);

        return $owner instanceof Model ? $owner : null;
    }
}
