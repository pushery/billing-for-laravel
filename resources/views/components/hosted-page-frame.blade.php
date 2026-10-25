{{--
    Opens a provider's page in the top window when the account screen runs in a frame.

    An app inside the Shopify admin runs in a frame, and the page where the merchant approves a charge does not load
    in one. The plan screen redirects as usual, which moves only the frame, and dispatches `billing-hosted-page` with
    the same address beside the redirect. Inside a frame this listener opens that address in the top window; outside
    one it does nothing, and the redirect does the work.

    Inline for the reason the toast region gives: this package ships no JavaScript build.
--}}
<script>
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
</script>
