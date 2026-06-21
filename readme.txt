=== Kitgenix Order Tracking for WooCommerce ===
Contributors: kitgenix
Donate link: https://buymeacoffee.com/kitgenix
Tags: woocommerce, tracking, shipping, shipments, emails
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.3
Requires Plugins: woocommerce
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

Add WooCommerce order tracking with multi-shipment support, international carriers, CSV import/export, email links, and a customer tracking page.

== Description ==

Customers don’t just want a tracking number — they want confidence.

After purchase, the questions are predictable:
“Has it shipped yet?”, “Is this coming in multiple parcels?”, “Which courier is it with?”, and “Can I track it right now?”

**Kitgenix Order Tracking for WooCommerce** gives you a clean, reliable way to manage **WooCommerce shipment tracking** without bloated shipping suites. Add **multiple shipments** to a single order (split deliveries, replacements, multi-parcel, different carriers), bulk import or export shipment data in CSV format for spreadsheet-based fulfilment workflows, apply international carrier packs for different regions, show tracking clearly to customers, and reduce “where is my order?” support tickets with a self-service tracking page.

It also includes a **Tracking Analytics** screen inside WooCommerce so you can understand shipping speed, exceptions, and how often customers check tracking.

= Built for real-world shipping workflows =

This plugin is especially useful if you:
- ship orders in more than one box (multi-parcel / multi-shipment),
- part-ship items while the rest are backordered,
- manage fulfilment in spreadsheets or need to update lots of tracking numbers at once,
- ship to multiple regions and need better carrier coverage than UK/Ireland-only defaults,
- use multiple couriers (Amazon Logistics, An Post, DHL, DPD, DX, Evri, FedEx, Parcelforce, Royal Mail, UPS, Woodside, Yodel, etc.),
- want a branded tracking page instead of sending customers to random courier sites,
- want tracking shown in emails (cleanly, without duplicate blocks),
- want a “Partially shipped” workflow and email for staged fulfilment.

= What the plugin does =

1. Add one or more shipments to a WooCommerce order (carrier + tracking number + optional shipped date + per-item quantities).
2. Generate courier tracking links from built-in carrier URL patterns (plus an “Other” courier option for custom links).
3. Display tracking for customers:
   - in emails,
   - and on a public tracking page via shortcode.
4. Optionally show an approximate delivery route map (Leaflet + OpenStreetMap; no Google Maps, no API key, no CDN).
5. Monitor operations with a Tracking Analytics dashboard (ship speed, totals, breakdowns, lookups, recent shipments).
6. Import or export shipment rows in CSV format so warehouse teams can round-trip tracking updates through Excel or Google Sheets.
7. Apply regional carrier packs and country-aware postcode / ZIP / postal-code verification for broader international use.

= Key features =

== Multiple shipments (multi-parcel, split deliveries, replacements) ==
Add as many shipments as you need to a single order. Each shipment can include:
- carrier (optional; if omitted, no carrier tracking link is generated),
- tracking number,
- shipped date (optional),
- shipped items + quantities (line-item allocation for partial fulfilment).

This makes partial shipments and multi-parcel deliveries accurate and customer-friendly.

== Built-in carriers (international-ready) ==
Includes a carrier registry with icons and tracking URL patterns, so customers get direct “Track shipment” links.

Built-in carriers include:
- Amazon Logistics
- An Post
- Australia Post
- Bpost
- Canada Post
- Chronopost
- Colissimo / La Poste
- Correos
- DHL
- Deutsche Post / DHL Germany
- DPD Ireland
- DPD UK
- DX
- Evri
- FedEx
- GLS
- NZ Post
- Other (custom link / no pattern)
- Parcelforce Worldwide
- PostNL
- PostNord
- Royal Mail
- UPS US
- UPS Ireland
- UPS UK
- USPS
- Woodside Distribution
- Yodel

== Carrier management (built-in + custom) ==
The carrier registry is configurable from Kitgenix → Order Tracking → Carriers:
- apply recommended carrier packs for UK & Ireland, Europe, North America, Australia & New Zealand, or a global mixed-carrier setup,
- enable or disable built-in carriers,
- reorder carriers by priority so the most-used options appear first,
- add custom carriers with their own tracking URL patterns,
- upload custom carrier icons from the WordPress media library,
- keep the built-in “Other” fallback available for manual links.

== International carrier packs and country-aware validation ==
Stores shipping internationally often need two things at once: broader carrier coverage and customer verification that matches the destination market.

