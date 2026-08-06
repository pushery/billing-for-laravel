<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Contracts\SellerOfRecordResolver;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\Exceptions\PostureNotPermitted;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * The configured default seller-of-record posture actually resolves.
 *
 * The posture decides who the buyer contracts with and who owes the VAT, and the resolver refuses one that
 * is unknown, outside the opt-in whitelist, or that names the merchant for an electronic supply without a
 * genuine rebuttal. Every one of those refusals happens on the FIRST routed sale otherwise — mid-checkout,
 * with money already in flight. This asks the resolver the same question before anything is sold.
 *
 * It asks through the bound resolver rather than re-reading the config, so a consumer who replaced the
 * resolver is checked against their own rules and not against the shipped ones.
 *
 * Not waivable: a posture that cannot resolve cannot be made to resolve by waiving the point, and the sale
 * would fail anyway — later, and in front of a buyer.
 */
final readonly class SellerOfRecordPostureCheckpoint implements GoLiveCheckpoint
{
    public function __construct(
        private Repository $config,
        private SellerOfRecordResolver $resolver,
    ) {}

    public function key(): string
    {
        return 'configuration.seller_of_record';
    }

    public function step(): GoLiveStep
    {
        return GoLiveStep::Configuration;
    }

    public function isBlocking(): bool
    {
        return true;
    }

    public function isWaivable(): bool
    {
        return false;
    }

    public function evaluate(): CheckpointOutcome
    {
        $electronic = (bool) $this->config->get('billing.marketplace.seller_of_record.supplies_are_electronic', true);

        try {
            $posture = $this->resolver->resolveFor($electronic);
        } catch (PostureNotPermitted $e) {
            return CheckpointOutcome::fail($e->getMessage());
        }

        $supply = $electronic ? 'an electronically-supplied service' : 'a non-electronic supply';

        return CheckpointOutcome::pass(
            "The default seller-of-record posture [{$posture->value}] resolves for {$supply}."
        );
    }
}
