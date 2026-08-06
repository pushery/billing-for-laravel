<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\UsTaxFormStatus;
use Pushery\Billing\Enums\UsTaxFormType;

/**
 * One declaration a seller gave, and when it stops counting.
 *
 * @property int $id
 * @property ?string $merchant_type
 * @property ?string $merchant_id
 * @property UsTaxFormType $form_type
 * @property UsTaxFormStatus $status
 * @property Carbon $signed_on
 * @property ?Carbon $expires_on
 * @property ?string $document_reference
 * @property ?Carbon $merchant_erased_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class UsTaxForm extends Model
{
    protected $table = 'billing_us_tax_forms';

    /** @var list<string> */
    protected $fillable = [
        'merchant_type', 'merchant_id', 'form_type', 'status', 'signed_on', 'expires_on',
        'document_reference', 'merchant_erased_at',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'form_type' => UsTaxFormType::class,
        'status' => UsTaxFormStatus::class,
        'signed_on' => UtcDateTime::class,
        'expires_on' => UtcDateTime::class,
        'merchant_erased_at' => UtcDateTime::class,
    ];
}