The plugin now includes recommended built-in carrier packs for:
- UK & Ireland
- Europe
- North America
- Australia & New Zealand
- Global / mixed-carrier stores

Applying a carrier pack configures a recommended built-in carrier set and priority order for that region, while still letting you fine-tune carriers manually afterwards.

The customer tracking form now also uses country-aware postcode validation rules, so ZIP codes, postal codes, and country-prefixed formats are handled more accurately for international orders instead of assuming every market behaves like a UK postcode.

== Customer tracking page (self-service) ==
Add a tracking page anywhere using:
[kitgenix_tracking_form]

The tracking form supports:
- Order-based lookup (order number + billing email, with country-aware postcode / ZIP / postal-code verification used before showing full addresses, the route map, and item details), and
- Tracking-number lookup (useful when the customer only has parcel details)

When shipments have item allocations, the tracking results can show which items were shipped in each parcel and which items remain unshipped. Postcode verification is normalised so common spacing and punctuation differences do not block valid customer lookups.

Tracking-number lookups show shipment status and tracking details, but full addresses, route maps, and item-level detail stay behind postcode verification when the order includes a stored postcode.

Great for reducing support tickets and giving customers a consistent experience.

== Tracking in WooCommerce emails (done cleanly) ==
Tracking is appended to:
- WooCommerce Completed Order email, and
- the plugin’s own Partially Shipped email

Includes duplicate-guarding so tracking blocks aren’t repeated if templates/hooks are customised.

== “Partially shipped” order status + email (included) ==
The plugin registers:
- a custom order status: **wc-partially-shipped** (“Partially shipped”), and
- a custom email: **Kitgenix Partially Shipped**

Ideal for stores that ship in stages and want to send tracking early without marking the whole order completed.

== Optional route map (OpenStreetMap + Leaflet) ==
If enabled, the tracking view can show an approximate route map using:
- Leaflet (bundled locally — no CDN),
- OpenStreetMap tiles,
- Nominatim geocoding (endpoint + contact email are filterable)

Includes fallbacks if geocoding fails (shows what it can, or hides the map with a message).

== Tracking Analytics dashboard (inside WooCommerce) ==
Adds an admin screen under the Kitgenix hub with practical metrics:
- shipped vs unshipped orders,
- total shipments,
- exceptions,
- average/median time-to-ship,
- carrier/status breakdowns,
- recent shipments,
- privacy-safe totals for tracking numbers added and successful customer lookups,
- customer tracking lookup totals,
- date range selection (predefined windows like 7–180 days)

Calculations are batched and cached briefly (transients) to keep the dashboard fast.

== Bulk shipment import and export ==
Stores that process a lot of orders often work from spreadsheets rather than opening each order one by one.

The new Bulk Tools tab in Kitgenix → Order Tracking lets you:
- export tracked shipments to CSV with one row per shipment,
- re-import edited CSV files to add tracking updates across many orders,
- choose whether imports append new shipments or replace all shipments on matched orders,
- round-trip per-item shipment quantities using the `item_id:qty|item_id:qty` format,
- keep warehouse and support workflows aligned by using the exported `order_id` values for reliable imports.

The CSV workflow is built on top of the plugin’s existing shipment data model, so imported shipments use the same storage, tracking links, customer email rendering, analytics totals, and public tracking-page output as shipments added manually in the order screen.

= How it works (quick overview) =

1) Add tracking in wp-admin
Edit an order and use the **Order Tracking** meta box. Create shipments using the “Add tracking” modal:
- tracking number
- carrier dropdown (explicit selection, or leave blank)
- shipped date
- item quantities (loaded via AJAX)
- optional order status update after save (don’t change / partially shipped / completed)

2) Tracking appears for customers
If shipments exist, customers see a clear “Shipment Tracking” section in key emails and via the tracking page.

3) Customers can track without contacting you
Use the shortcode page so customers can look up tracking by order details or by tracking number.

4) You can measure shipping performance
Open Tracking Analytics to see speed, breakdowns, and customer behaviour.

== Quick Start ==

1. Install and activate the plugin.
2. Create a page called “Track Your Order” (or similar).
3. Add this shortcode:
   [kitgenix_tracking_form]
4. Edit any order and add a shipment in the Order Tracking meta box.
5. Send a Completed Order email (or use Partially Shipped) and confirm tracking is shown.
6. Optional: use Kitgenix → Order Tracking → Bulk Tools when your fulfilment team needs CSV import/export workflows.
7. Optional: use Kitgenix → Order Tracking → Carriers to apply a regional carrier pack before fine-tuning carrier priorities.

