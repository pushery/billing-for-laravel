<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Account-hub route prefix
    |--------------------------------------------------------------------------
    |
    | The URL prefix the published account-hub screens mount under. The whole hub
    | is gated on the billing master switch (billing.enabled) — when billing is
    | off, none of these routes are registered.
    |
    */

    'prefix' => env('BILLING_ACCOUNT_PREFIX', 'account/billing'),

    /*
    |--------------------------------------------------------------------------
    | Route middleware
    |--------------------------------------------------------------------------
    |
    | The middleware stack the account-hub routes run through. The hub shows a
    | signed-in owner their own billing, so it needs the app's web + auth stack.
    |
    */

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    |
    | The Blade layout the full-page hub screens extend. Point this at your app's
    | own layout to frame the hub in your chrome; the default is the package's
    | self-contained layout.
    |
    */

    'layout' => env('BILLING_ACCOUNT_LAYOUT', 'billing::layouts.account'),

    /*
    |--------------------------------------------------------------------------
    | Scoped Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | The account hub emits a scoped CSP so the active driver's payment element
    | (js.stripe.com and friends) loads on the billing screens ONLY, never across
    | the rest of your app. It is on by default for the package's own views.
    |
    | If you frame the hub in your own layout with external assets (fonts, a CDN,
    | analytics), whitelist those origins under "additional" — keyed by directive,
    | e.g. 'font-src' => ['https://fonts.gstatic.com'] — rather than turning the
    | header off. Set "enabled" to false only if your app already sends its own
    | CSP for these routes (browsers enforce every CSP header at once).
    |
    */

    'csp' => [
        'enabled' => env('BILLING_ACCOUNT_CSP', true),
        'additional' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stylesheet
    |--------------------------------------------------------------------------
    |
    | The package's screens are pure Tailwind, so they need a stylesheet to look
    | right. Two supported setups:
    |
    |  (a) Host-owned (recommended): point "layout" above at your own app layout,
    |      and add the package's views to your Tailwind v4 source scan so its
    |      classes are compiled into your CSS:
    |
    |          @source '../../vendor/pushery/billing-for-laravel/resources/views';
    |
    |  (b) Standalone: keep the package's self-contained layout and point this at a
    |      compiled Tailwind stylesheet you serve (a CDN build, or your own
    |      published asset). Leave it null and the standalone layout ships unstyled.
    |
    */

    'stylesheet' => env('BILLING_ACCOUNT_STYLESHEET'),

];
