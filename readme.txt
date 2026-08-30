=== Kitgenix Order Tracking for WooCommerce ===
Contributors: kitgenix
Donate link: https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2
Tags: woocommerce, order tracking, shipment tracking, shipping, carrier
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.0
Requires Plugins: woocommerce
WC requires at least: 10.0
WC tested up to: 11.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Plugin URI: https://wordpress.org/plugins/kitgenix-order-tracking-for-woocommerce/
Author: Kitgenix
Author URI: https://kitgenix.com/
Author Plugin URI: https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce
Documentation URI: https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/documentation
Support URI: https://wordpress.org/support/plugin/kitgenix-order-tracking-for-woocommerce/
Author Support URI: https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/support
Feature Request URI: https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/feature-request

Add shipment tracking numbers to WooCommerce orders, notify customers by email, and give them a self-service order tracking page.

== Description ==

**Kitgenix Order Tracking for WooCommerce** adds shipment tracking to your store without a heavyweight shipping suite. Record a tracking number and carrier against any order, let WooCommerce emails carry that information automatically, and give customers a self-service tracking page so they stop emailing to ask "where is my order?"

The plugin stores tracking numbers and links to each carrier's own tracking page; it does not call carrier APIs to fetch live delivery status. Every "shipped", "in transit", "delivered" or "exception" state shown anywhere in the plugin is set by your staff, not pulled automatically from a courier.

= Key features =

* **Multiple shipments per order** – record split parcels, partial shipments, and replacements, each with its own carrier and tracking number.
* **Per-item shipment allocation** – choose which order items (and quantities) belong to each shipment, so staff and customers can see what's shipped and what's still outstanding. Allocated quantities are automatically capped at what was actually ordered.
* **27 built-in carriers** with ready-made tracking URL patterns – including Royal Mail, Parcelforce, Evri, DPD (UK & Ireland), An Post, DHL, UPS (US, UK & Ireland), FedEx, USPS, Canada Post, Australia Post, NZ Post, and more – plus an "Other" option for couriers not in the list.
* **Conservative carrier auto-detection** – recognisable tracking number formats (UPS, Amazon Logistics, Royal Mail/Parcelforce, USPS) are suggested automatically as an unconfirmed suggestion staff can accept or override – never a silent guess, and never applied to a carrier the store has disabled.
* **Regional carrier packs** – apply a ready-made carrier set for UK & Ireland, Europe, North America, Australia & New Zealand, or a global mixed-carrier list, or hand-pick and reorder carriers yourself.
* **Custom carriers** – add couriers that aren't built in, with your own tracking URL pattern and an icon uploaded from the media library.
* **CSV bulk import and export** – export every tracked shipment to CSV and re-import edited files to update many orders in one pass, ideal for spreadsheet-based fulfilment.
* **Self-service customer tracking page** – a shortcode-based page where customers look up tracking by order number and billing email, or by tracking number alone, with no account or login required.
* **Country-aware postcode verification** – full addresses, the illustrative route map, and item-level shipment detail are only shown once the customer confirms the postcode, ZIP code, or postal code on the order.
* **"Partially shipped" order status and email** – a dedicated WooCommerce order status and matching customer email for stores that ship orders in stages.
* **Tracking Analytics dashboard** – shipment coverage, time-to-ship, carrier and status breakdowns, exceptions, and privacy-safe lookup totals for your store.
* **Optional route map** – an illustrative store-to-customer route using bundled Leaflet and OpenStreetMap tiles, no Google Maps or API key required.
* **HPOS compatible** – works on both the classic order-edit screen and WooCommerce's High-Performance Order Storage screen.
* **No custom database tables** – shipments are stored as order meta, so your existing backups and WooCommerce data tools behave as expected.

= Adding tracking information =

Tracking is added from the **Kitgenix Order Tracking** meta box on the WooCommerce order edit screen. The "Add tracking" modal lets staff enter:

* a tracking number (required),
* a carrier (optional – choose from the list, leave it to automatic detection, or select "Other" for a custom link),
* the items and quantities included in the shipment,
* a shipped date and an optional estimated delivery date,
* a shipment status for your own records (Shipped, In transit, Delivered, Exception, Returned, Cancelled),
* an internal note visible only to staff, and
* an optional order status update (leave unchanged, mark Partially shipped, or mark Completed).

