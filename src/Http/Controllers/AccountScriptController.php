<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Controllers;

use Illuminate\Http\Response;
use Pushery\Billing\Enums\AccountScript;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the account hub's client scripts as files of the application's own origin, see {@see AccountScript}.
 *
 * A route rather than a published asset: a script that has to be published is missing on every host that never
 * ran the command, and these files exist so that the hub works under a strict policy without the host doing
 * anything.
 */
final class AccountScriptController
{
    public function __invoke(string $script): Response
    {
        $file = AccountScript::tryFrom($script) ?? throw new NotFoundHttpException;

        return new Response($file->source(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Private, because the route runs in the hub's middleware and the response leaves with the session
            // cookie: a shared cache that kept it could hand that cookie to the next visitor. The address carries
            // a digest of the bytes, so the browser may keep its copy for good.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
