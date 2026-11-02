<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\VatIdValidator;
use Pushery\Billing\Enums\CustomerAudience;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Enums\VatIdValidation;
use Pushery\Billing\Models\CustomerDeclaration;
use Pushery\Billing\ValueObjects\CustomerStatement;

/**
 * Where a customer's declarations about themselves are written down and read back.
 *
 * ## A history, not a field
 *
 * Every declaration is a row of its own, and none is ever changed. A customer who first bought as a consumer and later
 * registers a company has said both things, at two moments, and a sale made in between was made under the first. A
 * field on the customer would keep only the second and lose the evidence of which rules applied to the first sale.
 *
 * ## The VAT number is checked when it is declared
 *
 * A business that gives a VAT number has it put to the bound {@see VatIdValidator}, and the answer is kept on the row
 * with its moment. A register that is unavailable is an answer too, `unavailable`, and it does not stop the
 * declaration: whether a sale may rest on an unconfirmed number is the tax path's question, not this one's.
 *
 * ## What is refused
 *
 * A consumer declaration in an application that sells only to businesses. There the application shows the restriction
 * and has it confirmed, and what the customer confirms is that they buy as a business.
 */
final readonly class CustomerDeclarations
{
    public function __construct(
        private Repository $config,
        private VatIdValidator $vatIds,
    ) {}

    /**
     * Write a declaration down, and hand back the row.
     *
     * @param  string|null  $reference  the key the purchase declarations of the same checkout were taken under
     *
     * @throws InvalidArgumentException when a consumer declares in an application that sells only to businesses
     */
    public function declare(Model $owner, CustomerStatement $statement, ?string $reference = null): CustomerDeclaration
    {
        $audience = CustomerAudience::configured($this->config);

        if ($audience === CustomerAudience::BusinessOnly && $statement->type === CustomerType::Consumer) {
            throw new InvalidArgumentException('billing.audience is business_only, so a customer declares as a business: the '
                .'application states that it sells only to businesses and has the customer confirm it.');
        }

        $declaredAt = $statement->declaredAt ?? CarbonImmutable::now('UTC');
        $validation = $statement->vatId === null ? null : $this->vatIds->validate($statement->vatId);

        return CustomerDeclaration::model()::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'audience' => $audience,
            'customer_type' => $statement->type,
            'statement' => $statement->statement,
            'statement_version' => $statement->statementVersion,
            'business_name' => $statement->businessName,
            'business_address' => $statement->businessAddress,
            'vat_id' => $statement->vatId,
            'vat_id_validation' => $validation,
            'vat_id_checked_at' => $validation instanceof VatIdValidation ? CarbonImmutable::now('UTC') : null,
            'renewal_terms_consented' => $statement->renewalTermsConsented,
            'renewal_terms_statement' => $statement->renewalTermsStatement,
            'reference' => $reference,
            'declared_at' => $declaredAt,
        ]);
    }

    /**
     * The declaration in force for this customer, which is the latest, or null where they declared nothing.
     *
     * Ordered by the moment declared and then by the row, so two declarations in the same second still have one latest.
     */
    public function current(Model $owner): ?CustomerDeclaration
    {
        $latest = $this->ofOwner($owner)->orderByDesc('declared_at')->orderByDesc('id')->first();

        return $latest instanceof CustomerDeclaration ? $latest : null;
    }

    /**
     * Everything this customer declared, oldest first.
     *
     * @return Collection<int, CustomerDeclaration>
     */
    public function history(Model $owner): Collection
    {
        /** @var Collection<int, CustomerDeclaration> */
        return $this->ofOwner($owner)->orderBy('declared_at')->orderBy('id')->get();
    }

    /** @return Builder<CustomerDeclaration> */
    private function ofOwner(Model $owner): Builder
    {
        return CustomerDeclaration::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }
}