For fulfilment teams working from spreadsheets, the **Bulk Tools** tab under Kitgenix → Order Tracking exports all tracked shipments to CSV (order ID, carrier, tracking number, dates, status, and per-item quantities) and re-imports edited files, either appending new shipments or replacing all shipments on the matched orders.

= Customer notifications and tracking page =

When shipments exist on an order, tracking details – carrier, tracking number, a link to the carrier's tracking page, and any estimated delivery date – appear automatically in:

* the WooCommerce **Completed Order** email, and
* the plugin's own **Partially Shipped** email (sent when an order is moved to the "Partially shipped" status).

Duplicate-output guarding keeps the tracking block from appearing twice if email templates are customised.

Customers can also look up tracking themselves on any page using the `[kitgenix_tracking_form]` shortcode, by order number and billing email or by tracking number alone. Full addresses, the optional route map, and item-level shipment detail are only revealed once the postcode, ZIP code, or postal code on the order has been confirmed, using country-aware matching rather than a single postcode format.

= Tracking Analytics =

The Tracking Analytics screen (Kitgenix → Order Tracking) shows shipment coverage, average and median time-to-ship, shipments and exceptions by carrier and status, recent shipments, and privacy-safe totals for tracking numbers added and successful customer lookups. Results are computed in batches and cached briefly in transients to keep the dashboard responsive.

= Carrier management =

From the Carriers tab you can apply a recommended regional carrier pack, enable or disable built-in carriers, reorder them by priority, and add custom carriers with their own tracking URL pattern and icon.

= Developer features =

* Action hooks fire when shipments are created, updated, or deleted, and when the Partially Shipped notification is sent.
* Filters let you override the Nominatim geocoding endpoint and contact email used by the optional route map, and enable a debug order note on save.
* No custom database tables – shipments are stored as order meta.
* Translation-ready with a bundled .pot file.

See the Developers section further down this page for exact hook names, meta keys, and nonce actions.

= Compatibility =

Requires WooCommerce to be installed and active. The plugin declares compatibility with WooCommerce's custom order tables (HPOS) and works on both the classic order-edit screen and the HPOS order-edit screen. Leaflet (for the optional map) is bundled with the plugin – no CDN or external script is loaded for it.

This plugin is part of the free Kitgenix plugin family for WooCommerce (PDF invoicing, stock sync, custom product tabs, and more) and includes an optional "Kitgenix" admin page for discovering and managing other Kitgenix plugins.

== Installation ==

1. Install and activate WooCommerce if you haven't already – this plugin will not activate without it.
2. Install this plugin via Plugins → Add New (search for "Kitgenix Order Tracking for WooCommerce"), or upload the ZIP file to /wp-content/plugins/ and activate it.
3. Create a page (e.g. "Track Your Order") and add the shortcode:
   `[kitgenix_tracking_form]`
4. Open any WooCommerce order and use the Kitgenix Order Tracking meta box to add a shipment.
5. Send a Completed Order email (or use the Partially Shipped status) to confirm tracking appears correctly.
6. Optional: visit Kitgenix → Order Tracking → Carriers to apply a regional carrier pack, and → Bulk Tools if your fulfilment team needs CSV import/export.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
Yes. WooCommerce must be installed and active. If WooCommerce isn't active, the plugin deactivates itself automatically and shows a notice.

= Can I add multiple tracking numbers to one order? =
Yes. Add as many shipments as you need per order, including split parcels and partial shipments, each with its own carrier and tracking number.

= Does the plugin fetch live tracking status from couriers? =
No. It stores the tracking number and links to the carrier's own tracking page. Any shipment status shown in the admin or analytics (Shipped, In transit, Delivered, etc.) is set manually by your staff – the plugin does not call carrier APIs.

= Which couriers are supported? =
Built-in carriers include Amazon Logistics, An Post, Australia Post, Bpost, Canada Post, Chronopost, Colissimo/La Poste, Correos, DHL, Deutsche Post/DHL Germany, DPD Ireland, DPD UK, DX, Evri, FedEx, GLS, NZ Post, Parcelforce Worldwide, PostNL, PostNord, Royal Mail, UPS (US, Ireland, UK), USPS, Woodside Distribution, and Yodel, plus an "Other" option for custom links.

= Can I use a courier that isn't listed? =
Yes. Use Kitgenix → Order Tracking → Carriers to add a custom carrier with its own tracking URL pattern (using `{tracking_number}`) and icon, or select "Other" when adding tracking to an order.

