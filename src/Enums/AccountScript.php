<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

use Illuminate\Support\Facades\URL;

/**
 * The account hub's client scripts, each served as a file of the application's own origin by
 * `AccountScriptController`.
 *
 * Files rather than inline `<script>` tags, because a host may send a policy of its own: under `script-src 'self'`
 * without `'unsafe-inline'` a browser drops an inline script unless its tag carries the host's nonce, which this
 * package does not know, and it loads a file of the same origin. A policy built on `'strict-dynamic'` ignores
 * `'self'`, so there the host allows the files itself.
 *
 * The sources are PHP constants because this package ships no JavaScript build and publishes no assets: a constant
 * is in place the moment the package is installed, and nothing has to be built or published first. Every value a
 * script needs comes from a `data-` attribute of the markup, so each file is the same for every page and every
 * locale, and its address carries a digest of its bytes.
 */
enum AccountScript: string
{
    /** The listener of the minimal toast region, `billing::components.toast-region`. */
    case ToastRegion = 'toast-region';

    /** Opens a provider's page in the top window when the hub runs in a frame, `billing::components.hosted-page-frame`. */
    case HostedPage = 'hosted-page';

    /**
     * Appends each `wirekit-toast` to the region of its urgency and removes it after eight seconds.
     *
     * The severities are the values of `ToastLevel`, read as data rather than branched on, so a level this file
     * does not know lands in the polite region with the neutral style instead of losing the message. The label of
     * the dismiss button is read off the region's container when a toast arrives, in the locale of the page on
     * screen.
     */
    private const string TOAST_REGION = <<<'JS'
        (function () {
            // Once per page load. `wire:navigate` swaps the body and runs the scripts in it again, and a listener on
            // `window` outlives the swap: registered on every screen, one toast showed once per screen visited. The
            // listener looks its regions up when a toast arrives, so it writes into the body on screen.
            if (window.__billingToastRegion) {
                return;
            }

            window.__billingToastRegion = true;

            var STYLES = {
                'info': 'bg-slate-800 text-white',
                'success': 'bg-emerald-700 text-white',
                'warning': 'bg-amber-700 text-white',
                'danger': 'bg-red-700 text-white',
            };

            var URGENCY = { 'info': 'polite', 'success': 'polite', 'warning': 'assertive', 'danger': 'assertive' };

            window.addEventListener('wirekit-toast', function (event) {
                var detail = (event && event.detail) || {};
                var message = typeof detail.message === 'string' ? detail.message : '';

                if (message === '') {
                    return;
                }

                var variant = Object.prototype.hasOwnProperty.call(URGENCY, detail.variant) ? detail.variant : 'info';
                var region = document.getElementById('billing-toasts-' + URGENCY[variant]);

                if (!region) {
                    return;
                }

                var holder = region.closest('[data-billing-toast-dismiss]');
                var label = holder ? holder.getAttribute('data-billing-toast-dismiss') : '';

                var toast = document.createElement('div');
                toast.className = 'pointer-events-auto max-w-md rounded-lg px-4 py-3 text-sm shadow-lg ' + STYLES[variant];
                // textContent, never innerHTML: a broadcast payload is untrusted, and the bridge clamps only the
                // severity. The message is whatever the application sent, so it is written as text.
                toast.textContent = message;

                var dismiss = document.createElement('button');
                dismiss.type = 'button';
                dismiss.className = 'ml-3 underline';
                dismiss.textContent = label || 'Dismiss';
                dismiss.addEventListener('click', function () {
                    toast.remove();
                });
                toast.appendChild(dismiss);

                region.appendChild(toast);

                window.setTimeout(function () {
                    toast.remove();
                }, 8000);
            });
        })();
        JS;

    /**
     * Opens the address of a `billing-hosted-page` event in the top window, and only inside a frame: outside one
     * the redirect that goes with the event does the work.
     */
    private const string HOSTED_PAGE = <<<'JS'
        (function () {
            // Once per page load, as in the toast region: `wire:navigate` runs the scripts of a swapped body again.
            if (window.__billingHostedPage) {
                return;
            }

            window.__billingHostedPage = true;

            window.addEventListener('billing-hosted-page', function (event) {
                var url = event && event.detail ? event.detail.url : null;

                if (window.top === window.self || typeof url !== 'string' || !/^https?:\/\//.test(url)) {
                    return;
                }

                window.open(url, '_top');
            });
        })();
        JS;

    /** The script's bytes. */
    public function source(): string
    {
        return match ($this) {
            self::ToastRegion => self::TOAST_REGION,
            self::HostedPage => self::HOSTED_PAGE,
        };
    }

    /** The first sixteen hex digits of the script's SHA-256: a changed script has a new address. */
    public function version(): string
    {
        return substr(hash('sha256', $this->source()), 0, 16);
    }

    /** The address the markup loads the script from, with its version. */
    public function url(): string
    {
        return URL::route('billing.account.script', ['script' => $this->value, 'v' => $this->version()]);
    }
}
