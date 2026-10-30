# Kitgenix Order Tracking for WooCommerce

## Overview

**Purpose** (from the main plugin file header, `kitgenix-order-tracking-for-woocommerce.php`): *"Add WooCommerce order tracking, multi-shipment support, email tracking links, and a public customer tracking page."*

The plugin lets store staff record one or more shipments (parcels) per WooCommerce order 2.2.0 – 30 October 2026 tracking number, carrier, per-item allocation, shipment status, delivery estimate, and an internal note 2.2.0 – 30 October 2026 and surfaces that information to customers through WooCommerce emails and a self-service public tracking page (`[kitgenix_tracking_form]` shortcode). It explicitly does **not** call any carrier API: it never fetches live delivery scans, never books collections, and never buys shipping labels. All shipment statuses (Shipped, In transit, Delivered, Exception, Returned, Cancelled) are staff-managed values stored in order meta, not live carrier data.

| Property | Value |
|---|---|
| Plugin Name | Kitgenix Order Tracking for WooCommerce |
| Version | 2.2.0 (`KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION`) |
| Requires WordPress | 6.0 (Tested up to 7.4) |
| Requires PHP | 8.2 (`declare(strict_types=1)` used in nearly every PHP file 2.2.0 – 30 October 2026 the two exceptions are `includes/Support/Event_Log.php` and `uninstall.php`) |
| Requires Plugins | WooCommerce (`Requires Plugins: woocommerce`) |
| WC requires at least | 10.0 (Tested up to 11.2.1) |
| License | GPLv3 or later |
| Text Domain | `kitgenix-order-tracking-for-woocommerce` |
| Domain Path | `/languages` |
| Author | Kitgenix (https://kitgenix.com/) |

The plugin refuses to activate without WooCommerce: `register_activation_hook()` in the main file checks `class_exists('WooCommerce')` and, if absent, calls `deactivate_plugins()` and `wp_die()` with a translated notice.

## Architecture

### Folder / file layout

```
kitgenix-order-tracking-for-woocommerce.php   Bootstrap, Kitgenix Hub admin page, autoloader, activation/deactivation hooks
uninstall.php                                  Uninstall cleanup (options + transients only)
readme.txt                                     WordPress.org readme (features, FAQ, external services disclosure, current-release changelog)
CHANGELOG.md                                   Full, human-authored release history (readme.txt lists only the current release)
languages/kitgenix-order-tracking-for-woocommerce.pot   Translation template

includes/Plugin.php                            Main plugin loader (Kitgenix\OrderTracking\Plugin)

includes/Orders/Order_Tracking.php             Shipment persistence, retrieval, search, quantity clamping, change hooks
includes/Orders/Shipment.php                   Shipment value object: normalise/validate a single shipment record

includes/Support/Carriers.php                  Carrier registry, carrier packs, tracking URL builder, carrier auto-detection
includes/Support/Event_Log.php                 Capped activity log (option-backed) with retention/prune logic

includes/Admin/Admin.php                       Admin bootstrap: wires Order_Meta_Box + AdminModule
includes/Admin/AdminModule.php                 Back-compat alias: class_alias(Analytics::class, AdminModule)
includes/Admin/Analytics.php                   The whole "Order Tracking" admin screen: tabs, KPIs, carriers UI, bulk import/export, log tab, settings save handlers (2667 lines)
includes/Admin/Order_Meta_Box.php              Order-edit-screen meta box, "Add tracking" modal markup, AJAX order-items endpoint, meta-box save handler

includes/Emails/Email_Hooks.php                Injects the Shipment Tracking block into WooCommerce emails; triggers the Partially Shipped email on status change
includes/Emails/Email_Partially_Shipped.php    Custom WC_Email subclass: "Kitgenix Partially Shipped"

includes/Frontend/Shortcodes.php               [kitgenix_tracking_form] shortcode: lookup form, results rendering, Leaflet map bootstrap, postcode-gated privacy logic, lookup rate limiting
includes/Frontend/Timeline.php                 Renders the customer-facing shipment status timeline (order placed → shipped → in transit → delivered/exception/returned/cancelled)

templates/emails/partially-shipped.php         HTML template for the custom Partially Shipped email (WooCommerce override pattern)
templates/emails/plain/partially-shipped.php   Plain-text counterpart

assets/js/admin-order-tracking.js              Order-edit screen: Add/Edit tracking modal, order-items AJAX, shipment row rendering
assets/js/admin-analytics.js                   Analytics screen: ranked-list bar widths, custom-carrier modal, WP media picker for carrier icons
assets/js/kitgenix-admin-components.js         Shared component controller: toasts, modal open/close, collapsible cards, copy-to-clipboard, live table search, client-side table pagination
assets/js/kitgenix-admin-tabs.js               Shared topbar/tabs controller: tab switching, URL/hash sync, mobile hamburger menu, dropdown menus, search-in-settings, dark/light theme toggle, notice relocation

assets/css/admin.css                           Plugin-specific admin overrides (stat grid columns, sparkline visibility, etc.) layered on the shared design system
assets/css/frontend.css                        Public tracking-page/shortcode styling
assets/css/kitgenix-admin-ui.css               Shared Kitgenix admin design system (tokens, topbar, cards, tables, modals, toggles, badges) 2.2.0 – 30 October 2026 "kept identical between plugins"
assets/css/kitgenix-hub.css                    Styling for the shared "Kitgenix" hub page (admin.php?page=kitgenix)

assets/vendor/leaflet/leaflet.js, leaflet.css, images/  Bundled Leaflet mapping library (no CDN)
assets/images/carriers/*.svg                   Carrier icon set (8 files: an-post, dhl, dpd-ie, dpd-uk, other, parcelforce, royal-mail, woodside)
assets/images/logos/*.svg                      Kitgenix brand/admin-menu icons
assets/images/social-media/*.svg               Social icon set used in admin headers/sidebars
```

### Bootstrap sequence

1. **`kitgenix-order-tracking-for-woocommerce.php`** loads first. It:
   - Defines constants `KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_FILE`, `_PLUGIN_DIR`, `_VERSION` (`2.2.0`), `_PLUGIN_URL`.
   - Registers a shared, cross-plugin top-level **"Kitgenix"** admin menu (`kitgenix_ensure_admin_menu()`, hooked to `admin_menu` priority 5) that is safe to call from multiple Kitgenix plugins (`global $admin_page_hooks` guard). The menu page itself (`kitgenix_render_admin_page()`) renders the **Kitgenix Hub** 2.2.0 – 30 October 2026 a grid of cards for every known Kitgenix plugin (Turnstile CAPTCHA, Custom Tabs, Document Manager, this plugin, PDF Invoicing, Stock Sync, MultiStore, Image Optimizer, Affiliate Link Manager) showing install/active status, WP.org install counts/ratings/icons (fetched via `plugins_api()` and cached 24h in transients `kitgenix_hub_wporg_active_installs_v1`, `kitgenix_hub_wporg_ratings_v1`, `kitgenix_hub_wporg_media_v1`), and Install/Activate/Open/Details/Review/Support actions.
   - `register_activation_hook()`: requires WooCommerce or self-deactivates with `wp_die()`; sets a 30-second transient `kitgenix_order_tracking_for_woocommerce_do_activation_redirect` to drive a one-time redirect (on `admin_init`) to the Analytics page, skipped on bulk activation (`$_GET['activate-multi']`).
   - `register_deactivation_hook()`: clears the activation-redirect transient and unschedules the `kitgenix_order_tracking_prune_event_log` cron hook via `wp_clear_scheduled_hook()`.
   - Adds a "Settings" link to the plugin's row on the Plugins screen (`plugin_action_links_*` filter).
   - Registers a **PSR-4-ish autoloader** via `spl_autoload_register()` (see below).
   - Declares WooCommerce HPOS ("custom order tables") compatibility on `before_woocommerce_init` via `FeaturesUtil::declare_compatibility()`.
   - On `plugins_loaded`, if `WooCommerce` class exists, calls `Kitgenix\OrderTracking\Plugin::init()`.
2. **`Plugin::init()`** (`includes/Plugin.php`):
   - Re-declares HPOS compatibility (belt-and-braces, same call as above).
   - Calls `Order_Tracking::init()` (currently a no-op reserved for future hooks).
   - If `is_admin()`, calls `Admin::init()` → `Order_Meta_Box::init()` + `AdminModule::init()` (alias of `Analytics::init()`).
   - Calls `Shortcodes::init()` (registers `[kitgenix_tracking_form]` + frontend style enqueue).
   - Calls `Email_Hooks::init()` (order-email tracking block + Partially Shipped trigger).
   - Hooks `init` → `register_order_status()` (registers the custom `wc-partially-shipped` post status).
   - Hooks `wc_order_statuses` filter → `add_order_status_to_list()` (inserts "Partially shipped" into the admin status dropdown, right after "Processing").
   - Hooks `woocommerce_email_classes` filter → `register_emails()` (adds `Email_Partially_Shipped` as `kitgenix_partially_shipped`).

### Autoloading

`spl_autoload_register()` in the main file maps any class under the `Kitgenix\OrderTracking\` namespace to `includes/<Relative\Path>.php` (namespace separators become `DIRECTORY_SEPARATOR`), e.g. `Kitgenix\OrderTracking\Support\Carriers` → `includes/Support/Carriers.php`. It is a simple namespace-prefix loader, not a full PSR-4 implementation 2.2.0 – 30 October 2026 the `includes/` folder is a fixed root, not derived from `composer.json`.

### Key classes

| Class | Responsibility |
|---|---|
| `Kitgenix\OrderTracking\Plugin` | Bootstrap / hook wiring, custom order status + email class registration |
| `Kitgenix\OrderTracking\Orders\Order_Tracking` | Save/read shipments on an order, quantity clamping, indexed tracking-number search, fires the `kitgenix_order_tracking_shipment_*` hooks |
| `Kitgenix\OrderTracking\Orders\Shipment` | Stateless value object: `from_stored()` / `from_input()` normalise raw arrays into the canonical shipment shape; `known_statuses()`; `normalise_items()` |
| `Kitgenix\OrderTracking\Support\Carriers` | Built-in carrier registry, regional carrier "packs", custom-carrier storage, tracking URL templating, carrier auto-detection (`detect()` / `detect_selectable()`) |
| `Kitgenix\OrderTracking\Support\Event_Log` | Capped (100-entry) activity log option, retention/prune, code→category/note/action reference table |
| `Kitgenix\OrderTracking\Admin\Admin` | Thin bootstrap combining `Order_Meta_Box` + `AdminModule` |
| `Kitgenix\OrderTracking\Admin\AdminModule` | `class_alias` of `Analytics` (kept for backwards compatibility with any external code referencing `AdminModule`) |
| `Kitgenix\OrderTracking\Admin\Analytics` | The entire "Order Tracking" admin page: menu registration, tabs (Overview/Breakdown/Recent/Carriers/Bulk/Support/Log), KPI computation with transient caching, carriers settings save, CSV bulk import/export, event log tab, cron scheduling |
| `Kitgenix\OrderTracking\Admin\Order_Meta_Box` | Order-edit-screen meta box (HPOS + classic), "Add tracking" modal template, save handler, AJAX order-items endpoint |
| `Kitgenix\OrderTracking\Emails\Email_Hooks` | Renders the Shipment Tracking block into WooCommerce emails; triggers `kitgenix_partially_shipped` on the `wc-partially-shipped` order-status transition |
| `Kitgenix\OrderTracking\Emails\Email_Partially_Shipped` | `WC_Email` subclass for the "Kitgenix Partially Shipped" customer email |
| `Kitgenix\OrderTracking\Frontend\Shortcodes` | `[kitgenix_tracking_form]` renderer: lookup form, results, postcode-gated privacy, Leaflet/Nominatim map bootstrap |
| `Kitgenix\OrderTracking\Frontend\Timeline` | Renders the honest, staff-status-only shipment timeline used on the customer tracking page |

## Features

- **Multiple shipments per order.** An order can have any number of shipments, each independently tracked (carrier, tracking number, status, delivery estimate, note, shipped date).
- **Per-item allocation.** Each shipment can be tied to specific order line items and quantities. `Order_Tracking::clamp_item_quantities()` guarantees the sum of allocated quantities across all shipments on an order can never exceed what was actually ordered 2.2.0 – 30 October 2026 over-allocated rows are silently clamped (never rejected outright) and the clamp is recorded in the Event Log (`quantity_clamped`).
- **Carrier auto-detection (suggestion only).** `Carriers::detect()` recognises a small set of unambiguous tracking-number formats (UPS `1Z…`, Amazon Logistics `TBA##########`, Royal Mail/Parcelforce `XX#########GB`, USPS domestic/international formats) and returns a *suggested* carrier. It never overwrites an explicitly chosen carrier and is shown in the admin UI as "Detected: X (unconfirmed)". Ambiguous numeric-only formats are intentionally left unmatched.
- **Built-in + custom carriers**, enable/disable, priority ordering, and regional "carrier packs" (see Carrier Support below).
- **Bulk CSV import/export** of shipments (Bulk Tools admin tab), with append/replace import modes and an `item_id:qty|item_id:qty` encoding for per-item allocations.
- **Customer email notifications**: a Shipment Tracking table is injected into the WooCommerce `customer_completed_order` email and the plugin's own `kitgenix_partially_shipped` email.
- **Custom order status + email for partial fulfilment**: `wc-partially-shipped` ("Partially shipped") status plus a dedicated `Email_Partially_Shipped` WC_Email.
- **Public, self-service tracking page** via `[kitgenix_tracking_form]`: lookup by order number + billing email, or by tracking number alone; postcode/ZIP verification gates full address/item/map detail.
- **Optional illustrative route map** (OpenStreetMap tiles via bundled Leaflet + Nominatim geocoding) showing a straight line from store to delivery address 2.2.0 – 30 October 2026 explicitly not a live GPS/vehicle position.
- **Tracking Analytics dashboard** (Overview/Breakdown/Recent tabs): order coverage, avg/median time-to-ship, shipments-by-carrier/status, exception rate, all-time lookup counter, sparkline trend, recent shipments table.
- **Activity/Event Log tab** with configurable auto-delete retention (7/14/30 days) and a reference table explaining each internal event code.
- **HPOS (High-Performance Order Storage) compatible**: declared via `FeaturesUtil::declare_compatibility('custom_order_tables', …, true)`; the meta box, save handler, and asset enqueue logic all detect and support both the classic `shop_order` post-type screen and the HPOS `wc-orders` admin screen.

## Carrier Support

All carrier data lives in `includes/Support/Carriers.php`.

### Built-in carriers (`Carriers::all()` / `Carriers::builtin()`)

| Key | Label | URL pattern |
|---|---|---|
| `amazon_logistics` | Amazon Logistics | `https://track.amazon.co.uk/tracking/{tracking_number}` |
| `an_post` | An Post | `https://www.anpost.com/Post-Parcels/Track/Search?item={tracking_number}` |
| `dhl` | DHL | `https://www.dhl.com/gb-en/home/tracking.html?tracking-id={tracking_number}&submit=1` |
| `dpd_ie` | DPD Ireland | `https://www.dpd.ie/tracking/?consignmentNumber={tracking_number}` |
| `dpd_uk` | DPD UK | `https://track.dpd.co.uk/search?reference={tracking_number}` |
| `dx` | DX | `https://my.dxdelivery.com/t/{tracking_number}` |
| `evri` | Evri | `https://www.evri.com/track/parcel/{tracking_number}/details` |
| `fedex` | FedEx | `https://www.fedex.com/fedextrack/?trknbr={tracking_number}` |
| `bpost` | Bpost | `https://track.bpost.cloud/track/items?itemIdentifier={tracking_number}` |
| `canada_post` | Canada Post | `https://www.canadapost-postescanada.ca/track-reperage/en#/details/{tracking_number}` |
| `chronopost` | Chronopost | `https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT={tracking_number}` |
| `colissimo` | Colissimo / La Poste | `https://www.laposte.fr/outils/suivre-vos-envois?code={tracking_number}` |
| `correos` | Correos | `https://www.correos.es/es/en/tools/track/status?tracking-number={tracking_number}` |
| `deutsche_post_dhl` | Deutsche Post / DHL Germany | `https://www.dhl.de/en/privatkunden/dhl-sendungsverfolgung.html?piececode={tracking_number}` |
| `woodside` | Woodside Distribution | `https://www.woodsides.com/contact/` (no tracking-number placeholder 2.2.0 – 30 October 2026 a contact page) |
| `gls` | GLS | `https://gls-group.com/GROUP/en/parcel-tracking/?match={tracking_number}` |
| `nz_post` | NZ Post | `https://www.nzpost.co.nz/tools/tracking/item/{tracking_number}` |
| `other` | Other carrier | *(empty pattern 2.2.0 – 30 October 2026 fallback, always present, always last unless prioritised)* |
| `parcel_force` | Parcelforce Worldwide | `https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}` |
| `royal_mail` | Royal Mail | `https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}` |
| `postnl` | PostNL | `https://jouw.postnl.nl/track-and-trace/{tracking_number}` |
| `postnord` | PostNord | `https://www.postnord.com/track-and-trace/?id={tracking_number}` |
| `australia_post` | Australia Post | `https://auspost.com.au/mypost/track/#/details/{tracking_number}` |
| `ups_us` | UPS US | `https://www.ups.com/track?loc=en_US&Requester=KITGENIX.COM&tracknum={tracking_number}` |
| `usps` | USPS | `https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking_number}` |
| `ups_ie` | UPS Ireland | `https://www.ups.com/track?loc=en_IE&Requester=KITGENIX.COM&tracknum={tracking_number}` |
| `ups_uk` | UPS UK | `https://www.ups.com/track?loc=en_GB&Requester=KITGENIX.COM&tracknum={tracking_number}` |
| `yodel` | Yodel | `http://yodel.co.uk/tracking/{tracking_number}` |

Each carrier also has an `icon` URL (`assets/images/carriers/*.svg`; most unmapped carriers fall back to `other.svg`).

### Tracking URL construction

`Carriers::build_tracking_url(string $key, string $tracking_number)` does a simple `str_replace('{tracking_number}', rawurlencode($tracking_number), $pattern)`. Custom carriers use the same `{tracking_number}` placeholder convention.

### Carrier auto-detection (`Carriers::detect()`)

Conservative, regex-based, intentionally narrow:

```php
1Z + 16 alphanumeric              → ups_uk / ups_ie / ups_us (by store base country)
TBA + 10 digits                   → amazon_logistics
[A-Z]{2}\d{9}GB                   → royal_mail (also covers Parcelforce 2.2.0 – 30 October 2026 same URL)
(94|93|92|82)\d{18,20}            → usps (US domestic formats)
[A-Z]{2}\d{9}US                   → usps (international Express/Priority)
```

Anything else (including ambiguous purely-numeric formats shared by many couriers) returns `null` 2.2.0 – 30 October 2026 no guess is better than a wrong one. `Carriers::detect_selectable()` wraps `detect()` and additionally verifies the detected carrier is currently enabled/selectable before surfacing it, so a suggestion never points at a carrier the store has disabled.

### Custom carriers

Stored in the `custom` array of the `kitgenix_order_tracking_for_woocommerce_carriers_settings` option. Each row: `key`, `label`, `url_pattern` (may use `{tracking_number}`), `icon` (URL, uploadable via the WP Media Library), `enabled`, `priority`. Managed entirely from the admin Carriers tab (add/edit/delete forms with a shared modal). Key collisions with built-in carrier keys are auto-suffixed (`custom_<key>`).

### Carrier packs (`Carriers::pack_options()`)

Regional presets that set `builtin_disabled` + `builtin_priority` in one action:

| Pack key | Carriers included |
|---|---|
| `uk_ie` | amazon_logistics, an_post, dpd_ie, dpd_uk, dx, evri, parcel_force, royal_mail, ups_ie, ups_uk, woodside, yodel, dhl, fedex |
| `europe` | dhl, fedex, bpost, chronopost, colissimo, correos, deutsche_post_dhl, gls, postnl, postnord |
| `north_america` | usps, ups_us, canada_post, dhl, fedex |
| `oceania` | australia_post, nz_post, dhl, fedex |
| `global` | every built-in carrier except `other` |

`Carriers::recommended_pack()` maps the WooCommerce store base country (`wc_get_base_location()`) to a pack: GB/IE → `uk_ie`, US/CA → `north_america`, AU/NZ → `oceania`, a list of EU countries → `europe`, anything else → `global`. The Carriers admin tab shows a dismissible-style notice suggesting the recommended pack when it differs from the currently applied one.

### Selectable / priority ordering

`Carriers::selectable()` returns enabled built-in + enabled custom carriers, sorted by `priority` (ascending, unset/`0` treated as "last"; `other` defaults to priority `10000` unless explicitly set) then alphabetically by label. This is the list shown in the "Add tracking" carrier `<select>`.

## Admin Interface

All admin UI is delivered through the shared "Kitgenix" design system (`assets/css/kitgenix-admin-ui.css`, `kitgenix-admin-tabs.js`, `kitgenix-admin-components.js`) 2.2.0 – 30 October 2026 a sticky topbar, dropdown "Analytics" submenu, in-page settings search, dark/light theme toggle (persisted to `localStorage['kitgenixAdminTheme']`), and mobile hamburger menu.

### Menus

| Menu | Slug | Capability | Registered by |
|---|---|---|---|
| Top-level "Kitgenix" hub | `kitgenix` | `manage_woocommerce` (falls back to `manage_options`) | `kitgenix_ensure_admin_menu()` in the main plugin file (idempotent across Kitgenix plugins via `global $admin_page_hooks` guard) |
| Submenu "Order Tracking" | `kitgenix-order-tracking-for-woocommerce-analytics` | `manage_woocommerce` (else `manage_options`) | `Analytics::register_menu()`, parent slug `kitgenix` |

### Order Tracking admin page tabs (`Analytics::render_page()`)

All tabs live on one page (`?page=kitgenix-order-tracking-for-woocommerce-analytics&tab=…`), toggled client-side by `kitgenix-admin-tabs.js` (also readable/settable via the URL query and `#kitgenix-tab-<name>` hash):

| Tab (`tab=`) | Renderer | Purpose |
|---|---|---|
| `overview` (default) | `render_overview()` | KPI stat tiles (orders in range, shipment coverage %, avg/median time-to-ship, shipments in range + top carrier, exception rate, all-time tracking lookups, orders without shipments), a shipments-over-time SVG sparkline, and an "Insights" summary card |
| `breakdown` | `render_breakdowns()` | Two ranked-list panels with percentage bars: shipments by carrier, shipments by status (top 10 each) |
| `recent` | `render_recent_shipments()` | Table of the 20 most recent shipments in range: date, order (linked), carrier, tracking number, status badge |
| `carriers` | `render_carriers()` | Carrier pack selector, built-in carriers enable/priority table, custom carriers CRUD (add form + list table + edit modal) |
| `bulk` | `render_bulk_tools()` | CSV export card + CSV import card (drag-and-drop dropzone, append/replace mode) |
| `log` | `render_log()` | Activity Log table (searchable, paginated 25/page) + retention selector + "Clear log" + a static reference table of known event codes (not fully exhaustive 2.2.0 – 30 October 2026 see Known Limitations) |
| `support` | `render_support()` | Donate (PayPal) hero card, review/docs/copy-link quick actions |

The admin nav renders tabs in the order shown above (carriers, bulk, log, then support last 2.2.0 – 30 October 2026 the Log tab was deliberately moved directly before Support per the 2.1.0 changelog).

`overview` / `breakdown` / `recent` share a date-range filter bar (`range` query arg, 7–365 days, quick presets 7/30/90/180/365) rendered by `render_filters()`.

### Options / settings stored

| Option name | Autoload | Purpose |
|---|---|---|
| `kitgenix_order_tracking_for_woocommerce_carriers_settings` | no | `{ builtin_disabled[], builtin_priority{}, custom[], carrier_pack }` 2.2.0 – 30 October 2026 see Carriers above |
| `kitgenix_order_tracking_for_woocommerce_tracking_numbers_added` | no | Cumulative count of *new unique* tracking numbers ever saved (privacy-safe metric, incremented in `Order_Tracking::save_shipments()`) |
| `kitgenix_order_tracking_for_woocommerce_lookup_count` | no | Cumulative count of successful public tracking-page lookups (incremented in `Shortcodes::increment_successful_lookup_count()`) |
| `kitgenix_order_tracking_event_log` | no | Array of up to 100 Event Log entries (`{time, context, outcome, note, code}`) |
| `kitgenix_order_tracking_log_retention_days` | no | Event log auto-delete window: `7`, `14`, or `30` (default `30`) |

### Transients

| Key pattern | TTL | Purpose |
|---|---|---|
| `kitgenix_order_tracking_for_woocommerce_analytics_<md5(range)>` | 10 minutes | Cached computed analytics dataset per date range |
| `kitgenix_order_tracking_for_woocommerce_do_activation_redirect` | 30 seconds | One-time post-activation redirect flag |
| `kitgenix_hub_wporg_active_installs_v1`, `kitgenix_hub_wporg_ratings_v1`, `kitgenix_hub_wporg_media_v1` | 24 hours (`DAY_IN_SECONDS`) | WordPress.org `plugins_api()` data cache for the Kitgenix Hub cards |

### Order edit screen additions

`Order_Meta_Box` (`includes/Admin/Order_Meta_Box.php`):

- **Meta box** "Kitgenix Order Tracking" (side, default priority) registered via `add_meta_boxes`, targeting the classic `shop_order` post-type screen or, when HPOS's custom-orders-table usage is enabled (detected via `wc_get_container()` → `CustomOrdersTableController`), the HPOS screen ID from `wc_get_page_screen_id('shop-order')`. A `try/catch` around the HPOS detection logs `hpos_lookup_failed` to the Event Log on failure and falls back to the classic screen.
- Renders the existing shipment list (`render_shipment_row()` per shipment: carrier + detected-carrier badge, tracking number, status, shipped date, delivery estimate, allocated items, internal note, Edit/Remove links) plus "Add tracking" and "Save tracking" buttons.
- **"Add tracking" modal** (`render_modal_template()`, printed once in `admin_footer` on order screens): tracking number, carrier `<select>` (populated from `Carriers::selectable()`), date shipped, estimated delivery, shipment status `<select>` (`Shipment::known_statuses()`), internal note (`<textarea>`, staff-only, never emailed), an "Update order status" radio group (`no_change` / `partial` / `completed`), and a dynamically-loaded order-items checklist (AJAX).
- **No admin bulk action or order-list column** is added by this plugin 2.2.0 – 30 October 2026 tracking is only manageable per-order via the meta box (there is no "bulk add tracking" from the orders list, only the Bulk Tools CSV workflow on the Analytics page).
- **Order status list**: this plugin **does** add one custom order status, "Partially shipped" (`wc-partially-shipped`), inserted into `wc_get_order_statuses()` right after "Processing" (see `Plugin::add_order_status_to_list()`).

## Email Notifications

### WooCommerce emails modified

`Email_Hooks::init()` hooks `append_tracking_to_email()` onto **both** `woocommerce_email_before_order_table` and `woocommerce_email_after_order_table` (priority 10, 4 args), but only acts for `email->id` in `['customer_completed_order', 'kitgenix_partially_shipped']`. A static `$printed` de-dupe map (`$email->id . '|' . $order_id`) plus explicit hook-preference logic (tracking prints on the *before* hook for these two email IDs, on *after* for anything else) prevents the block appearing twice if both hooks fire.

Content rendered (HTML and plain-text variants):
- A "Shipment Tracking" heading/table: Carrier | Tracking (+ inline items summary if the shipment has item allocations) | Estimated delivery | Track (link to `tracking_url`, styled `class="email-order-details"`/`class="td"` to inherit WooCommerce's own email CSS/colour settings).
- An optional "Not yet shipped items" section listing order items with remaining (ordered − shipped) quantity > 0.

### Custom email: Kitgenix Partially Shipped

`Email_Partially_Shipped extends WC_Email`, `id = 'kitgenix_partially_shipped'`, `customer_email = true`.

- **Trigger**: `Email_Hooks::init()` hooks `woocommerce_order_status_partially-shipped` → `trigger_partially_shipped_email( $order_id, $order )`, which fetches the registered email object from `WC()->mailer()->get_emails()` and calls `->trigger($order_id, $order)`. This fires automatically whenever an order transitions to the `wc-partially-shipped` status (e.g. via the "Update order status" radio in the tracking modal, or manually from the order screen's status dropdown).
- **Subject** (translatable, with WooCommerce placeholders): `Your {site_title} order {order_number} is partially shipped`.
- **Heading**: "Your order has been partially shipped".
- **Templates**: `template_html = 'emails/partially-shipped.php'`, `template_plain = 'emails/plain/partially-shipped.php'`, with `template_base` pointed at this plugin's own `templates/` folder (`$this->template_base = …/templates/`) 2.2.0 – 30 October 2026 following the standard WooCommerce "plugin template override" pattern, so a theme can still override by placing a copy at `yourtheme/woocommerce/emails/partially-shipped.php`.
- **Merge tags / placeholders** added to `$this->placeholders`: `{order_date}` (formatted via `wc_format_datetime()`), `{order_number}`. Standard WooCommerce placeholders (`{site_title}`, etc.) are inherited from `WC_Email`.
- After sending, fires the custom action `kitgenix_order_tracking_shipment_notification_sent( $order->get_id(), 'kitgenix_partially_shipped' )`.
- Template structure follows WooCommerce's modern "email improvements" layout when `FeaturesUtil::feature_is_enabled('email_improvements')` is true (wraps the intro in `.email-introduction`, adds an extra line "Here's a reminder of what you've ordered"). Both templates call the standard `woocommerce_email_header`, `woocommerce_email_order_details`, `woocommerce_email_order_meta`, `woocommerce_email_customer_details`, `woocommerce_email_footer` action hooks. Each template explicitly `remove_action()`s the plugin's own `append_tracking_to_email` hook and calls it directly in the desired position (above the order summary) to avoid double-rendering.

## Hooks Reference

### Hooks this plugin hooks INTO

| Hook | Type | Callback | File |
|---|---|---|---|
| `admin_menu` (priority 5) | action | `kitgenix_ensure_admin_menu()` | main plugin file |
| `admin_enqueue_scripts` (priority 5) | action | `kitgenix_order_tracking_for_woocommerce_register_admin_ui_style()` | main plugin file |
| `admin_enqueue_scripts` | action | `kitgenix_order_tracking_for_woocommerce_enqueue_hub_assets()` | main plugin file |
| `admin_init` | action | activation-redirect handler | main plugin file |
| `plugin_action_links_{plugin_basename}` | filter | adds Settings link | main plugin file |
| `before_woocommerce_init` | action | HPOS compatibility declaration | main plugin file + `Plugin::init()` |
| `plugins_loaded` | action | `Plugin::init()` | main plugin file |
| `init` | action | `Plugin::register_order_status()` | `Plugin.php` |
| `wc_order_statuses` | filter | `Plugin::add_order_status_to_list()` | `Plugin.php` |
| `woocommerce_email_classes` | filter | `Plugin::register_emails()` | `Plugin.php` |
| `add_meta_boxes` | action | `Order_Meta_Box::add_meta_box()` | `Order_Meta_Box.php` |
| `woocommerce_process_shop_order_meta` | action | `Order_Meta_Box::save_meta_box()` | `Order_Meta_Box.php` |
| `admin_enqueue_scripts` | action | `Order_Meta_Box::enqueue_assets()` | `Order_Meta_Box.php` |
| `admin_footer` | action | `Order_Meta_Box::render_modal_template()` | `Order_Meta_Box.php` |
| `wp_ajax_kitgenix_order_tracking_for_woocommerce_get_order_items` | action | `Order_Meta_Box::ajax_get_order_items()` | `Order_Meta_Box.php` |
| `woocommerce_email_after_order_table` | action | `Email_Hooks::append_tracking_to_email()` | `Email_Hooks.php` |
| `woocommerce_email_before_order_table` | action | `Email_Hooks::append_tracking_to_email()` | `Email_Hooks.php` |
| `woocommerce_order_status_partially-shipped` | action | `Email_Hooks::trigger_partially_shipped_email()` | `Email_Hooks.php` |
| `wp_enqueue_scripts` | action | `Shortcodes::enqueue_styles()` | `Shortcodes.php` |
| `admin_menu` | action | `Analytics::register_menu()` | `Analytics.php` |
| `admin_enqueue_scripts` | action | `Analytics::enqueue_assets()` | `Analytics.php` |
| `admin_notices` | action | `Analytics::render_admin_notices()` | `Analytics.php` |
| `admin_post_kitgenix_order_tracking_export_shipments` | action | `Analytics::handle_export_shipments()` | `Analytics.php` |
| `admin_post_kitgenix_order_tracking_import_shipments` | action | `Analytics::handle_import_shipments()` | `Analytics.php` |
| `admin_post_kitgenix_order_tracking_clear_event_log` | action | `Analytics::handle_clear_event_log()` | `Analytics.php` |
| `admin_post_kitgenix_order_tracking_save_log_retention` | action | `Analytics::handle_save_log_retention()` | `Analytics.php` |
| `update_option_kitgenix_order_tracking_for_woocommerce_carriers_settings` | action | `Analytics::on_carriers_saved()` (logs the save) | `Analytics.php` |
| `admin_init` | action | `Analytics::maybe_schedule_log_prune()` | `Analytics.php` |
| `kitgenix_order_tracking_prune_event_log` (custom cron hook) | action | `Event_Log::prune()` | `Analytics.php` |

### Custom actions/filters this plugin fires

| Hook | Type | Signature | Fired from | Purpose |
|---|---|---|---|---|
| `kitgenix_order_tracking_shipment_created` | action | `(int $order_id, array $shipment)` | `Order_Tracking::fire_change_hooks()` | A new shipment was added to an order |
| `kitgenix_order_tracking_shipment_updated` | action | `(int $order_id, array $shipment, array $previous_shipment)` | `Order_Tracking::fire_change_hooks()` | An existing shipment (matched by `id`) changed |
| `kitgenix_order_tracking_shipment_deleted` | action | `(int $order_id, array $shipment)` | `Order_Tracking::fire_change_hooks()` | A shipment present before save but absent after was removed |
| `kitgenix_order_tracking_shipment_notification_sent` | action | `(int $order_id, string $email_id)` | `Email_Partially_Shipped::trigger()` | Fired after the Partially Shipped email is sent (`$email_id` is always `'kitgenix_partially_shipped'`) |
| `kitgenix_order_tracking_for_woocommerce_debug_save` | filter | `(bool $enabled, int $order_id): bool` | `Order_Meta_Box::save_meta_box()` | Return `true` to have the raw vs. normalised POSTed shipment data written to the order notes on save (debugging aid) |
| `kitgenix_order_tracking_for_woocommerce_nominatim_email` | filter | `(string $email): string` | `Shortcodes::render_tracking_form()` | Supply/override a contact email param sent to the Nominatim geocoding endpoint |
| `kitgenix_order_tracking_for_woocommerce_nominatim_endpoint` | filter | `(string $endpoint): string` | `Shortcodes::render_tracking_form()` | Override the Nominatim geocoding endpoint URL (default `https://nominatim.openstreetmap.org/search`) |

## Shortcodes / Blocks / Widgets

Only one shortcode is registered, no blocks or widgets.

### `[kitgenix_tracking_form]`

Registered in `Shortcodes::init()` via `add_shortcode('kitgenix_tracking_form', [self::class, 'render_tracking_form'])`. Declared attributes: **none** (`shortcode_atts([], $atts, 'kitgenix_tracking_form')` 2.2.0 – 30 October 2026 the `$atts`/`$content` parameters are accepted but unused, reserved for future extension).

Behaviour, in full, is documented under **Frontend Behavior** below.

## Data Storage

No custom database tables are created, no custom post types or taxonomies are registered, and no REST API routes are registered.

### Order meta keys (via `WC_Order::get_meta()`/`update_meta_data()`/`add_meta_data()` 2.2.0 – 30 October 2026 HPOS-safe, never raw `wp_postmeta` queries)

| Meta key | Shape | Written by | Notes |
|---|---|---|---|
| `_kitgenix_tracking_shipments` | Single meta row, array of shipment records | `Order_Tracking::save_shipments()` | Canonical shipment storage. Each record: `id` (UUID v4), `carrier`, `tracking_number`, `created_at` (falls back to `date_shipped` on legacy reads), `delivery_estimate`, `status`, `note`, `items` (`[{item_id, qty}]`). `tracking_url` and `carrier_detected` are computed at *read* time (`Shipment::from_stored()`) and never persisted. |
| `_kitgenix_tracking_number` | Repeated meta rows, one per tracking number (`add_meta_data($key, $value, false)`) | `Order_Tracking::save_shipments()` | Secondary index enabling a fast exact-match `wc_get_orders(['meta_key'=>…,'meta_value'=>…])` lookup in `find_order_by_tracking_number()`. Cleared and rebuilt on every save. |
| `kitgenix_order_status_action` | String: `no_change` \| `partial` \| `completed` | `Order_Meta_Box::save_meta_box()` | Records the last "update order status" choice made in the Add-tracking modal; currently stored for potential future use/debugging, not read back anywhere else in the codebase. |

### Options

See the **Options / settings stored** table under Admin Interface, plus the **Data Storage** items below that aren't admin-facing settings:

| Option | Purpose |
|---|---|
| `kitgenix_order_tracking_for_woocommerce_carriers_settings` | Carrier configuration |
| `kitgenix_order_tracking_for_woocommerce_tracking_numbers_added` | Counter |
| `kitgenix_order_tracking_for_woocommerce_lookup_count` | Counter |
| `kitgenix_order_tracking_event_log` | Activity log entries |
| `kitgenix_order_tracking_log_retention_days` | Log retention window |

### Transients

See the **Transients** table under Admin Interface (analytics cache, activation redirect flag, Kitgenix Hub WP.org data caches).

### Usermeta

None used by this plugin.

## AJAX / REST Endpoints

No REST API routes are registered (confirmed 2.2.0 – 30 October 2026 no `register_rest_route()` calls anywhere in the codebase).

### Admin-AJAX

| Action | Method | Handler | Nonce | Capability | Request params | Response |
|---|---|---|---|---|---|---|
| `wp_ajax_kitgenix_order_tracking_for_woocommerce_get_order_items` | POST | `Order_Meta_Box::ajax_get_order_items()` | `nonce` field, action `kitgenix_order_tracking` | `current_user_can('edit_shop_order', $order_id)` | `order_id` (int), `shipment_id` (string, optional 2.2.0 – 30 October 2026 excludes that shipment's own already-allocated qty from the "remaining" calc), `nonce` | `wp_send_json_success(['items' => [{item_id, name, sku, qty (remaining selectable), image_html}]])` or `wp_send_json_error(['message' => …])` |

Note: this is a *logged-in-admin-only* endpoint (registered with the `wp_ajax_` prefix, no `wp_ajax_nopriv_` counterpart) 2.2.0 – 30 October 2026 it only loads order items into the "Add tracking" modal on the order-edit screen.

### `admin-post.php` handlers (form-POST style, not AJAX, but functionally endpoint-like)

| Action (`admin-post.php?action=`) | Handler | Nonce action | Capability | Purpose |
|---|---|---|---|---|
| `kitgenix_order_tracking_export_shipments` | `Analytics::handle_export_shipments()` | `kitgenix_order_tracking_export_shipments` | `manage_woocommerce` (or `manage_options` fallback) | Streams a CSV (`text/csv`, UTF-8 BOM) of every shipment across every order, columns: `order_id, order_number, shipment_id, carrier, carrier_label, tracking_number, tracking_url, date_shipped, status, item_quantities`. Cells starting with `=`, `+`, `-`, `@`, a tab or CR are prefixed with an apostrophe (`neutralize_csv_formula()`) to prevent spreadsheet formula injection; import reverses this (`restore_csv_formula()`) so exports round-trip |
| `kitgenix_order_tracking_import_shipments` | `Analytics::handle_import_shipments()` | `kitgenix_order_tracking_import_shipments` | same | Parses an uploaded CSV (auto-detects `,`/`;`/tab delimiter), resolves each row to an order by `order_id` or `order_number`, builds shipment records, and calls `Order_Tracking::save_shipments()` in `append` (skip existing tracking numbers) or `replace` (overwrite all shipments on matched orders) mode. Redirects back to the Bulk Tools tab with a `bulk_notice` query arg (`imported`, `import-empty`, `import-file`, `import-invalid`, `import-failed`) |
| `kitgenix_order_tracking_clear_event_log` | `Analytics::handle_clear_event_log()` | `kitgenix_order_tracking_clear_event_log` (via `check_admin_referer()`) | `manage_options` or `manage_woocommerce` | Clears the Event Log option |
| `kitgenix_order_tracking_save_log_retention` | `Analytics::handle_save_log_retention()` | `kitgenix_order_tracking_save_log_retention` (via `check_admin_referer()`) | `manage_options` or `manage_woocommerce` | Saves the log auto-delete window (7/14/30 days, clamped) |

### Direct-POST (non-admin-post) form handlers

| Form | Handler | Nonce action | Capability | Purpose |
|---|---|---|---|---|
| Order-edit meta box save | `Order_Meta_Box::save_meta_box()` (hooked to `woocommerce_process_shop_order_meta`) | `kitgenix_order_tracking_save` (field `kitgenix_order_tracking_nonce`) | `current_user_can('edit_shop_order', $order_id)` | Persists the shipment array POSTed from the order-edit screen |
| Carriers settings forms (pack / built-in / custom add-edit-delete) | `Analytics::maybe_handle_carriers_save()` (invoked at the top of `render_page()` when `tab=carriers` and method is POST) | `kitgenix_order_tracking_for_woocommerce_save_carriers` (field `kitgenix_order_tracking_for_woocommerce_carriers_nonce`) | `current_user_can(self::get_menu_capability())` | Saves carrier pack selection, built-in enable/priority table, or a custom-carrier add/edit/delete action, via `Carriers::update_settings()` |
| Public tracking lookup form | `Shortcodes::render_tracking_form()` (self-processing shortcode) | `kitgenix_tracking_lookup` (field `kitgenix_tracking_nonce`) | none (public); failed lookups rate-limited per IP and per order | Looks up an order by (order number + billing email) or by tracking number alone |

## Cron / Scheduled Events

| Hook | Schedule | Registered via | Callback |
|---|---|---|---|
| `kitgenix_order_tracking_prune_event_log` | `daily` | `Analytics::maybe_schedule_log_prune()` (hooked to `admin_init`, calls `wp_schedule_event(time(), 'daily', …)` only if `wp_next_scheduled()` returns false) | `Event_Log::prune()` 2.2.0 – 30 October 2026 removes log entries older than the configured retention window |

The scheduled event is explicitly unscheduled on plugin deactivation via `wp_clear_scheduled_hook('kitgenix_order_tracking_prune_event_log')` in the main plugin file's `register_deactivation_hook()` callback. Log entries are also opportunistically pruned inline every time a new entry is recorded (`Event_Log::record()` calls `prune_expired()` before persisting), so the daily cron is a housekeeping backstop rather than the only pruning path.

## Frontend Behavior

### `[kitgenix_tracking_form]` (`Shortcodes::render_tracking_form()`)

1. **Enqueues** `assets/css/frontend.css` (via `wp_enqueue_scripts`).
2. **Lookup form**: two mutually-exclusive modes, switched with radio buttons and JS-shown/hidden field groups:
   - *Order number & email* 2.2.0 – 30 October 2026 `kitgenix_order_number` + `kitgenix_order_email`; the order is loaded via `wc_get_order($order_number)` and the submitted email is compared case-insensitively (`strcasecmp`) against `$order->get_billing_email()`.
   - *Tracking number only* 2.2.0 – 30 October 2026 `kitgenix_tracking_number`; resolved via `Order_Tracking::find_order_by_tracking_number()`.
   - A `kitgenix_order_postcode` field (label/help text adapt per-country: US/PR → "ZIP code"; GB/GG/IM/IE/JE/AU/NZ → "Postcode"; CA → "Postal code"; else generic) is optional and used only to unlock sensitive detail (see below).
   - All submissions require the `kitgenix_tracking_lookup` nonce (`kitgenix_tracking_nonce` field); a failed nonce shows a generic "could not be verified" error.
   - An unknown order number and a wrong billing email both return the same message ("We could not find an order matching those details."), so the form cannot be used to enumerate order IDs.
   - **Rate limiting** (transients, 15-minute window): failed lookups (unknown order/tracking number, wrong email, wrong postcode) are counted per client IP (`REMOTE_ADDR`, default limit 20, filter `kitgenix_order_tracking_for_woocommerce_max_failed_lookups_per_ip`) and wrong email/postcode attempts are also counted per order (default limit 5, filter `kitgenix_order_tracking_for_woocommerce_max_failed_lookups_per_order`), so the postcode gate cannot be brute-forced even from rotating IPs. When either limit is reached the form shows a "Too many unsuccessful attempts" message. A limit of `0` disables that check. Failures are counted once per request even if the shortcode renders more than once.
3. **Privacy gate**: `$can_show_sensitive_order_details` is `true` when either (a) the submitted postcode matches the order's shipping or billing postcode via `postal_codes_match()` (country-aware normalisation 2.2.0 – 30 October 2026 strips non-alphanumerics, handles US ZIP+4 and Brazilian CEP truncation, and also strips a leading ISO country-code prefix if present), or (b) the order has *no* postcode stored at all **and** the lookup used order number + billing email. A tracking-number-only lookup for an order with no postcode never unlocks sensitive details (fixed in 2.2.0; previously case (b) unlocked them for either lookup mode). Otherwise, the results show only the tracking number, carrier, and tracking link 2.2.0 – 30 October 2026 full billing/shipping addresses, per-item shipment detail, and the map are withheld with an explanatory note.
4. **Results rendering** (only when a matching order + at least one shipment is found): order summary (order number, date, WC status), Shipment Tracking list (carrier icon+label, tracking number, "Open carrier tracking page" link or a "not available" note if the carrier has no URL pattern, per-shipment item breakdown), an "Order details" card (billing/shipping addresses + full item list with shipped/not-shipped quantities, when unlocked), and 2.2.0 – 30 October 2026 if both a store address and an unlocked customer address are available 2.2.0 – 30 October 2026 a "Route from store to delivery address" map card.
   - If the order exists but has **no shipments yet**, a friendly "not yet dispatched" state is shown instead of an error, still respecting the same postcode gate for order details.
   - A successful lookup (order found, whether or not it has shipments) increments `kitgenix_order_tracking_for_woocommerce_lookup_count`.
5. **Map** (only rendered when both `$store_address` and `$customer_address` resolve to non-empty strings): a `<div id="kitgenix-order-map">` with `data-origin`/`data-destination`/`*-country` attributes. An inline JS block (built as a PHP string and injected via `wp_add_inline_script`) initialises Leaflet, geocodes both addresses through Nominatim (trying up to 3 query variants per address: full address → likely postcode segment → likely locality segment, capped for politeness), drops markers, draws a straight `L.polyline` between them, and fits the map bounds. Falls back gracefully (single marker, or a hidden map card with a message) if one or both addresses can't be geocoded. No Google Maps, no API key, no CDN 2.2.0 – 30 October 2026 Leaflet is served from the bundled `assets/vendor/leaflet/` files.
6. **My Account integration**: see the `My_Account` panel below 2.2.0 – 30 October 2026 logged-in customers see their shipments on the core My Account "View order" screen without using this shortcode.

### `My_Account` tracking panel (`Frontend\My_Account`)

Added in 2.2.0. Hooks `woocommerce_view_order` at priority 5, so a branded `<section id="kitgenix-tracking" class="kitgenix-account-tracking">` appears above WooCommerce's order details table on My Account > Orders > View order. It re-checks `current_user_can( 'view_order', $order_id )` before rendering. The header shows the WooCommerce order status as a badge; each shipment shows the carrier icon and label, a status badge (from `Shipment::known_statuses()`), the tracking number, the `Timeline` steps, the shipment note, the items in that shipment, and a "Track with carrier" button (or a "not available" note when the carrier has no URL pattern). Orders with no shipments show a "not dispatched yet" message. No postcode gate applies, because WooCommerce has already confirmed the customer owns the order.

- `kitgenix_order_tracking_for_woocommerce_show_my_account_panel` (bool, `WC_Order`) 2.2.0 – 30 October 2026 return `false` to hide the panel.
- `kitgenix_order_tracking_for_woocommerce_my_account_accent_color` (string hex colour, `WC_Order`) 2.2.0 – 30 October 2026 sets the `--kitgenix-ot-accent` CSS variable used by the panel's border, badges, timeline and button. Defaults to `#214BFF`; invalid values fall back to the default.

### `Timeline` renderer (`Frontend\Timeline::render()`)

Used by the My Account tracking panel for each shipment (its `render()` method returns already-escaped HTML 2.2.0 – 30 October 2026 an `<ol class="kitgenix-timeline">` of steps). Steps: "Order placed" (always reached, dated from `$order->get_date_created()`), "Shipped" (always reached once a shipment exists, dated from `created_at`), "In transit" (reached only if status is `in_transit` or `delivered`), and a final step whose label/variant depends on status: `delivered` → "Delivered"; `exception` → "Delivery exception" (warning variant); `returned` → "Returned to sender" (warning); `cancelled` → "Cancelled" (warning); anything else → "Delivered" (not yet reached). Explicitly designed to **never fabricate carrier scan events** 2.2.0 – 30 October 2026 only what the store itself recorded.

## Assets

### JavaScript

| File | Enqueued on | Purpose |
|---|---|---|
| `assets/js/admin-order-tracking.js` | Order-edit screen (classic + HPOS), via `Order_Meta_Box::enqueue_assets()` | jQuery-based: opens/populates the "Add tracking" modal (loading state → AJAX order-items fetch → render item checklist), builds/replaces shipment row HTML client-side on Confirm, wires Edit/Remove buttons, syncs the modal's "update order status" choice into the order-edit screen's native status `<select>`, and wires the "Save tracking" button to trigger the native WooCommerce order "Save"/"Publish" button. Depends on `jquery`, `kitgenix-admin-components`. |
| `assets/js/admin-analytics.js` | Order Tracking admin page, via `Analytics::enqueue_assets()` | Vanilla JS: animates ranked-list bar widths from `data-*width` attributes; wires the custom-carrier edit/delete modal (populates fields from row `data-*` attributes, WP Media Library picker for carrier icon uploads via `wp.media()`, submits a hidden delete form with a `confirm()` prompt). Depends on `kitgenix-admin-components`. |
| `assets/js/kitgenix-admin-components.js` | Both admin screens above (shared, loaded standalone) | Toast notifications (`window.KitgenixAdminUI.toast()`), accessible modal open/close with focus trapping and return-focus, collapsible/accordion cards, copy-to-clipboard buttons, live client-side table search (`data-kitgenix-table-search`), lightweight client-side table pagination (`data-kitgenix-table-paginate="25"`), and global Escape-to-close-modal handling. Deliberately kept file-identical across all Kitgenix plugins. |
| `assets/js/kitgenix-admin-tabs.js` | Order Tracking admin page (any element matching `.kitgenix-admin-app`/`[data-kitgenix-tabs]`) | Shared topbar/tab controller: tab switching with URL query + hash sync, in-page settings search (`/` or Cmd/Ctrl+K to focus), mobile hamburger menu, dropdown submenu (Analytics), dark/light theme toggle (persisted per-browser via `localStorage`), relocates stray `.notice`/`.settings-error` elements out of the sticky header into the standard WP notice area, and measures the topbar's real height to expose `--kitgenix-sticky-offset` for the sidebar's `position: sticky` offset. Also file-identical across Kitgenix plugins. |

### CSS

| File | Enqueued on | Purpose |
|---|---|---|
| `assets/css/admin.css` | Order Tracking admin page + order-edit meta box screen | Plugin-specific overrides layered on the shared design system 2.2.0 – 30 October 2026 e.g. forces the stat-tile grid to a fixed 4/2/1-column responsive layout (rather than the shared auto-fit rule other Kitgenix plugins rely on), sparkline empty-state styling, meta-box/modal layout. Uses only `var(--kitgenix-*)` custom-property tokens, no hardcoded colours. |
| `assets/css/frontend.css` | Any page containing `[kitgenix_tracking_form]` | Public tracking form + results layout (cards, tracking list, item lists, map embed, error states). |
| `assets/css/kitgenix-admin-ui.css` | Every Kitgenix admin screen (registered early via `admin_enqueue_scripts` priority 5 in the main plugin file so other enqueue calls can depend on the handle `kitgenix-order-tracking-for-woocommerce-admin-ui`) | The shared cross-plugin design system: CSS custom-property tokens (brand colours, spacing/radius scale, Manrope/Inter via Google Fonts `@import`), topbar, cards, tables, modals, toggles, badges, stat tiles, dark-mode variants. Comment explicitly states "Keep this file identical between plugins to ensure consistent UI/UX." |
| `assets/css/kitgenix-hub.css` | The top-level `admin.php?page=kitgenix` Hub page only (enqueued conditionally by `kitgenix_order_tracking_for_woocommerce_enqueue_hub_assets()` when the `page` query arg is `kitgenix`) | Styling for the plugin-discovery grid of cards on the shared Hub page. |

### Vendor libraries

- **Leaflet** (`assets/vendor/leaflet/leaflet.js`, `leaflet.css`, `images/`) 2.2.0 – 30 October 2026 bundled locally, no CDN, used only when the tracking-page route map renders (both a store and customer address are resolvable). Tiles are fetched live from `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`; geocoding from `https://nominatim.openstreetmap.org/search` (both overridable via the `kitgenix_order_tracking_for_woocommerce_nominatim_endpoint`/`_email` filters).
- No other third-party JS/CSS libraries are bundled or CDN-loaded. Fonts (Manrope/Inter) are pulled from Google Fonts via a CSS `@import` in `kitgenix-admin-ui.css`.

## Internationalization

- **Text domain**: `kitgenix-order-tracking-for-woocommerce`, **Domain Path**: `/languages` (declared in the main plugin file header).
- **Translation template**: `languages/kitgenix-order-tracking-for-woocommerce.pot`, generated with WP-CLI 2.12.0 (per its header), `Project-Id-Version: ... 2.2.0`, `POT-Creation-Date: 2026-10-22`.
- All user-facing strings throughout the codebase are wrapped in `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, `_x()`, `_n_noop()`, with translator comments preceding every `sprintf()`/`printf()` call that has positional placeholders.
- No explicit `load_plugin_textdomain()` call was found in the codebase 2.2.0 – 30 October 2026 as of WordPress 4.6+, translations for plugins hosted on WordPress.org are loaded automatically from the language packs directory without an explicit call, which is consistent with this being a WordPress.org-distributed plugin (the readme.txt and `PluginScore Verification` header confirm WordPress.org distribution).

## Security

### Nonce usage

| Nonce action | Field name | Verified in |
|---|---|---|
| `kitgenix_order_tracking_save` | `kitgenix_order_tracking_nonce` | `Order_Meta_Box::save_meta_box()` (order meta box save) |
| `kitgenix_order_tracking` | `nonce` | `Order_Meta_Box::ajax_get_order_items()` (AJAX) |
| `kitgenix_tracking_lookup` | `kitgenix_tracking_nonce` | `Shortcodes::render_tracking_form()` (public lookup form) |
| `kitgenix_order_tracking_for_woocommerce_save_carriers` | `kitgenix_order_tracking_for_woocommerce_carriers_nonce` | `Analytics::maybe_handle_carriers_save()` |
| `kitgenix_order_tracking_export_shipments` | `_wpnonce` (via `wp_nonce_field()`) | `Analytics::handle_export_shipments()` |
| `kitgenix_order_tracking_import_shipments` | `_wpnonce` | `Analytics::handle_import_shipments()` |
| `kitgenix_order_tracking_clear_event_log` | (via `wp_nonce_url()` + `check_admin_referer()`) | `Analytics::handle_clear_event_log()` |
| `kitgenix_order_tracking_save_log_retention` | (via `wp_nonce_field()` + `check_admin_referer()`) | `Analytics::handle_save_log_retention()` |

Every nonce check uses `sanitize_text_field(wp_unslash(...))` before `wp_verify_nonce()`, and every state-changing admin-post/POST handler verifies its nonce *before* doing any work.

### Capability checks

- Order meta box save: `current_user_can('edit_shop_order', $order_id)`.
- AJAX order items: `current_user_can('edit_shop_order', $order_id)`.
- Analytics page menu, carriers save, log tab, export/import, clear-log, retention-save: `current_user_can(self::get_menu_capability())`, which resolves to `manage_woocommerce` when WooCommerce is active (or detectable via `is_plugin_active()`), otherwise `manage_options` 2.2.0 – 30 October 2026 with `handle_clear_event_log()`/`handle_save_log_retention()` explicitly accepting either `manage_options` OR `manage_woocommerce`.
- Kitgenix Hub top-level page render: `current_user_can('manage_options') || (WooCommerce active && current_user_can('manage_woocommerce'))`.
- Public tracking-form lookups and the "Kitgenix Order Tracking" order-items AJAX have no capability requirement beyond nonce verification where relevant (the tracking-form lookup is intentionally public; the order-items AJAX still requires `edit_shop_order`).

### Sanitization / escaping patterns

- Input: `sanitize_text_field()`, `sanitize_key()`, `sanitize_email()`, `sanitize_textarea_field()`, `absint()`, `esc_url_raw()`, all wrapped around `wp_unslash()` for POST/GET superglobals.
- Shipment records specifically go through `Shipment::from_input()` / `Shipment::from_stored()`, which validate every field individually by type (never a blanket `sanitize_text_field()` over a nested array 2.2.0 – 30 October 2026 the code comments explicitly note this would strip newlines from the internal-note textarea).
- Output: `esc_html()`, `esc_html__()/_e()`, `esc_attr()`, `esc_url()`, `esc_js()`, `wp_kses_post()` (for WooCommerce-formatted addresses), and a handful of tightly-scoped `wp_kses()` calls with an explicit allowed-tags array (e.g. the sparkline SVG, the Kitgenix Hub card markup) for the few places raw-ish HTML must be echoed. Every `phpcs:ignore` comment on an escaping/nonce rule is accompanied by an explanatory `// Reason:` comment.
- Direct database access is avoided entirely except in `uninstall.php` (a scoped `$wpdb->get_col()` to enumerate transient option names by prefix for cleanup, explicitly commented as necessary because there's no WP API to list options by `LIKE`) and the deliberately-flagged, commented `phpcs:disable` block in `Order_Tracking::find_order_by_tracking_number()` around an *exact* indexed `meta_key`/`meta_value` lookup (not a slow `LIKE` query).
- File uploads (bulk CSV import) are read via `SplFileObject` with `UPLOAD_ERR_OK`/`tmp_name` validation before any parsing; CSV header/row parsing is defensive against missing/empty headers (`missing_headers`, `invalid_headers` exceptions) and malformed rows (skipped, counted, and reported back to the admin).

## Uninstall / Cleanup

### `uninstall.php`

Guarded by `defined('WP_UNINSTALL_PLUGIN') || exit;`. On uninstall:

**Removed:**
- Options: `kitgenix_order_tracking_for_woocommerce_lookup_count`, `kitgenix_order_tracking_for_woocommerce_tracking_numbers_added`, `kitgenix_order_tracking_for_woocommerce_carriers_settings`, `kitgenix_order_tracking_event_log` (plus `delete_site_option()` equivalents for the first three).
- All transients whose key starts with `kitgenix_order_tracking_for_woocommerce_` (via a helper that queries `wp_options` for `_transient_<prefix>%` / `_transient_timeout_<prefix>%` and deletes each through `delete_transient()` so the object cache is also cleared correctly) 2.2.0 – 30 October 2026 this covers the analytics cache and the activation-redirect flag, **but not** the Kitgenix Hub WP.org data caches (`kitgenix_hub_wporg_active_installs_v1`, `kitgenix_hub_wporg_ratings_v1`, `kitgenix_hub_wporg_media_v1`), which are keyed under a different `kitgenix_hub_wporg_*` prefix and are never deleted on uninstall.
- On multisite, the same options + transients are removed on **every site** in the network via `get_sites()` + `switch_to_blog()`/`restore_current_blog()`.

**Not removed (by design):**
- Order meta: `_kitgenix_tracking_shipments`, `_kitgenix_tracking_number` 2.2.0 – 30 October 2026 historical shipment/tracking data is preserved on orders even after the plugin is removed.
- The `wc-partially-shipped` order status and any order history entries referencing it remain on orders (WooCommerce order-status history is core order data, untouched by this uninstall script).
- `kitgenix_order_tracking_log_retention_days` 2.2.0 – 30 October 2026 **not explicitly deleted** in `uninstall.php` (this is a minor gap; see Known Limitations below).

### Deactivation

`register_deactivation_hook()` in the main plugin file (lighter-touch than uninstall 2.2.0 – 30 October 2026 data is meant to survive a mere deactivation):
- Deletes the `kitgenix_order_tracking_for_woocommerce_do_activation_redirect` transient.
- Unschedules the `kitgenix_order_tracking_prune_event_log` daily cron event via `wp_clear_scheduled_hook()`.

No other cleanup happens on deactivation 2.2.0 – 30 October 2026 options, order meta, and the event log all persist so the plugin can be safely reactivated without data loss.

## Known Limitations / TODOs

- No explicit `// TODO` / `// FIXME` code comments were found anywhere in the PHP, JS, or CSS source. The codebase instead documents intentional simplifications inline as prose comments (e.g. "This is a *suggestion* only", "never fabricates live carrier events", "no country-centroid fallback").
- `Order_Tracking::init()` is a documented no-op ("Bootstrap (reserved for future hooks)") 2.2.0 – 30 October 2026 currently nothing is wired through it.
- `kitgenix_order_tracking_log_retention_days` is not removed by `uninstall.php`, unlike its sibling options 2.2.0 – 30 October 2026 a small inconsistency versus the readme's stated uninstall behaviour, which only documents removing the carrier settings, the two counters, and the event log option.
- The Log tab's static event-code reference table is not exhaustive: `Event_Log::code_reference()` documents 9 codes (`carriers-saved`, `file_unreadable`, `file_open_failed`, `missing_headers`, `invalid_headers`, `import_unexpected_exception`, `date_parse_failed`, `tracking_count_skipped`, `hpos_lookup_failed`) but omits `quantity_clamped` (logged by `Order_Tracking::clamp_item_quantities()`) 2.2.0 – 30 October 2026 any such log entry falls through to the generic "No further detail is available" fallback instead of a specific explanation.
- No admin bulk action or order-list column exists for tracking (only per-order meta-box editing or the Bulk Tools CSV workflow) 2.2.0 – 30 October 2026 there is no way to add/edit tracking for multiple orders at once from the WooCommerce Orders list screen itself.
- Carrier auto-detection is intentionally minimal (5 regex patterns covering UPS, Amazon Logistics, Royal Mail/Parcelforce, and USPS) 2.2.0 – 30 October 2026 most of the 27 built-in carriers have no detectable tracking-number signature and always require the admin to pick a carrier manually.
- The optional route map is explicitly illustrative only: a straight polyline between two geocoded points, not a real routing/driving-directions API, and it depends on the third-party Nominatim public geocoding service being reachable and not rate-limiting the request (no local caching of geocode results between page loads is implemented in the frontend JS).
- The `AdminModule` class is a `class_alias` shim purely for backwards compatibility (per the CHANGELOG's 1.0.5 entry: "Introduced the `AdminModule` entrypoint while keeping settings storage unchanged") 2.2.0 – 30 October 2026 all actual logic lives in `Analytics`.
- No REST API and no Gutenberg block are provided 2.2.0 – 30 October 2026 integration for headless/decoupled frontends would need to be built on top of the existing shortcode/AJAX surface or by reading order meta directly.