= Does the plugin guess the carrier for me? =
It can suggest one for tracking numbers with a distinctive, unambiguous format (UPS's "1Z" prefix, Amazon Logistics' "TBA" prefix, Royal Mail/Parcelforce and USPS formats). This is always shown as an unconfirmed suggestion – it never overwrites a carrier you've explicitly chosen, and ambiguous numeric formats are left unmatched rather than guessed.

= Can I import or export tracking in bulk? =
Yes. The Bulk Tools tab exports all tracked shipments to CSV and lets you re-import edited files, either appending new shipments or replacing all shipments on the matched orders. Per-item quantities round-trip using an `item_id:qty|item_id:qty` format.

= Do customers get tracking in emails automatically? =
Yes, once a shipment exists on the order. Tracking appears in the WooCommerce Completed Order email and in the plugin's Partially Shipped email.

= What is the "Partially shipped" order status and email? =
The plugin adds a custom order status, "Partially shipped", and a matching customer email. It's designed for stores that ship orders in stages and want to notify customers with tracking before the whole order is complete.

= Can customers track an order without logging in? =
Yes. The `[kitgenix_tracking_form]` tracking page is public and supports lookup by order number and billing email, or by tracking number alone.

= Why can't a customer see the full address or item details on the tracking page? =
For privacy, full addresses, the route map, and item-level shipment detail are only shown after the customer confirms the postcode, ZIP code, or postal code stored on the order. This check uses country-aware matching, not a single fixed postcode format.

= Does the plugin work with WooCommerce High-Performance Order Storage (HPOS)? =
Yes. The plugin declares HPOS compatibility and its order meta box and save logic work on both the classic and HPOS order-edit screens.

= Do I need a Google Maps API key for the route map? =
No. The optional map uses Leaflet (bundled with the plugin) and OpenStreetMap tiles, with no API key or CDN required.

= The route map isn't showing – what should I check? =
Check for a strict Content Security Policy or aggressive script/asset optimisation that might block the bundled Leaflet script or the OpenStreetMap tile and geocoding requests.

= Does this plugin create shipping labels or replace a fulfilment system? =
No. It records tracking numbers you already have and displays them to customers; it doesn't generate labels, rates, or replace a fulfilment/shipping suite.

= Is this plugin GDPR-friendly? =
It doesn't set tracking cookies and uses WooCommerce's existing order data for lookups. Carrier tracking links and the optional map's tile/geocoding requests are external services – review their own privacy policies for your needs.

= Does this plugin talk to any other Kitgenix plugins or a remote Kitgenix server? =
No customer or store data is sent to Kitgenix. The plugin adds an optional "Kitgenix" admin page that can look up publicly available plugin listing information (install counts, ratings, icons) from WordPress.org for other Kitgenix plugins, to help you find and manage them – see External Services below.

== Screenshots ==

1. Adding tracking to an order: the "Add tracking" modal with item quantities, tracking number, carrier, shipped date, and an optional order status update.
2. The customer tracking page: order number and billing email lookup, with optional postcode verification.
3. Tracking results: shipment tracking, order details, addresses, and item shipped/not-shipped status.
4. WooCommerce Completed Order email showing the Shipment Tracking section.
5. The plugin's Partially Shipped email for staged fulfilment.
6. The optional route map from store to delivery address, powered by Leaflet and OpenStreetMap.
7. The Tracking Analytics overview: shipment coverage, time-to-ship, exceptions, and lookup totals.

== Developers ==

Text domain:
kitgenix-order-tracking-for-woocommerce

Shortcode:
[kitgenix_tracking_form]

Data model (high level):
- Shipments are stored as order meta under `_kitgenix_tracking_shipments` (array)
- Tracking numbers are indexed for fast lookup via repeated order meta `_kitgenix_tracking_number` (one meta row per tracking number)
- Each shipment can also store a `status` value (shipped, in_transit, delivered, exception, returned, cancelled) for admin records and analytics – this is set by staff, not fetched from a carrier
- Admin UI stores the last chosen "update order status" action as order meta `kitgenix_order_status_action` (values: `no_change`, `partial`, `completed`)
- Carrier settings are stored in the option `kitgenix_order_tracking_for_woocommerce_carriers_settings`
- Counter option: `kitgenix_order_tracking_for_woocommerce_tracking_numbers_added` (privacy-safe total)
- Counter option: `kitgenix_order_tracking_for_woocommerce_lookup_count` (successful tracking-page lookups)
- Event log option: `kitgenix_order_tracking_event_log`
- Analytics aggregates are cached briefly in transients prefixed `kitgenix_order_tracking_for_woocommerce_analytics_...`
- Activation redirect uses transient `kitgenix_order_tracking_for_woocommerce_do_activation_redirect` (about 30 seconds)
- No custom database tables are created
- No custom post types, taxonomies, or REST API routes are registered by this plugin
- Uninstall does not remove order meta or shipment data (to preserve historical tracking) – see Uninstall behaviour below