== Installation ==

1. Install via Plugins → Add New and search for “Kitgenix Order Tracking for WooCommerce”, or upload the ZIP to /wp-content/plugins/.
2. Activate the plugin.
3. Create a tracking page and add:
   [kitgenix_tracking_form]
4. Add shipments in WooCommerce → Orders → Edit order.
5. Optional: use Kitgenix → Order Tracking → Bulk Tools to export or import shipments in CSV format.

== Frequently Asked Questions ==

= Can I add multiple tracking numbers to one WooCommerce order? =
Yes. You can add multiple shipments per order, including split parcels and partial shipments.

= Can different shipments use different couriers? =
Yes. Each shipment can have its own carrier and tracking number.

= Can I import or export shipment tracking in CSV format? =
Yes. The Bulk Tools tab lets you export shipments to CSV and import edited files again. Each CSV row represents one shipment, and imports can either append new shipments or replace all shipments on the matched orders.

= Which couriers are supported? =
Built-in carriers include Amazon Logistics, An Post, Australia Post, Bpost, Canada Post, Chronopost, Colissimo / La Poste, Correos, DHL, Deutsche Post / DHL Germany, DPD Ireland, DPD UK, DX, Evri, FedEx, GLS, NZ Post, Other, Parcelforce Worldwide, PostNL, PostNord, Royal Mail, UPS US, UPS Ireland, UPS UK, USPS, Woodside Distribution, and Yodel.

= Can I use a courier that isn’t listed? =
Yes. Use Kitgenix → Order Tracking → Carriers to add custom carriers (including tracking URL patterns with `{tracking_number}`) and enable/disable built-in carriers. The built-in “Other” carrier is a fallback option with no automatic tracking URL.

= Can I control which carriers staff see in the admin dropdown? =
Yes. You can apply a recommended regional carrier pack, disable built-in carriers, change their priority order, and add custom carriers with their own icons and tracking URL patterns.

= Does the plugin support international carrier packs? =
Yes. The Carriers tab now includes recommended carrier packs for UK & Ireland, Europe, North America, Australia & New Zealand, and global mixed-carrier stores. Applying a pack sets a recommended built-in carrier list and priority order that you can still fine-tune manually.

= Do customers get tracking in emails automatically? =
Yes. Tracking is shown in the Completed Order email and the plugin’s Partially Shipped email when shipments exist.

= Why isn’t tracking showing in the Completed Order email? =
Common causes: tracking isn’t saved yet, the Completed Order email is disabled in WooCommerce, or another plugin/theme overrides email templates/hooks. You can also test with the Partially Shipped email.

= What is the “Partially shipped” email? =
It’s a dedicated email included with the plugin, designed for staged fulfilment. It can be triggered when an order status changes to wc-partially-shipped.

= Can customers track without logging in? =
Yes. The tracking page is public. Customers can look up tracking by order details or by tracking number.

= Can customers track by tracking number only? =
Yes. Tracking-number lookup is supported.

= Does postcode verification work for ZIP codes and postal codes outside the UK? =
Yes. The tracking form now uses country-aware postcode / ZIP / postal-code matching so international orders are not forced through a UK-style postcode comparison.

= Will customers see which items shipped in each parcel? =
Yes. When you assign item quantities to shipments, the tracking view can show shipped items, remaining items, and per-parcel detail after the postcode check is satisfied.

= Does the plugin work with HPOS? =
Yes. The plugin declares compatibility with WooCommerce custom order tables (HPOS).

= Do I need Google Maps for the map feature? =
No. The optional map uses Leaflet + OpenStreetMap. No API key required.

= The map is blank — what should I check? =
Check for a strict Content Security Policy, blocked OpenStreetMap tile requests, or aggressive script optimisation. Ensure the tracking page is allowed to load tiles and that frontend assets aren’t being stripped.

= Does this plugin create WooCommerce Shipping labels or replace fulfilment suites? =
No. It stores shipment tracking data and displays it in the right places. It doesn’t replace full shipping/fulfilment suites.

= Is this GDPR friendly? =
The plugin doesn’t add tracking cookies. It uses WooCommerce order details for lookups. Carrier links and map tiles/geocoding are external services; review their policies for your needs.

== Screenshots ==

