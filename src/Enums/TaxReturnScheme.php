<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

use Pushery\Billing\Models\InvoiceRecord;

/**
 * Which special return declares a sale: the one-stop-shop return of the Union scheme, or the import scheme's.
 *
 * Each return reads its own flag frozen on the document and nothing else, so a document belongs to the return
 * whose flag it carries. A sale in neither is declared in the ordinary return of the member state it is taxed in.
 * The value is the type a produced run of the return is recorded under.
 */
enum TaxReturnScheme: string
{
    /** The Union scheme: services to consumers in other member states, distance sales of goods within the Union. */
    case OneStopShop = 'one_stop_shop';

    /** The import scheme: distance sales of goods imported in consignments of up to 150 euros. */
    case ImportScheme = 'import_scheme';

    /** Whether this return declares the document's sale. */
    public function declares(InvoiceRecord $document): bool
    {
        return match ($this) {
            self::OneStopShop => (bool) $document->oss,
            self::ImportScheme => (bool) $document->ioss,
        };
    }
}
