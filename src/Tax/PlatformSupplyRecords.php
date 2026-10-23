<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use Pushery\Billing\Contracts\SuppliesConsignmentNumbers;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\MerchantPartyUnavailable;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\GoodsConsignment;
use Pushery\Billing\ValueObjects\Money;

/**
 * The records the platform keeps of the supplies it facilitates without being treated as their supplier, one row per
 * routed sale.
 *
 * A platform that facilitates supplies through its interface keeps records of them (Art. 242a VAT Directive), and
 * Art. 54c(2) of Implementing Regulation 282/2011 lists what they hold: who the supplier is, with their VAT number and
 * their bank or virtual account, and of each supply its description, value, place and time, the order or transaction
 * number, and for goods the consignment number. Under intermediation the platform issues no document of the seller's
 * sale, so each row is read from the sale's own row, where the lane froze the goods and where they went, and from the
 * application, which holds who its sellers are.
 *
 * A supply the platform is treated as making itself is not here: it has records of its own, {@see DeemedSupplyRecords}.
 * Neither is a sale under the deemed-supplier posture, where the platform is the supplier of what it sells.
 *
 * ## What the columns are
 *
 * - `supplier`, `supplier_name`, `supplier_address` and `supplier_vat_id`: the merchant, and the party the
 *   application's {@see MerchantPartyResolver} names for them; empty where none is bound or the merchant was erased.
 * - `supplier_account`: the IBAN of that party where it names one, otherwise the account the merchant holds at the
 *   payment provider, which is the virtual account the money was sent to.
 * - `kind`, `description` and `quantity`: goods or services; for goods the description and quantity of the
 *   consignment, for services the kind of thing sold.
 * - `value`, `refunded` and `currency`: what the buyer paid and what was given back since.
 * - `supplied_on`: the day of the sale.
 * - `destination`: where the goods were sent or the buyer was.
 * - `order_reference`: the payment the sale was made with, which identifies the transaction.
 * - `consignment_number`: the number the goods shipped under, where {@see SuppliesConsignmentNumbers} is bound.
 */
final readonly class PlatformSupplyRecords
{
    /** The columns of a row, in the order they are written. */
    public const array COLUMNS = [
        'order_reference', 'supplied_on', 'supplier', 'supplier_name', 'supplier_address', 'supplier_vat_id',
        'supplier_account', 'kind', 'description', 'quantity', 'value', 'refunded', 'currency', 'destination',
        'consignment_number',
    ];

    /** How many sales one read holds. */
    private const int PAGE = 500;

    public function __construct(private MerchantPartyResolver $parties) {}

    /**
     * Every routed sale the platform facilitated between the two moments without being its supplier, oldest first.
     *
     * @return LazyCollection<int, array<string, string>>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): LazyCollection
    {
        $container = Container::getInstance();
        $numbers = null;

        if ($container->bound(SuppliesConsignmentNumbers::class)) {
            $numbers = $container->make(SuppliesConsignmentNumbers::class);
        }

        return MerchantCharge::model()::query()
            ->whereNull('deemed_supply')
            ->whereIn('seller_posture', [SellerOfRecordPosture::PlatformIntermediary->value, SellerOfRecordPosture::SellerOfRecord->value])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(self::PAGE)
            ->map(fn (MerchantCharge $charge): array => $this->rowFor($charge, $numbers));
    }

    /**
     * The row of one sale.
     *
     * @return array<string, string>
     */
    private function rowFor(MerchantCharge $charge, ?SuppliesConsignmentNumbers $numbers): array
    {
        $goods = $charge->tax_archetype === TaxArchetype::ConsumerGoods;
        $consignment = $charge->goodsConsignment();
        $party = $this->partyOf($charge);
        $consignmentNumber = null;

        if ($goods && $numbers instanceof SuppliesConsignmentNumbers) {
            $consignmentNumber = $numbers->consignmentNumberFor($charge->provider, $charge->charge_reference);
        }

        return [
            'order_reference' => $charge->provider.':'.$charge->charge_reference,
            'supplied_on' => $charge->created_at?->toDateString() ?? '',
            'supplier' => $charge->merchant_erased_at !== null ? '' : $charge->merchant_type.'#'.$charge->merchant_id,
            'supplier_name' => $party->name ?? '',
            'supplier_address' => $party instanceof Party ? "{$party->address}, {$party->postcode} {$party->city}, {$party->country}" : '',
            'supplier_vat_id' => (string) $party?->vatId,
            'supplier_account' => $party->iban ?? $this->accountOf($charge),
            'kind' => $goods ? 'goods' : 'services',
            'description' => $consignment->description ?? $charge->tax_archetype->value ?? '',
            'quantity' => $goods && $consignment instanceof GoodsConsignment ? (string) $consignment->quantity : '',
            'value' => new Money($charge->gross_minor, $charge->currency)->toDecimal(),
            'refunded' => new Money($charge->refunded_minor, $charge->currency)->toDecimal(),
            'currency' => $charge->currency,
            'destination' => (string) $charge->destination_country,
            'consignment_number' => (string) $consignmentNumber,
        ];
    }

    /** Who the application says the merchant is, or null where it says nothing or the merchant was erased. */
    private function partyOf(MerchantCharge $charge): ?Party
    {
        $merchant = $charge->merchant;

        if (! $merchant instanceof Model) {
            return null;
        }

        try {
            return $this->parties->partyFor($merchant);
        } catch (MerchantPartyUnavailable) {
            return null;
        }
    }

    /** The account the merchant holds at the payment provider, as the provider names it, or nothing. */
    private function accountOf(MerchantCharge $charge): string
    {
        // An erased merchant leaves the pair null, and a query for null would ask for an account that names
        // no merchant rather than for this merchant's.
        if ($charge->merchant_type === null || $charge->merchant_id === null) {
            return '';
        }

        $account = MerchantAccount::model()::query()
            ->where('merchant_type', $charge->merchant_type)
            ->where('merchant_id', $charge->merchant_id)
            ->where('provider', $charge->provider)
            ->first();

        return $account instanceof MerchantAccount ? "{$account->provider}:{$account->account_reference}" : '';
    }
}
