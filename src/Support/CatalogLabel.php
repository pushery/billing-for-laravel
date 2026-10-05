<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\Facades\Lang;

/**
 * A tier or add-on name from the configuration, in the reader's language where the application translates it.
 *
 * The name is the application's own string, and the configuration is built before any locale exists, so the name
 * is translated where it is shown: the application's translations are asked for it, its JSON file of the locale
 * first. A name nobody translates comes back as it is, so a single-language application sees no change.
 *
 * A name can also coincide with a translation group, as the key `pro` does with a `lang/en/pro.php`. The
 * translator then answers with that whole group as an array, and the name comes back as it is instead.
 */
final class CatalogLabel
{
    public static function translate(string $label): string
    {
        $translated = Lang::get($label);

        return is_string($translated) ? $translated : $label;
    }
}
