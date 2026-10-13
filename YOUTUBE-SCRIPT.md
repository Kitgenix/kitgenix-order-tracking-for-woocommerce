# YouTube Script: Kitgenix Order Tracking for WooCommerce

**Suggested video title:** Stop "Where Is My Order?" Emails: Multi-Shipment Tracking for WooCommerce (Free Plugin Walkthrough)

**Alternative titles:**
- WooCommerce Order Tracking Done Properly: Multiple Parcels, Tracking Emails and a Customer Tracking Page
- How to Add Tracking Numbers to WooCommerce Orders (Split Shipments, 27 Carriers, CSV Import)

**Estimated runtime:** about 11 to 12 minutes (roughly 1,750 spoken words at around 155 words per minute, plus short pauses for screen demos)

**Plugin version shown:** 2.1.0 (requires WordPress 6.0+, PHP 8.1+, WooCommerce 10.0+)

**Recording notes:**
- Use a staging store with a few realistic orders. You'll need at least one order with three or more line items and a postcode, plus one test order with no postcode on file for the privacy segment.
- Set the store address in WooCommerce > Settings > General so the route map has a starting point.
- Create a "Track Your Order" page containing `[kitgenix_tracking_form]` before recording.
- Plain paragraphs are spoken. Everything in `[ON SCREEN: ...]` is direction for the person recording and editing.

---

## 1. Hook

[ON SCREEN: A support inbox filling up: "Where is my order?", "Has my order shipped?", "Any update on #1042?", "Tracking number please".]

If you run a WooCommerce store, you know this inbox. "Where's my order?" "Has it shipped?" "Can I have the tracking number?" Every one of those is a slightly worried customer, and a team member who has to stop, open the order, find the courier and copy and paste a number.

[ON SCREEN: A bare WooCommerce order with a tracking number buried in a private order note.]

And when customers can't see tracking for themselves, they start wondering whether they can trust your store. Today we're going to fix that, properly, inside WooCommerce.

## 2. Intro

