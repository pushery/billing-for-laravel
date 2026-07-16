<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;

/**
 * Resolves a Stripe customer reference back to the local billing owner by looking it up on the
 * configured customer model's reference column (Cashier stores it in `stripe_id`). This is the seam
 * the webhook effects use to act on the right account. It fails soft — an unconfigured model, a
 * misconfigured class, or an unknown reference all return null rather than throwing, so a clone
 * without a billable model still boots and a stray webhook is simply ignored.
 */
final readonly class StripeCustomerDirectory implements CustomerDirectory
{
    public function __construct(private Repository $config) {}

    public function ownerForReference(string $customerReference): ?Model
    {
        $model = $this->config->get('billing.customer.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return null;
        }

        $column = $this->config->get('billing.customer.column', 'stripe_id');
        $column = is_string($column) ? $column : 'stripe_id';

        return $model::query()->where($column, $customerReference)->first();
    }
}