1. Admin order screen: Add shipments with carrier, tracking number, shipped date, and item quantities.
2. Tracking form page: Track by order details or tracking number.
3. Tracking results: carriers, tracking numbers, and “Track shipment” links.
4. Completed Order email: clean Shipment Tracking section.
5. Partially Shipped email: tracking shown for staged fulfilment.
6. Optional route map: Leaflet + OpenStreetMap preview.
7. Tracking Analytics dashboard: shipped vs unshipped, shipments, exceptions, time-to-ship, and lookup totals.
8. Carrier management screen with built-in carrier controls, custom carriers, priorities, and icon uploads.

== Developers ==

Text domain:
kitgenix-order-tracking-for-woocommerce

Shortcode:
[kitgenix_tracking_form]

Data model (high level):
- Shipments are stored as order meta under `_kitgenix_tracking_shipments` (array)
- Tracking numbers are indexed for fast lookup via repeated order meta `_kitgenix_tracking_number` (one meta row per tracking number)
- Each shipment can also store a `status` value for future workflow and analytics extensions
- Admin UI stores the last chosen “update order status” action as order meta `kitgenix_order_status_action` (values: `no_change`, `partial`, `completed`)
- Carrier settings are stored in the option `kitgenix_order_tracking_for_woocommerce_carriers_settings`
- Counter option: `kitgenix_order_tracking_for_woocommerce_tracking_numbers_added` (privacy-safe total)
- Counter option: `kitgenix_order_tracking_for_woocommerce_lookup_count` (successful tracking-page lookups)
- Analytics aggregates are cached briefly in transients prefixed `kitgenix_order_tracking_for_woocommerce_analytics_...`
- Activation redirect uses transient `kitgenix_order_tracking_for_woocommerce_do_activation_redirect` (about 30 seconds)
- No custom database tables are created
- Uninstall does not automatically remove order meta (to preserve historical tracking)

Nonces / capabilities (summary):
- Order edit tracking save uses nonce action `kitgenix_order_tracking_save` (`kitgenix_order_tracking_nonce`) and requires `edit_shop_order`.
- Order-items modal loads via AJAX action `kitgenix_order_tracking_for_woocommerce_get_order_items` using nonce action `kitgenix_order_tracking`.
- Tracking form submissions use nonce action `kitgenix_tracking_lookup` (`kitgenix_tracking_nonce`).
- Carriers (Shippers) settings form uses nonce action `kitgenix_order_tracking_for_woocommerce_save_carriers` (`kitgenix_order_tracking_for_woocommerce_carriers_nonce`).

Hooks / filters:
- kitgenix_order_tracking_for_woocommerce_debug_save( bool $enabled, int $order_id ): bool (enable debug order note on save)
- kitgenix_order_tracking_for_woocommerce_nominatim_email( string $email ): string (supply/override Nominatim email param)
- kitgenix_order_tracking_for_woocommerce_nominatim_endpoint( string $endpoint ): string (override geocoding endpoint)

Templates:
- The custom Partially Shipped email uses standard WooCommerce template override patterns via the files in `templates/emails/` and `templates/emails/plain/`.

Admin AJAX endpoints:
- wp_ajax_kitgenix_order_tracking_for_woocommerce_get_order_items (load order items into the shipment modal)

== External Services ==

This plugin may connect to external services in two situations:

1) When customers click courier tracking links
Tracking links open the carrier’s tracking page (e.g. Amazon Logistics, An Post, DHL, DPD, DX, Evri, FedEx, Parcelforce Worldwide, Royal Mail, UPS, Woodside Distribution, Yodel) or a custom “Other” URL. These links may contain the tracking number.

2) When the optional map is enabled
The tracking view may request:
- OpenStreetMap tile images
- Nominatim geocoding requests (to convert store/customer address details into coordinates)

Map implementation notes:
- Leaflet is bundled locally (no CDN fallback).
- Map tiles are loaded from `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`.
- Geocoding defaults to `https://nominatim.openstreetmap.org/search` and may include the full store and delivery address string in the `q` parameter.

3) In wp-admin (Kitgenix hub)
This plugin includes a shared “Kitgenix hub” component in wp-admin which may fetch publicly available plugin metadata from WordPress.org using WordPress core’s `plugins_api()` function.

Caching:
- Transient: `kitgenix_hub_wporg_active_installs_v1`
- Transient: `kitgenix_hub_wporg_ratings_v1`
- Transient: `kitgenix_hub_wporg_media_v1`

Leaflet assets are bundled locally (no CDN).

No personal data is sold or shared by this plugin. Where external services are used, requests may include data required to perform the function (for example, a tracking number in a carrier link, or address data used for geocoding). Review the relevant provider policies for your use case.

== Trademark Notice ==