Nonces / capabilities (summary):
- Order edit tracking save uses nonce action `kitgenix_order_tracking_save` (`kitgenix_order_tracking_nonce`) and requires `edit_shop_order`.
- Order-items modal loads via AJAX action `kitgenix_order_tracking_for_woocommerce_get_order_items` using nonce action `kitgenix_order_tracking`.
- Tracking form submissions use nonce action `kitgenix_tracking_lookup` (`kitgenix_tracking_nonce`).
- Carriers (Shippers) settings form uses nonce action `kitgenix_order_tracking_for_woocommerce_save_carriers` (`kitgenix_order_tracking_for_woocommerce_carriers_nonce`).
- CSV export/import on the Bulk Tools tab use the `kitgenix_order_tracking_export_shipments` and `kitgenix_order_tracking_import_shipments` nonce actions and require the plugin's admin capability (`manage_woocommerce`, or `manage_options` if WooCommerce management capabilities aren't available).

Hooks / filters:
- kitgenix_order_tracking_shipment_created( int $order_id, array $shipment ) (action, fires after a new shipment is saved)
- kitgenix_order_tracking_shipment_updated( int $order_id, array $shipment, array $previous_shipment ) (action, fires after an existing shipment is changed)
- kitgenix_order_tracking_shipment_deleted( int $order_id, array $shipment ) (action, fires after a shipment is removed)
- kitgenix_order_tracking_shipment_notification_sent( int $order_id, string $email_id ) (action, fires after the Partially Shipped email is sent)
- kitgenix_order_tracking_for_woocommerce_debug_save( bool $enabled, int $order_id ): bool (filter, enable a debug order note on save)
- kitgenix_order_tracking_for_woocommerce_nominatim_email( string $email ): string (filter, supply/override the Nominatim contact email param)
- kitgenix_order_tracking_for_woocommerce_nominatim_endpoint( string $endpoint ): string (filter, override the geocoding endpoint)

Templates:
- The custom Partially Shipped email uses standard WooCommerce template override patterns via the files in `templates/emails/` and `templates/emails/plain/`.

Admin AJAX endpoints:
- wp_ajax_kitgenix_order_tracking_for_woocommerce_get_order_items (load order items into the shipment modal)

Uninstall behaviour:
- Removed on uninstall: the carrier settings option, the tracking-numbers-added and lookup-count counter options, the event log option, and all plugin-prefixed transients (including any Kitgenix hub caches described below); the same options are also cleaned up per-site on multisite.
- Not removed on uninstall: order meta (`_kitgenix_tracking_shipments`, `_kitgenix_tracking_number`) and the "Partially shipped" order status/history stay on your orders, so historical tracking data and reporting are preserved even after the plugin is removed.

== External Services ==

This plugin may connect to external services in three situations:

1) When customers click courier tracking links
Tracking links open the carrier's own tracking page (e.g. Amazon Logistics, An Post, DHL, DPD, DX, Evri, FedEx, Parcelforce Worldwide, Royal Mail, UPS, Woodside Distribution, Yodel, or a custom "Other" URL you configure). These links may contain the tracking number. The plugin does not call any carrier API on its own – it never fetches live delivery status from a courier.

2) When the optional route map is enabled
The tracking view may request:
- OpenStreetMap tile images, from `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`.
- Nominatim geocoding requests, to convert store and customer address details into map coordinates. This defaults to `https://nominatim.openstreetmap.org/search` and may include the full store and delivery address string in the `q` parameter. A developer filter lets you override the endpoint and supply a contact email for the request.
Leaflet (the mapping library) is bundled with the plugin – no CDN or external script is loaded to render the map itself.

