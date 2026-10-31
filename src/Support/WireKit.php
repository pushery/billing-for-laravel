<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\Facades\View;

/**
 * Whether WireKit (pushery/wirekit) is in use, for the screens that ship a WireKit variant.
 *
 * Asked of the view factory rather than of the autoloader: a `<x-wirekit::…>` tag resolves through WireKit's view
 * namespace, which its service provider registers. So the answer is yes where the components resolve, and no where
 * WireKit is absent or installed without its provider, as in an application that keeps it out of package discovery.
 * The package never imports a WireKit symbol and loads without it.
 */
final class WireKit
{
    public static function active(): bool
    {
        return View::exists('wirekit::components.stack');
    }
}