All carrier names and logos are trademarks of their respective owners. This plugin is not affiliated with or endorsed by any courier, carrier, OpenStreetMap, or Nominatim service.

== Support Development ==

If this plugin reduces “where is my order?” tickets and helps customers self-serve tracking, you can support ongoing development here:
https://buymeacoffee.com/kitgenix

== Credits ==
Built with ❤︎ by @kitgenix - https://kitgenix.com

== Upgrade Notice ==

= 1.1.3 =
Recommended for all websites.

== Changelog ==
= 1.1.3 (26 May 2026) =
* Compatibility: Confirmed compatibility with WordPress 7.0 and WooCommerce 10.x.
* New: Added a Log tab to the admin settings page. Records recent plugin activity (carrier settings saves and key operations) with timestamps, context labels, and plain-English notes to aid troubleshooting.
* Fix: Activity log data is now fully cleaned up when the plugin is uninstalled.

= 1.1.2 (26 May 2026) =
* Dev: Skipped to be in line with other Kitgenix Plugins

= 1.1.1 (26 May 2026) =
* Dev: Skipped to be in line with other Kitgenix Plugins

= 1.1.0 (7 May 2026) =
* New: Added international carrier packs for UK & Ireland, Europe, North America, Australia & New Zealand, and global mixed-carrier stores in the Carriers admin tab.
* New: Expanded the built-in carrier library with additional regional carriers including Australia Post, Canada Post, USPS, PostNL, GLS, Chronopost, Colissimo / La Poste, Correos, Deutsche Post / DHL Germany, PostNord, Bpost, NZ Post, and UPS US.
* Improvement: Customer postcode verification now uses country-aware postcode / ZIP / postal-code matching for international orders instead of relying on one generic postcode normalizer.
* New: Added a Bulk Tools tab to the Order Tracking admin screen with shipment CSV export and import workflows for spreadsheet-based fulfilment teams.
* Improvement: Bulk imports now support append and replace modes, so stores can either add new tracking rows or overwrite shipments on the matched orders from a CSV file.
* Improvement: Shipment CSV exports and imports round-trip item allocations using the `item_id:qty|item_id:qty` format and reuse the same shipment storage used by the order edit screen, emails, analytics, and the public tracking page.

= 1.0.6 (19 March 2026) =
* UI: Improved the Kitgenix admin header layout for better alignment and less clutter.
* UI: Social links in admin headers now render as compact icon buttons (with accessible labels).
* UI: Added responsive header helpers so titles/description and actions/links lay out consistently.
* Fix: “Shipper settings saved” notice now displays in the WordPress standard notice area (not inside the header).
* Fix: Added defensive notice normalization to prevent notices being relocated into the header by other scripts.
* UI: Tracking Analytics “Showing last …” summary and Refresh action now appear within the filters bar.
* Fix: Removed a PHP warning in the Tracking Analytics Support tab (undefined variable).
* UI: Admin tables inside Kitgenix pages now use Kitgenix styling for a more consistent branded look.
* Fix: Added spacing between adjacent action links/buttons (e.g., Edit/Delete).
* UI: Carriers management now shows built-in carriers and custom carriers as separate cards on the Carriers tab.
* Improvement: Custom carriers are now managed with an Add form plus Edit/Delete actions (Edit opens a popup modal).
* UI: Added icon Upload button for custom carriers (uses the WordPress media library).
* Fix: The built-in “Other” carrier is now always listed last.
* Cleanup: Normalised admin nonce handling in analytics and settings flows for WordPress.org review compliance.
* Maintenance: Updated the plugin Author URI to the public Kitgenix WordPress.org profile and replaced the old custom admin-menu icon CSS with the native Dashicons icon.

= 1.0.5 (18 February 2026) =
* New: Added a Shippers tab (Kitgenix → Order Tracking → Shippers) for carrier management.
* New: Added enable/disable controls for built-in carriers (always keeps the “Other” fallback).
* New: Added carrier priority ordering so you can control which carriers appear first in the admin carrier dropdown.
* New: Added custom carriers with per-carrier enabled toggle, name, tracking URL pattern (supports `{tracking_number}`), optional icon URL, and optional priority.
* Change: Carrier dropdown now shows enabled carriers only, ordered by priority.
* Change: Renamed the Kitgenix submenu to “Order Tracking”.
* UI: Added Stock Sync for WooCommerce to the Kitgenix hub cards.
* Fix: Corrected Kitgenix hub card markup for consistent layout.
* Docs: Updated documentation, readme.txt, and WordPress.org screenshots.
* Dev: Introduced `AdminModule` entrypoint (keeps settings storage unchanged).
* Cleanup: Uninstall now removes the carrier settings option.
* Dev: Regenerated /languages/kitgenix-order-tracking-for-woocommerce.pot translation template.

