<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\GoLiveChecklist;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Contracts\JurisdictionProfile;
use Pushery\Billing\Exceptions\DuplicateGoLiveCheckpoint;
use Pushery\Billing\Exceptions\UnknownJurisdictionProfile;
use Pushery\Billing\Preflight\Checkpoints\CustodyAttestationCheckpoint;
use Pushery\Billing\Preflight\Checkpoints\DuplicateBuyerReceiptCheckpoint;
use Pushery\Billing\Preflight\Checkpoints\FeeRefundPolicyCheckpoint;
use Pushery\Billing\Preflight\Checkpoints\JurisdictionProfileCheckpoint;
use Pushery\Billing\Preflight\Checkpoints\ReceivingGateCheckpoint;
use Pushery\Billing\Preflight\Checkpoints\RoutingDriverCheckpoint;
use Pushery\Billing\Preflight\Checkpoints\SellerOfRecordPostureCheckpoint;
use Pushery\Billing\Preflight\Checkpoints\TaxStatusHoldCheckpoint;
use Pushery\Billing\Preflight\Profiles\GermanJurisdictionProfile;

/**
 * Everything the go-live checklist is made of: the package's own structural points, the active
 * jurisdiction profile's points, and whatever the consumer added.
 *
 * The three sources are collected lazily, at the moment the checklist runs, so nothing depends on which
 * service provider booted first. A consumer registers its own points from anywhere:
 *
 *     $this->app->make(CheckpointRegistry::class)->add(new MyOwnCheckpoint);
 *
 * The package's own points are deliberately few. A checkpoint is only worth arming when it has a subject
 * that exists today; one written against machinery that has not been built yet can never fail, and a check
 * that cannot fail reads exactly like a check that passed. Later milestones add their own points here as
 * the machinery they describe becomes real.
 */
final class CheckpointRegistry implements GoLiveChecklist
{
    /**
     * The jurisdiction profiles the package ships, by the name `billing.tax_profile` uses.
     *
     * @var array<string, class-string<JurisdictionProfile>>
     */
    private const array SHIPPED_PROFILES = [
        'de' => GermanJurisdictionProfile::class,
    ];

    /** @var list<GoLiveCheckpoint> */
    private array $custom = [];

    private ?JurisdictionProfile $profile = null;

    private bool $profileResolved = false;

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    /** Add consumer-owned points to the checklist. */
    public function add(GoLiveCheckpoint ...$checkpoints): void
    {
        foreach ($checkpoints as $checkpoint) {
            $this->custom[] = $checkpoint;
        }
    }

    /**
     * Every registered point, package first, then profile, then consumer.
     *
     * @return list<GoLiveCheckpoint>
     */
    public function all(): array
    {
        $profile = $this->profile();

        $checkpoints = [
            ...$this->packageCheckpoints($profile),
            ...($profile?->checkpoints() ?? []),
            ...$this->custom,
        ];

        $seen = [];

        foreach ($checkpoints as $checkpoint) {
            $key = $checkpoint->key();

            if (isset($seen[$key])) {
                throw DuplicateGoLiveCheckpoint::key($key);
            }

            $seen[$key] = true;
        }

        return $checkpoints;
    }

    /**
     * The active jurisdiction profile, or null when none is configured.
     *
     * A binding in the container wins over the configured name: binding the contract is the more specific,
     * more deliberate act, and it is how a consumer supplies a jurisdiction the package does not ship.
     */
    public function profile(): ?JurisdictionProfile
    {
        if ($this->profileResolved) {
            return $this->profile;
        }

        $this->profileResolved = true;

        if ($this->container->bound(JurisdictionProfile::class)) {
            return $this->profile = $this->container->make(JurisdictionProfile::class);
        }

        $name = $this->config->get('billing.tax_profile');

        if (! is_string($name) || $name === '') {
            return $this->profile = null;
        }

        if (! isset(self::SHIPPED_PROFILES[$name])) {
            throw UnknownJurisdictionProfile::named($name, array_keys(self::SHIPPED_PROFILES));
        }

        return $this->profile = $this->container->make(self::SHIPPED_PROFILES[$name]);
    }

    /**
     * The points the package itself contributes — structural conditions of its own machinery, never a
     * jurisdiction's obligations.
     *
     * @return list<GoLiveCheckpoint>
     */
    private function packageCheckpoints(?JurisdictionProfile $profile): array
    {
        return [
            new JurisdictionProfileCheckpoint($profile),
            $this->container->make(RoutingDriverCheckpoint::class),
            $this->container->make(ReceivingGateCheckpoint::class),
            $this->container->make(CustodyAttestationCheckpoint::class),
            $this->container->make(SellerOfRecordPostureCheckpoint::class),
            $this->container->make(FeeRefundPolicyCheckpoint::class),
            $this->container->make(TaxStatusHoldCheckpoint::class),
            $this->container->make(DuplicateBuyerReceiptCheckpoint::class),
        ];
    }
}
