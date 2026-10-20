<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Events\SellerDeclarationContradicted;
use Pushery\Billing\Exceptions\SellerDeclarationMissing;
use Pushery\Billing\Models\SellerDeclaration;
use Pushery\Billing\Models\SellerDeclarationContradiction;
use Pushery\Billing\ValueObjects\DeclaredSeller;

/**
 * What sellers outside the Union declared to the platform, and what the platform has observed against it since.
 *
 * A platform treated as supplying the goods it facilitates (Art. 14a VAT Directive) is not liable beyond the tax it
 * declared and paid where it relied on what the seller told it and neither knew nor could have known that it was wrong
 * (Art. 5c Implementing Regulation 282/2011). It carries the burden of showing all three, so this keeps each declaration
 * as the seller confirmed it, and each signal that contradicted one with the moment it arrived. Neither is ever changed.
 *
 * The application takes the declaration on its own seller screens and records it here, and it reports a contradicting
 * signal it comes across, such as a delivery address of the seller inside the Union. The declaration in force is the
 * newest, and a contradiction observed after it holds the seller's next sale of such goods until the seller declares again.
 */
final readonly class SellerDeclarations
{
    public function __construct(private Dispatcher $events) {}

    /**
     * Keep what a seller declared, with the statement it confirmed and the moment it confirmed it.
     *
     * The statement is the text the seller read and confirmed, in the language it was shown in, and its SHA-256 hash is
     * kept beside it so the record can show the text was not changed later.
     */
    public function record(Model $seller, DeclaredSeller $declared, string $statement, string $locale, CarbonImmutable $declaredAt): SellerDeclaration
    {
        if (trim($statement) === '') {
            throw new InvalidArgumentException('A seller declaration keeps the statement the seller confirmed, and an empty one confirms nothing.');
        }

        return SellerDeclaration::model()::query()->create([
            'merchant_type' => $seller->getMorphClass(),
            'merchant_id' => $seller->getKey(),
            'established_in' => $declared->establishedIn,
            'tax_numbers' => $declared->taxNumbers,
            'goods' => $declared->goods,
            'dispatched_from' => $declared->dispatchedFrom,
            'statement' => $statement,
            'statement_hash' => hash('sha256', $statement),
            'locale' => $locale,
            'declared_at' => $declaredAt,
        ]);
    }

    /**
     * Keep a signal that contradicts what the seller declared, and tell the operator.
     *
     * @param  string  $signal  what was observed, as a short key the application chooses, such as `delivery_address_in_union`
     * @param  ?string  $detail  what the application wants on record beside it
     */
    public function contradict(Model $seller, string $signal, CarbonImmutable $observedAt, ?string $detail = null): SellerDeclarationContradiction
    {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $signal) !== 1) {
            throw new InvalidArgumentException("A contradicting signal is named by a short key of lower-case letters, digits, dots, dashes and underscores; got [{$signal}].");
        }

        $contradiction = SellerDeclarationContradiction::model()::query()->create([
            'merchant_type' => $seller->getMorphClass(),
            'merchant_id' => $seller->getKey(),
            'signal' => $signal,
            'detail' => $detail,
            'observed_at' => $observedAt,
        ]);

        $this->events->dispatch(new SellerDeclarationContradicted($seller, $contradiction));

        return $contradiction;
    }

    /** The declaration in force for a seller: the newest, or none where the seller never declared. */
    public function current(Model $seller): ?SellerDeclaration
    {
        return $this->newest($seller);
    }

    /**
     * The declaration the platform may rely on for a seller's next sale.
     *
     * @throws SellerDeclarationMissing where the seller never declared, or a signal observed after its newest declaration
     *                                  contradicts it
     */
    public function assertDeclared(Model $seller): SellerDeclaration
    {
        $declaration = $this->newest($seller);
        $key = $seller->getKey();
        $name = $seller->getMorphClass().':'.(is_scalar($key) ? (string) $key : '');

        if (! $declaration instanceof SellerDeclaration) {
            throw SellerDeclarationMissing::forSeller($name);
        }

        $contradiction = SellerDeclarationContradiction::model()::query()
            ->where('merchant_type', $seller->getMorphClass())
            ->where('merchant_id', $key)
            ->where('observed_at', '>', $declaration->declared_at)
            ->orderBy('observed_at')
            ->first();

        if ($contradiction instanceof SellerDeclarationContradiction) {
            throw SellerDeclarationMissing::contradicted($name, $contradiction->signal, CarbonImmutable::instance($contradiction->observed_at));
        }

        return $declaration;
    }

    private function newest(Model $seller): ?SellerDeclaration
    {
        return SellerDeclaration::model()::query()
            ->where('merchant_type', $seller->getMorphClass())
            ->where('merchant_id', $seller->getKey())
            ->orderByDesc('declared_at')
            ->orderByDesc('id')
            ->first();
    }
}
