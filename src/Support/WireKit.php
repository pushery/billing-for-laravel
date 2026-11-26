<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\View;

/**
 * Whether WireKit (pushery/wirekit) is in use, for the screens that ship a WireKit variant.
 *
 * Asked of the view factory rather than of the autoloader: a `<x-wirekit::…>` tag resolves through WireKit's view
 * namespace, which its service provider registers. So the answer is yes where the components resolve, and no where
 * WireKit is absent or installed without its provider, as in an application that keeps it out of package discovery.
 * The package never imports a WireKit symbol and loads without it.
 *
 * A WireKit below {@see MINIMUM} counts as not in use, so the plain variant renders: the WireKit variants pass props an
 * older release does not have, and a Blade tag compiles against whatever the installed kit offers.
 */
final class WireKit
{
    /**
     * The oldest `pushery/wirekit` the WireKit variants are proven against: the lower bound of the development
     * constraint, which the lane with the lowest dependencies installs. It moves with that constraint, and a test holds
     * the two together.
     */
    public const string MINIMUM = '2.74.1'; // renovate: datasource=packagist depName=pushery/wirekit

    public static function active(): bool
    {
        return View::exists('wirekit::components.stack') && self::satisfies(self::installedVersion());
    }

    /**
     * Whether a version string clears the floor. A named branch, reported as `dev-…`, and no answer at all count as
     * satisfied: somebody developing against a branch has chosen it. A branch named like a version, reported as
     * `2.x-dev` or `2.73.x-dev`, sits above every release of its line, so its `x` compares as the highest number there
     * is: `2.x-dev` clears the floor and `2.73.x-dev` does not.
     */
    public static function satisfies(?string $version): bool
    {
        if (! is_string($version) || str_starts_with($version, 'dev-')) {
            return true;
        }

        $release = (string) preg_replace('/\.x-dev$/D', '.'.PHP_INT_MAX, ltrim($version, 'vV'));

        return version_compare($release, self::MINIMUM, '>=');
    }

    /** What Composer says is installed, or null where it cannot say, as where something else put WireKit on the autoloader. */
    private static function installedVersion(): ?string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('pushery/wirekit')
            ? InstalledVersions::getPrettyVersion('pushery/wirekit')
            : null;
    }
}