3) In wp-admin (Kitgenix hub)
This plugin includes a shared "Kitgenix hub" admin page that helps you find and manage other Kitgenix plugins. It uses WordPress core's `plugins_api()` function to fetch publicly available plugin listing data from the WordPress.org Plugins API (wordpress.org) – active install counts, ratings, and icon/banner artwork – for a fixed list of Kitgenix plugin slugs. No site content, order data, or personal data is sent as part of these requests; results are cached in transients for up to 24 hours (`kitgenix_hub_wporg_active_installs_v1`, `kitgenix_hub_wporg_ratings_v1`, `kitgenix_hub_wporg_media_v1`) to minimise repeat calls.

No personal data is sold or shared by this plugin. Where external services are used, requests may include data required to perform the function (for example, a tracking number in a carrier link, or address data used for geocoding). Review the relevant provider's own privacy policy for your use case.

== Trademark Notice ==

All carrier names and logos are trademarks of their respective owners. This plugin is not affiliated with or endorsed by any courier, carrier, OpenStreetMap, or Nominatim service.

== Support Development ==

If this plugin reduces "where is my order?" tickets and helps customers self-serve tracking, you can support ongoing development with a donation via PayPal:
https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2

== Credits ==
Built with ❤︎ by @kitgenix - https://kitgenix.com

== Upgrade Notice ==

= 2.0.0 =
Major admin redesign around the shared Kitgenix design system, a new Kitgenix Hub page, and reworked Analytics/Carriers/Bulk Tools/Support/Log screens. Recommended for all websites.

== Changelog ==

= 2.0.0 (25 August 2026) =

* New: Redesigned the entire admin experience around the shared Kitgenix design system – a sticky topbar with an Analytics dropdown menu, live settings search, dark/light theme toggle, and mobile hamburger menu replaces the previous page header and tab strip.
* New: Added a redesigned central Kitgenix Hub page for discovering, installing, activating, and reviewing every Kitgenix plugin from one screen, including the new Image Optimizer plugin.
* Improved: Rebuilt the Analytics, Carriers, Bulk Tools, Support, and Log tabs around a consistent set of cards, toggle switches, colour-coded status badges, empty states, and a drag-and-drop CSV upload zone, replacing the previous form tables and plain links.
* Improved: The Tracking Analytics stat-tile grid – including orders in range, time-to-ship, lookups, and other metrics – now uses a fixed four-column layout on wider screens, with responsive fallbacks to two columns and then one column on smaller displays.
* Improved: The "Add tracking" modal on the WooCommerce order edit screen and the custom carrier edit modal now share a common accessible modal component with proper focus handling, Escape-to-close support, and accessible labelling.
* Improved: Custom carrier rows now use compact icon-only edit and delete action buttons with colour-coded enabled/disabled badges instead of plain text links.
* Improved: The Log tab now displays entries in a searchable, paginated table containing time, context, outcome, and a plain-English note instead of a plain text block.
* Improved: CSV bulk import failures now record the specific underlying reason – including unreadable files, files that cannot be opened, missing or invalid headers, and unexpected processing errors – in the Log tab alongside the existing on-screen message.
* Improved: Added a reference table to the Log tab explaining what each recorded category means and whether administrator action is required.
* Improved: The Support tab is now three focused cards – a donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a "get involved" panel for reviews and plugin links – replacing the previous stack of donate, trust, and community cards.
* Improved: Moved the Log tab directly before Support in the admin navigation for a more logical workflow.
* Improved: Refreshed the Kitgenix logo and favicon assets used throughout the admin menu icon, Kitgenix Hub header, and Analytics header.
* Fix: The "Follow Us" social links no longer appear twice on the Support tab, removing the previous duplication between the inline social block and sidebar.
* Fix: The "Shipments over time" sparkline no longer displays a bold solid line when there is no shipment data. Empty charts now render a thinner, muted baseline so the absence of data cannot be mistaken for a genuine flat trend.
* Fix: Number inputs across the Analytics and settings screens are no longer cramped to a fixed 50px width.
* Dev: Added a shared `kitgenix-admin-components.js` controller providing modal open/close handling, toast notifications, collapsible cards, copy-to-clipboard controls, and live table search. Both `admin-analytics.js` and `admin-order-tracking.js` now use the shared component controller.
* Dev: Three previously silent internal errors – Analytics date parsing, tracking-count lookups, and HPOS order-screen detection – are now recorded in the Log tab for easier diagnostics while remaining non-blocking and preserving existing behaviour.
