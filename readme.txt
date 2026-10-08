=== Zhayon Funnel ===
Contributors: zhayon
Tags: woocommerce, funnel, checkout, analytics, conversion
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

قیف خرید سبک برای ووکامرس: ریزش خریداران از سبد تا پرداخت، به تفکیک دستگاه، منبع ورودی و درگاه.

== Description ==

Zhayon Funnel shows where shoppers drop out of the purchase flow
(cart → login → checkout → order → payment), split by device, traffic source
and payment gateway. Report: WooCommerce → «قیف خرید».

It is built to cost (almost) nothing:

* No event log. One table of pre-aggregated daily counters,
  `{prefix}zhf_funnel_daily`, keyed by (day, step, device, source, detail).
* No front-end JavaScript or CSS. Everything is captured server-side from
  WordPress, WooCommerce and REST hooks.
* Visit state lives in one small first-party cookie (`zhf_v`), not in the
  database. A step counts as a "unique" once per visit per day; repeats only
  bump a `hits` counter on the same row.
* At most one indexed upsert per tracked request. Home, product, shop and every
  other non-cart/checkout page run zero plugin queries; the tracking classes are
  not even loaded there.
* No personal data: no names, phones, emails, addresses, IPs or user IDs.
* Works with Classic and Block (Store API) cart/checkout, HPOS, page caches
  (the cookie is only set on cart/checkout and tracked REST/AJAX calls).

= Steps =

* `cart_view` – cart page viewed with a non-empty cart.
* `checkout_gate` – a guest reached checkout but must log in first (guest
  checkout disabled, or a login plugin redirected them).
* `otp_sent` – a one-time code was sent (from an integration or the public API).
* `login` – login (`login`) or sign-up (`register`), counted only for visits
  that came through cart/checkout. Failed attempts are recorded with their
  error code.
* `checkout_view` – checkout viewed by someone who can actually check out.
* `place_order` – «ثبت سفارش» submitted (detail = gateway id).
* `checkout_error` – checkout validation failed (detail = first error code / field).
* `order_created` – order created (detail = gateway id).
* `paid` / `payment_failed` – order status left pending/failed for
  processing/completed/on-hold, or pending for failed/cancelled. Counted once
  per order against the visit stored on the order (`_zhf_ctx`).
* `coupon_apply` – `ok` / `invalid` (secondary; not a funnel step).

== Developers ==

= Report a step from your theme or plugin =

Any OTP / login plugin can report its own outcomes:

    do_action( 'zhf_track', 'otp_sent', 'ok' );            // code sent
    do_action( 'zhf_track', 'otp_sent', 'rate_limited' );  // send failed (error code)
    do_action( 'zhf_track', 'login', 'invalid_otp' );      // login failed (error code)

Unknown steps are ignored. `otp_sent` and `login` only count when the visitor
already has the visit cookie (i.e. came from the cart or checkout). Details are
sanitized to a slug of at most 48 characters.

= Filters =

* `zhf_should_track( bool $track, string $step, array $context )` – every
  exclusion rule in one place. By default visit steps skip cron/CLI, HEAD
  requests, prefetch/prerender, empty or bot-like user agents, and users with
  `manage_woocommerce` (unless "track administrators" is on). Order-bound steps
  (`paid`, `payment_failed`) pass `$context['order']` and are gated by the visit
  saved on the order instead.
* `zhf_count_repeat_hits( bool $count, string $step )` – return false to skip
  the `hits` bump on repeat views, so repeat cart/checkout loads write nothing.
* `zhf_admin_font_css_url( string $url )` – return a stylesheet URL (e.g. a
  theme's IRANYekanX fonts.css) to load on the report page. The report's font
  stack is `IRANYekanX, Vazirmatn, Tahoma, sans-serif`; Vazirmatn is bundled.
* `zhf_zhiva_routes( array $routes )` – override the zhiva-panel REST routes the
  integration listens to.
* `zhf_hide_admin_notices( bool $hide )` – the report page hides WordPress and
  other plugins' admin notices (they break its layout); return false to show them.

= Integrations =

* zhiva-panel (auto-enabled when active): records `otp_sent` from
  `POST /zhiva-panel/v1/EveryClient/OTP/sms` and failed logins from
  `POST /zhiva-panel/v1/Auth/login-or-register`.

== Installation ==

1. Upload the `zhayon-funnel` folder (or the release zip) to `wp-content/plugins/`.
2. Activate «Zhayon Funnel». This creates the table and the daily cleanup cron.
3. Open WooCommerce → «قیف خرید». Data starts with the next cart view.

Deactivating or deleting the plugin keeps the data (table and settings); deleting
only removes the cron event and report caches. To erase the funnel statistics,
use «تنظیمات» → «حذف داده‌ها»: an admin types «حذف» (or "delete") to confirm, the
word is checked again on the server, and every counter row is removed. Settings
and order meta (`_zhf_ctx`, `_zhf_counted`) are kept.

== Changelog ==

= 1.0.0 =
* First release.

== Credits ==

Bundles the Vazirmatn font (variable, weights 100–900) by the Vazirmatn
Project Authors, under the SIL Open Font License 1.1 — see
`assets/admin/fonts/OFL.txt`.
