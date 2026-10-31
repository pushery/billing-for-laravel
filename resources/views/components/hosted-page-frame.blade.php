{{--
    Opens a provider's page in the top window when the account screen runs in a frame.

    An app inside the Shopify admin runs in a frame, and the page where the merchant approves a charge does not load
    in one. The plan screen redirects as usual, which moves only the frame, and dispatches `billing-hosted-page` with
    the same address beside the redirect. Inside a frame this listener opens that address in the top window; outside
    one it does nothing, and the redirect does the work.

    A file of the application's own origin, `AccountScript::HostedPage`, for the reason the toast region gives: a host
    policy with `script-src 'self'` and no `'unsafe-inline'` runs it, where it dropped an inline script.
--}}
<script src="{{ \Pushery\Billing\Enums\AccountScript::HostedPage->url() }}" defer></script>