[ON SCREEN: Kitgenix logo, then the plugin's WordPress.org listing.]

This is Kitgenix Order Tracking for WooCommerce. It lets you record one or more shipments against every order. It adds tracking to your customer emails, and it gives customers a self-service tracking page so they can find answers without emailing you.

It's built for stores that already ship with their own couriers and just need a clean, consistent way to record and share tracking. Our promise is simple: enter a tracking number once, and your customer can find it themselves.

## 3. Why We Built This

[ON SCREEN: A stock WooCommerce order screen, with circles showing there's no tracking field anywhere.]

Out of the box, WooCommerce has no shipment tracking interface at all. There's no tracking field, no carrier list and nothing in the emails. So tracking numbers end up in order notes.

[ON SCREEN: Text overlay: "One courier only?" "Monthly fee?" "One parcel per order?"]

The alternatives often fall short too. Some only support one courier. Some charge a monthly fee just to put a number in an email. And many assume every order goes out in one box.

[ON SCREEN: Text overlay: "No label buying. No collection booking. No live courier scans."]

We want to be upfront about one thing. This plugin doesn't connect to courier APIs. It doesn't buy labels, book collections or pull in live delivery scans. Statuses like "In transit" or "Delivered" are set by your team. What it does is keep a reliable record of every parcel, link customers to each courier's own tracking page, and keep everyone informed.

## 4. Installing & Activating

[ON SCREEN: Plugins > Add New Plugin, search for "Kitgenix Order Tracking", then Install and Activate.]

Go to Plugins, then Add New, search for "Kitgenix Order Tracking for WooCommerce", then install and activate it.

[ON SCREEN: Callout: "Requires WooCommerce 10.0+, WordPress 6.0+, PHP 8.1+. HPOS compatible."]

WooCommerce needs to be active first. If it isn't, the plugin won't activate and tells you why. It also supports WooCommerce's High-Performance Order Storage, so it works on both the classic and the newer order screens.

[ON SCREEN: Redirect to Kitgenix > Order Tracking. Then Pages > Add New, titled "Track Your Order", with a Shortcode block containing `[kitgenix_tracking_form]`. Click Publish.]

After activation, you'll land on the Order Tracking dashboard under the new Kitgenix menu. Before anything else, create a page called "Track Your Order", add the shortcode `kitgenix_tracking_form` in square brackets, and publish it. That's your customer tracking page.

## 5. Adding Tracking to an Order

[ON SCREEN: Open an order with three line items. Zoom in on the "Kitgenix Order Tracking" box in the sidebar and click "Add tracking".]

Open any order, and in the sidebar you'll find the Kitgenix Order Tracking box. Click "Add tracking".

[ON SCREEN: Pan over the modal fields.]

Each shipment gets a tracking number, a carrier, a shipped date, an optional estimated delivery date, a status, and an internal note. The note is for staff only and never appears in customer emails.

[ON SCREEN: Paste `AB123456789GB`. Highlight "Detected: Royal Mail (unconfirmed)".]

Paste in a tracking number. If it uses a distinctive format, like UPS numbers starting with "1Z", Amazon Logistics numbers starting with "TBA", Royal Mail and Parcelforce numbers ending in "GB", or USPS formats, the plugin suggests a carrier. That suggestion is marked as unconfirmed, and it never overrides a carrier you've chosen. If a number is just digits that could belong to several couriers, it doesn't guess at all.

[ON SCREEN: Open and scroll the carrier dropdown.]

Otherwise, pick from the dropdown. It includes 27 built-in carriers across the UK and Ireland, Europe, North America, Australia and New Zealand, plus "Other".

[ON SCREEN: The item checklist. Tick two of three items and adjust a quantity.]

Here's the part we really like. Below that is a checklist of the items on the order, so you choose exactly which items, and how many, are in this parcel. If only the mugs are shipping today, tick the mugs and leave the rest for later.

[ON SCREEN: Callout: "You can't allocate more than was ordered."]

You also can't over-allocate. The checklist only offers what's still left to ship. If anything tries to save more than was ordered, the quantity is automatically capped and the change is recorded in the Event Log. So your tracking can never claim you shipped three mugs when the customer bought two.

[ON SCREEN: The "Update order status" options: No change, Partially shipped, Completed.]

Finally, choose whether to update the order status: leave it as it is, mark it "Partially shipped", which is a new status the plugin adds, or mark it "Completed".

[ON SCREEN: Click Confirm, then add a second shipment, then click "Save tracking".]

Confirm, and the shipment appears in the sidebar with Edit and Remove links. You can add as many shipments as you need for split parcels, backorders or replacements. Then click "Save tracking", which saves the order. Nothing is stored until you do.

## 6. What the Customer Sees

[ON SCREEN: The front-end "Track Your Order" page.]

On your tracking page, customers can look up their order with their order number and billing email, or with just a tracking number.

[ON SCREEN: Highlight the optional postcode field.]

There's also an optional postcode field, and the label changes by country, for example "ZIP code" in the US. It's there because order numbers can be guessed and tracking numbers can be shared, so sensitive details stay locked until the postcode is confirmed.

[ON SCREEN: Look up an order without a postcode. Show the limited result: tracking number, carrier and tracking link.]

Without the postcode, customers see the essentials: tracking number, carrier and a link to the courier's tracking page. That answers "where's my order?" without exposing anything private.

[ON SCREEN: Enter the correct postcode. Show the addresses, parcel-by-parcel items and not-yet-shipped items.]

When they enter a postcode that matches the billing or shipping address, they also see the addresses, which items are in each parcel, and what hasn't shipped yet. The matching is country-aware, so spaces, dashes and US ZIP+4 codes are all handled.

[ON SCREEN: Callout titled "Privacy nuance". Look up the no-postcode test order by tracking number only and show the full details.]

There's one nuance we want to be honest about. If an order has no postcode stored, there's nothing to check against, so the full details are shown, even when someone looks it up with just a tracking number. If you sell where postcodes aren't always collected, anyone with the tracking number for those orders can see the addresses and items. Making the postcode a required checkout field closes that gap.

[ON SCREEN: Scroll down to the status timeline and the "Route from store to delivery address" map.]

There's also a simple status timeline, showing only what your team has recorded, with no invented courier scans. And once details are unlocked, there's a route map. This map is illustrative. It draws a straight line from your store to the delivery address, not the van's live location or the actual route. It uses OpenStreetMap, so you don't need a Google Maps API key.

[ON SCREEN: Look up an order with no shipments yet and show the "not yet dispatched" message.]

If an order hasn't shipped yet, customers see a friendly "not yet dispatched" message instead of an error. One more thing: the tracking page and your emails are where customers see tracking. The plugin doesn't add a My Account tab, so link to your tracking page clearly.

## 7. Email Notifications

[ON SCREEN: The Completed Order email with the Shipment Tracking table.]

When an order is marked Completed, WooCommerce's Completed Order email includes a Shipment Tracking table. It shows each parcel's carrier, tracking number, items, estimated delivery date and a Track link, using your WooCommerce email colours.

[ON SCREEN: The "Your order has been partially shipped" email, with the "Not yet shipped" section highlighted.]

When an order moves to "Partially shipped", either from the tracking window or the normal status dropdown, the customer automatically gets a "partially shipped" email. It includes tracking for everything sent so far and a list of items still to come.

[ON SCREEN: WooCommerce > Settings > Emails, highlighting "Kitgenix Partially Shipped".]

You'll find it in WooCommerce > Settings > Emails as "Kitgenix Partially Shipped", and your theme can override its template in the usual WooCommerce way.

## 8. The Analytics Dashboard

[ON SCREEN: Kitgenix > Order Tracking > Overview. Show the date-range presets, KPI tiles, sparkline and Insights card.]

Back in the admin, the Overview tab shows the key numbers for any period from 7 days to a year. You'll see shipment coverage, which is the share of orders with tracking, plus average and median time to ship, total shipments, your top carrier, exception rate, orders without a shipment, and the all-time number of successful lookups on your tracking page. That last one is worth watching, because every lookup is a customer helping themselves instead of contacting you.

[ON SCREEN: The Breakdown tab, then the Recent tab.]

The Breakdown tab ranks your shipments by carrier and by status. The Recent tab lists your 20 latest shipments, with links to each order.

[ON SCREEN: The Carriers tab. Show the recommended-pack notice, apply "UK & Ireland", then show the on/off toggles and priority fields.]

On the Carriers tab, carrier packs turn on the right couriers for your region in one click, and the plugin suggests a pack based on your store's country. You can also turn individual carriers on or off and set their priority, so your most-used couriers appear first in the dropdown.

[ON SCREEN: Add a custom carrier with a URL containing `{tracking_number}` and an icon from the Media Library.]

If your courier isn't built in, add a custom carrier. Give it a name, a tracking URL containing `{tracking_number}`, and an optional icon. After that, it works just like a built-in carrier.

[ON SCREEN: Bulk Tools: export a CSV, then drag a file onto the import area and show Append and Replace.]

Bulk Tools lets you export every shipment to a CSV file and import a CSV back. Each row is matched by order ID or order number. "Append" adds new shipments and skips tracking numbers already on the order. "Replace" overwrites all shipments on matched orders. Item allocations are kept too. Test your first import on a staging site.

[ON SCREEN: The Log tab with search, the 7/14/30-day retention setting and the reference table.]

The Log tab records carrier setting changes, import problems with the exact reason, and quantity adjustments. You can search it, clear it, and set entries to delete automatically after 7, 14 or 30 days.

## 9. Real-World Example

[ON SCREEN: Title card: "One order, two boxes, two couriers".]

Let's put it all together. A customer orders two mugs and a framed print. The mugs ship today, and the print follows later.

[ON SCREEN: Add the Royal Mail number, accept the suggestion, tick 2 x mugs, choose "Partially shipped", then save.]

On day one, we add the Royal Mail number and confirm the suggested carrier. We tick the two mugs, choose "Partially shipped", and save. The customer automatically gets the partially shipped email with Royal Mail tracking, and the print is listed as not yet shipped.

[ON SCREEN: Add a second shipment, choose DPD UK manually, tick the print, choose "Completed", then save. Cut to the Completed email showing both parcels.]

On day three, the print goes out with DPD. The number is all digits, so we choose DPD UK ourselves. We tick the print, choose "Completed", and save. The Completed Order email shows both parcels and both tracking links in one table.

[ON SCREEN: The customer enters their order number, email and postcode on the tracking page. Show both shipments, the item breakdown and the map.]

When the customer wants an update, they visit the tracking page, enter their details, and see both parcels with what's inside each one. Our lookup counter goes up by one, and that's one support ticket we never had to answer.

## 10. Tips & Best Practices

[ON SCREEN: Tip cards appearing one by one.]

Some quick tips. First, link your tracking page in your menu and footer, because customers need to be able to find it. Second, apply your regional carrier pack and turn off couriers you don't use. Third, use "Partially shipped" whenever an order goes out in stages. Fourth, keep statuses up to date, because they come from your team, not the courier, and your analytics depend on them. Fifth, require a postcode at checkout if you can. And sixth, check the Log tab after bulk imports.

[ON SCREEN: Callout: "Uninstalling keeps your tracking data."]

And if you ever uninstall the plugin, your shipment data stays on your orders. Only the plugin's settings and logs are removed.

## 11. Recap & Call to Action

[ON SCREEN: Montage of the Add tracking window, item checklist, partially shipped email, tracking page and Overview tab.]

So that's Kitgenix Order Tracking for WooCommerce. You get multiple shipments per order with item allocation that can't exceed what was ordered, 27 built-in carriers plus custom ones, tracking in your Completed and Partially Shipped emails, a privacy-conscious tracking page, and a full dashboard with bulk CSV tools and an activity log. It all runs inside WooCommerce, with no monthly fee and no external account.

[ON SCREEN: The WordPress.org URL, plus Subscribe and Like buttons, then the end screen.]

Install it free from WordPress.org by searching for "Kitgenix Order Tracking". If it saves you time, a review really helps. Leave your questions in the comments, subscribe for more WooCommerce walkthroughs, and here's to a quieter support inbox.
