# Changelog

## 2.2.0 – 30 October 2026

- **Fix:** The order number and email lookup now works for stores using a sequential or custom order-number plugin (for example WebToffee/Skyverge Sequential Order Numbers, Algoritmika Custom Order Numbers, or Kitgenix MultiStore Sync's own network order numbers). The lookup previously called `wc_get_order()` directly on the submitted number, which only accepts the order's internal ID, so a customer entering the order number actually shown to them could never find their order. The submitted number is now resolved the same way WooCommerce's own order-tracking shortcode does, through the `woocommerce_shortcode_order_tracking_order_id` filter, with a fallback to a short list of known order-number meta keys (filterable via `kitgenix_order_tracking_for_woocommerce_order_number_meta_keys`) before falling back to the raw internal ID.
- **New:** Branded My Account Tracking Panel. Display shipments and status details directly within WooCommerce My Account order views. Each shipment shows its carrier, a status badge, the tracking number, a shipment timeline, the items it contains and a "Track with carrier" button. The panel can be turned off with the `kitgenix_order_tracking_for_woocommerce_show_my_account_panel` filter, and its accent colour changed with the `kitgenix_order_tracking_for_woocommerce_my_account_accent_color` filter.
- **Improved:** The My Account shipment timeline no longer shows a permanently greyed-out "In transit" step for shipments staff never manually advance. Any shipment that hasn't reached a final status (delivered, exception, returned or cancelled) now ends in a single open "On its way"/"In transit" step with a subtle pulsing indicator, so it reads as active progress instead of a stalled tracker. The "Track with carrier" button is also more prominent, since the carrier's own site remains the source of live scan updates.
- **Security:** Fixed an issue where, for an order with no postcode stored, a lookup using only a tracking number could show the order's full billing and shipping addresses. These details are now only shown after an order number and billing email lookup, or once the postcode is confirmed.
- **Security:** Added rate limiting to the tracking form. Failed lookups are limited per IP address (20 every 15 minutes), and an individual order is temporarily locked after 5 incorrect email or postcode attempts, so the postcode check cannot be brute-forced. Both limits can be changed with the `kitgenix_order_tracking_for_woocommerce_max_failed_lookups_per_ip` and `kitgenix_order_tracking_for_woocommerce_max_failed_lookups_per_order` filters.
- **Security:** The tracking form now shows the same message when an order does not exist and when the email does not match, so it can no longer be used to discover which order numbers exist.
- **Security:** Shipment CSV exports now neutralise spreadsheet formula injection by prefixing values that start with `=`, `+`, `-`, `@`, a tab or a carriage return with an apostrophe. Shipment CSV imports remove it again automatically, so exported files import back unchanged.
- **Improved:** Raised the minimum PHP version to 8.2 (`Requires PHP: 8.2`).
- **Improved:** Tested up to WordPress 7.4 and WooCommerce 11.2.1, and reviewed for compatibility with PHP 8.2 to 8.5. The full codebase passes syntax checks on PHP 8.5 and a PHPCompatibility scan for PHP 8.2–8.5, and uses no dynamic properties or functions deprecated in PHP 8.2–8.5.
- **Improved:** Declared compatibility with the WooCommerce Cart and Checkout blocks (`cart_checkout_blocks`), so WooCommerce no longer lists the plugin as possibly incompatible in the Checkout block editor.
- **Fix:** The CSV `$escape` argument is now passed explicitly in the shipment CSV export and bulk tracking CSV import, preventing "the $escape parameter must be provided" deprecation notices on PHP 8.4 and later. The CSV format is unchanged.
- **Dev:** Removed the PluginScore verification code from the readme, as Kitgenix Plugin Score has been discontinued.
- **Dev:** Bumped the version to 2.2.0 across the plugin.
- **Documentation:** Regenerated the `.pot` translation template for 2.2.0.
- **Documentation:** The readme changelog now lists only the current release. The full release history is in `CHANGELOG.md`.

## 2.1.2 – 13 October 2026

- **Fix:** Bump version to 2.1.2 across the plugin.

## 2.1.1 – 13 October 2026

- **Fix:** Updated PluginScore Verification code.

## 2.1.0 – 13 October 2026

- **New:** Added a configurable log auto-delete setting (7/14/30 days) to automatically remove old event log entries.
- **New:** Added a "Request a Feature" button to the Need Help? sidebar, linking directly to this plugin's page on the Kitgenix roadmap (https://roadmap.kitgenix.com).
- **New:** Added a "Request a Feature" banner below the plugin grid on the Kitgenix Hub page, linking to the shared Kitgenix roadmap.

## 2.0.0 – 22 August 2026

- **New:** Redesigned the entire admin experience around the shared Kitgenix design system – a sticky topbar with an Analytics dropdown menu, live settings search, dark/light theme toggle, and mobile hamburger menu replaces the previous page header and tab strip.
- **New:** Added a redesigned central Kitgenix Hub page for discovering, installing, activating, and reviewing every Kitgenix plugin from one screen, including the new Image Optimizer plugin.
- **Improved:** Rebuilt the Analytics, Carriers, Bulk Tools, Support, and Log tabs around a consistent set of cards, toggle switches, colour-coded status badges, empty states, and a drag-and-drop CSV upload zone, replacing the previous form tables and plain links.
- **Improved:** The Tracking Analytics stat-tile grid – including orders in range, time-to-ship, lookups, and other metrics – now uses a fixed four-column layout on wider screens, with responsive fallbacks to two columns and then one column on smaller displays.
- **Improved:** The "Add tracking" modal on the WooCommerce order edit screen and the custom carrier edit modal now share a common accessible modal component with proper focus handling, Escape-to-close support, and accessible labelling.
- **Improved:** Custom carrier rows now use compact icon-only edit and delete action buttons with colour-coded enabled/disabled badges instead of plain text links.
- **Improved:** The Log tab now displays entries in a searchable, paginated table containing time, context, outcome, and a plain-English note instead of a plain text block.
- **Improved:** CSV bulk import failures now record the specific underlying reason – including unreadable files, files that cannot be opened, missing or invalid headers, and unexpected processing errors – in the Log tab alongside the existing on-screen message.
- **Improved:** Added a reference table to the Log tab explaining what each recorded category means and whether administrator action is required.
- **Improved:** The Support tab is now three focused cards – a donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a "get involved" panel for reviews and plugin links – replacing the previous stack of donate, trust, and community cards.
- **Improved:** Moved the Log tab directly before Support in the admin navigation for a more logical workflow.
- **Improved:** Refreshed the Kitgenix logo and favicon assets used throughout the admin menu icon, Kitgenix Hub header, and Analytics header.
- **Fix:** The "Follow Us" social links no longer appear twice on the Support tab, removing the previous duplication between the inline social block and sidebar.
- **Fix:** The "Shipments over time" sparkline no longer displays a bold solid line when there is no shipment data. Empty charts now render a thinner, muted baseline so the absence of data cannot be mistaken for a genuine flat trend.
- **Fix:** Number inputs across the Analytics and settings screens are no longer cramped to a fixed 50px width.
- **Dev:** Added a shared `kitgenix-admin-components.js` controller providing modal open/close handling, toast notifications, collapsible cards, copy-to-clipboard controls, and live table search. Both `admin-analytics.js` and `admin-order-tracking.js` now use the shared component controller.
- **Dev:** Three previously silent internal errors – Analytics date parsing, tracking-count lookups, and HPOS order-screen detection – are now recorded in the Log tab for easier diagnostics while remaining non-blocking and preserving existing behaviour.

## 1.1.3 – 26 May 2026

- **New:** Added a Log tab to the admin settings page. Records recent plugin activity (carrier settings saves and key operations) with timestamps, context labels, and plain-English notes to aid troubleshooting.
- **Fix:** Activity log data is now fully cleaned up when the plugin is uninstalled.
- **Compatibility:** Confirmed compatibility with WordPress 7.0 and WooCommerce 10.x.

## 1.1.2 – 26 May 2026

- **Dev:** Skipped to be in line with other Kitgenix Plugins.

## 1.1.1 – 26 May 2026

- **Dev:** Skipped to be in line with other Kitgenix Plugins.

## 1.1.0 – 7 May 2026

- **New:** Added international carrier packs for UK & Ireland, Europe, North America, Australia & New Zealand, and global mixed-carrier stores in the Carriers admin tab.
- **New:** Expanded the built-in carrier library with additional regional carriers including Australia Post, Canada Post, USPS, PostNL, GLS, Chronopost, Colissimo / La Poste, Correos, Deutsche Post / DHL Germany, PostNord, Bpost, NZ Post, and UPS US.
- **New:** Added a Bulk Tools tab to the Order Tracking admin screen with shipment CSV export and import workflows for spreadsheet-based fulfilment teams.
- **Improvement:** Customer postcode verification now uses country-aware postcode / ZIP / postal-code matching for international orders instead of relying on one generic postcode normalizer.
- **Improvement:** Bulk imports now support append and replace modes, so stores can either add new tracking rows or overwrite shipments on the matched orders from a CSV file.
- **Improvement:** Shipment CSV exports and imports round-trip item allocations using the `item_id:qty|item_id:qty` format and reuse the same shipment storage used by the order edit screen, emails, analytics, and the public tracking page.

## 1.0.6 – 19 March 2026

- **Improvement:** Custom carriers are now managed with an Add form plus Edit/Delete actions, with Edit opening a popup modal.
- **UI:** Improved the Kitgenix admin header layout for better alignment and less clutter.
- **UI:** Social links in admin headers now render as compact icon buttons with accessible labels.
- **UI:** Added responsive header helpers so titles/descriptions and actions/links lay out consistently.
- **UI:** Tracking Analytics “Showing last …” summary and Refresh action now appear within the filters bar.
- **UI:** Admin tables inside Kitgenix pages now use Kitgenix styling for a more consistent branded look.
- **UI:** Carriers management now shows built-in carriers and custom carriers as separate cards on the Carriers tab.
- **UI:** Added an icon Upload button for custom carriers using the WordPress Media Library.
- **Fix:** “Shipper settings saved” notices now display in the standard WordPress notice area instead of inside the Kitgenix header.
- **Fix:** Added defensive notice normalization to prevent notices being relocated into the header by other scripts.
- **Fix:** Removed a PHP warning in the Tracking Analytics Support tab caused by an undefined variable.
- **Fix:** Added spacing between adjacent action links/buttons such as Edit/Delete.
- **Fix:** The built-in “Other” carrier is now always listed last.
- **Cleanup:** Normalised admin nonce handling in analytics and settings flows for WordPress.org review compliance.
- **Maintenance:** Updated the plugin Author URI to the public Kitgenix WordPress.org profile and replaced the old custom admin-menu icon CSS with the native Dashicons icon.

## 1.0.5 – 18 February 2026

- **New:** Added a Shippers tab under Kitgenix → Order Tracking → Shippers for carrier management.
- **New:** Added enable/disable controls for built-in carriers while always retaining the “Other” fallback.
- **New:** Added carrier priority ordering so administrators can control which carriers appear first in the admin carrier dropdown.
- **New:** Added custom carriers with per-carrier enabled toggle, name, tracking URL pattern supporting `{tracking_number}`, optional icon URL, and optional priority.
- **Change:** Carrier dropdown now shows enabled carriers only, ordered by priority.
- **Change:** Renamed the Kitgenix submenu to “Order Tracking”.
- **UI:** Added Stock Sync for WooCommerce to the Kitgenix Hub cards.
- **Fix:** Corrected Kitgenix Hub card markup for consistent layout.
- **Cleanup:** Uninstall now removes the carrier settings option.
- **Dev:** Introduced the `AdminModule` entrypoint while keeping settings storage unchanged.
- **Dev:** Regenerated `/languages/kitgenix-order-tracking-for-woocommerce.pot` translation template.
- **Docs:** Updated documentation, `readme.txt`, and WordPress.org screenshots.

## 1.0.4 – 27 January 2026

- **Improvement:** Minor bug fixes, i18n and PHPCS improvements, plus analytics and email template refinements.
- **Fix:** Hardened analytics/admin asset enqueue detection to prefer `$_GET['page']` and then fall back to hook suffix so analytics and Hub assets load reliably on existing installs.
- **Change:** Declared PHP requirement as 8.1.
- **Change:** Standardised allowed HTML and escaping in the admin Hub and analytics views for consistent safe output.
- **Cleanup:** Applied PHPCS, i18n, and security fixes across admin and core files, including output escaping, translator comments, and optional nonce checks.

## 1.0.3 – 5 January 2026

- **Improvement:** Updated the email hook to align with WooCommerce’s modern email template structure.
- **Cleanup:** Implemented minor code refinements and cleanup for better consistency and maintainability.

## 1.0.2 – 1 January 2026

- **New:** Added a new top-level Kitgenix admin Hub menu for managing Kitgenix plugins from one place, including install, activate, and open actions.
- **New:** Tracking Analytics is now available under Kitgenix → Tracking Analytics, with a refreshed branded header and quick links to documentation, support, and reviews.
- **New:** Added a Support tab and a new privacy-safe “tracking numbers added” metric; rank-list percentage bars now render reliably through a small admin script.
- **Improvement:** Refactored the admin order screen with improved “Add tracking” modal markup, CSS class naming, and modal show/hide behaviour.
- **Improvement:** Updated admin styling with shared Kitgenix design tokens, improved dark-mode support, and bundled Hub styles and logos.
- **Change:** Renamed several developer-facing internal actions, filters, and options to match the full plugin slug, including the AJAX action, Nominatim filters, and lookup counter option. Custom code targeting the old names may need updating.

## 1.0.1 – 18 December 2025

- **New:** Shortcode tracking page now shows a full order summary and item statuses when an order has not yet shipped, including optional addresses after postcode verification and the map when available.
- **New:** Added a WooCommerce admin Tracking Analytics dashboard under WooCommerce → Tracking Analytics with KPIs for shipped/unshipped orders, total shipments, exceptions, average/median time-to-ship, and all-time tracking lookups.
- **New:** Added carrier and status reporting, including ranked breakdowns with percentage bars and an improved recent shipments view.
- **Improvement:** Improved Analytics performance and stability by fetching orders in batches instead of using `limit => -1`, transient-caching analytics calculations, and enqueueing assets through the correct admin hook.
- **Improvement:** Improved UX and copy for the unshipped state with clearer “not shipped yet” messaging and more consistent parity with the shipped view.
- **Improvement:** Updated email templates, placement, and styling to better match WooCommerce and site branding.
- **Improvement:** Centralised tracking output into a single `Email_Hooks::append_tracking_to_email` renderer to remove duplication and improve maintainability.
- **Improvement:** Improved CTA behaviour and item display.
- **UI:** Redesigned Tracking Analytics into a modern branded reporting dashboard with a hero header, tabs, KPI cards with context text, insights panel, trend sparkline, quick date-range presets, and status pills.
- **UI:** Updated tracking tables and links to use WooCommerce email colour settings, with per-email colours where available, global fallbacks otherwise, and automatic accessible link contrast.
- **UI:** Polished Analytics tables with sticky headers, compact rows, zebra striping, truncation for long cells, and improved hover/readability.
- **Fix:** Guarded template variables to prevent undefined variable notices such as `$additional_content`.
- **Change:** Updated the Partially Shipped HTML and plain-text templates to align with WooCommerce’s modern email structure, including header/footer hooks, intro block, and additional-content handling.
- **Change:** Updated the Partially Shipped template to call the shared renderer in the correct placement above the order summary and rely on hook-aware logic.
- **Change:** Kept changes backwards-compatible without modifying WooCommerce core templates, while honouring WooCommerce → Settings → Emails colour settings across supported WooCommerce versions.

## 1.0.0 – 7 December 2025

- **New:** Initial release.
- **New:** Added support for multiple tracking numbers per WooCommerce order.
- **New:** Added a customer tracking page via the `[kitgenix_tracking_form]` shortcode.
- **New:** Added carrier logos and automatic carrier tracking URLs.
- **New:** Added an optional OpenStreetMap delivery route display.
- **New:** Added a custom “Kitgenix Partially Shipped” WooCommerce email.
- **New:** Added tracking details to the WooCommerce Completed Order email.