= 1.0.4 (27 January 2026) =
* Improvement: Minor bug fixes, i18n and PHPCS improvements; analytics and email template refinements.
* Change: Declared PHP requirement as 8.1.
* Cleanup: PHPCS/i18n/security fixes across admin and core files (output escaping, translator comments, optional nonce checks).
* Change: Standardised allowed HTML and escaping in the admin hub and analytics views for consistent safe output.
* Fix: Hardened analytics/admin asset enqueue detection to prefer $_GET['page'] then fall back to hook-suffix so analytics and hub assets load on existing installs.

= 1.0.3 (05 January 2026) =
* Improvement: Updated the email hook to align with WooCommerce’s modern email template structure.
* Cleanup: Implemented minor code refinements and cleanup for better consistency and maintainability.

= 1.0.2 (01 January 2026) =
* New: Added a new top-level Kitgenix admin hub menu to manage Kitgenix plugins from one place (install/activate/open).
* New: Tracking Analytics is now available under Kitgenix → Tracking Analytics, with a refreshed branded header and quick links to documentation/support/reviews.
* New: Added a Support tab and a new (privacy-safe) “tracking numbers added” metric; ranklist percentage bars now render reliably via a small admin script.
* Improvement: Admin order screen refactor — improved “Add tracking” modal markup/CSS class naming and modal show/hide behaviour.
* Improvement: Admin styling updates — introduced shared Kitgenix design tokens (including better dark-mode support) and added bundled hub styles/logos.
* Change: Developer-facing — renamed some internal actions/filters/options to match the full plugin slug (e.g. AJAX action, Nominatim filters, lookup counter option). Update any custom code that targets the old names.

= 1.0.1 (18 December 2025) =
* New: Shortcode tracking page now shows a full order summary and item statuses when an order has not yet shipped (no shipments) — including optional addresses after postcode verification and the map when available.
* New: Added WooCommerce admin Tracking Analytics dashboard (WooCommerce → Tracking Analytics) with KPIs for shipped/unshipped orders, total shipments, exceptions, average/median time-to-ship (days), and all-time tracking lookups.
* New: Added carrier and status reporting, including ranked breakdowns with percentage bars plus an improved recent shipments view.
* UI: Redesigned Tracking Analytics into a modern, branded reporting dashboard (hero header, tabs, KPI cards with context text, insights panel, trend sparkline, quick date-range presets, and status pills).
* Improvement: Improved Analytics performance and stability — orders are fetched in batches (avoids limit => -1), analytics calculations are transient-cached, and assets are enqueued using the correct admin hook for reliable loading.
* Improvement: Minor UX/copy improvements for the unshipped state (“not shipped yet” messaging) and consistent rendering parity with shipped view.
* Improvement: Email templates, placement, and styling updates to better match WooCommerce and site branding.
* Change: Updated the Partially Shipped HTML and plain templates to align with WooCommerce’s modern email structure (header/footer hooks, intro block, and additional content handling).
* Fix: Guarded template variables to prevent undefined variable notices (e.g., $additional_content).
* Improvement: Centralised tracking output into a single renderer (Email_Hooks::append_tracking_to_email) to remove duplication and improve maintainability.
* Change: Updated the Partially Shipped template to call the renderer in the correct placement (above the order summary) and rely on hook-aware logic.
* UI: Styling improvements — tracking tables and links now use WooCommerce email colour settings (per-email where available, global fallbacks otherwise) with automatic accessible link contrast when needed.
* Improvement: Improved CTA behaviour and item display.
* Change: Backwards-compatible changes that avoid modifying WooCommerce core templates — honours WooCommerce → Settings → Emails colours and remains compatible across WooCommerce versions.
* UI: Analytics table polish — sticky headers, compact rows, zebra striping, truncation for long cells, and improved hover/readability.

= 1.0.0 (07 December 2025) =
* New: Initial release.
* New: Add multiple tracking numbers to WooCommerce orders.
* New: Customer tracking page via [kitgenix_tracking_form] shortcode.
* New: Carrier logos and automatic tracking URLs.
* New: Optional OpenStreetMap delivery route display.
* New: Custom “Kitgenix Partially Shipped” WooCommerce email.
* New: Tracking details included in Completed Order email.

