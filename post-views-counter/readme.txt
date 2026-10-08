=== Post Views Counter ===
Contributors: dfactory
Tags: counter, postviews, statistics, analytics, pageviews
Requires at least: 6.4
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.8.0
License: MIT
License URI: http://opensource.org/licenses/MIT

Count and display post views, track site-wide visits, and see what content works – inside WordPress. Fast, easy to use and privacy-first.

== Description ==

[Post Views Counter](https://postviewscounter.com/) counts how many times your posts, pages and other content are viewed, shows those counts on your site, and helps you see which content works – right inside WordPress. No external tools. No bloat. Just the numbers you need to see what’s working.

It counts two things:

- **Views** – Counted views of your selected posts, pages and other content, according to your counting settings. Views can be displayed on your site and used to sort content.
- **Visits** – A Visit starts with a browser’s first counted view and covers subsequent counted content views within the fixed Count Interval. Visits are shown as site-wide totals beside Views, not per post.

= Key Benefits =

Clarity, speed, and control:

- **Clear, Focused Metrics** – Views for every post and Visits for the whole site give you a clear picture of how your content is performing.
- **Made for WordPress** – Runs entirely in your site. No GA, no third-party pipes; accurate counts in your Dashboard and post lists.
- **Your counting rules** – Choose which content to count, exclude selected visitors, and set the interval for repeat counts.
- **Privacy-first** – Views and Visits are stored as aggregate totals on your server. Browser storage helps prevent repeat counting within your Count Interval. Pro offers cookieless browser storage; its optional Strict Counts feature also caches IP-derived data to limit repeat counts.
- **Works at scale** – Minimal overhead, no external scripts, Multisite-ready.
- **Display anywhere** – Automatically show counts, or place them exactly where you want via blocks, shortcode or PHP.

= Features =

Practical features that matter:

- Count & display views for **any post type** you select.
- Count **Visits** and see them beside Views in the dashboard chart.
- Three counting modes: **PHP, JavaScript, REST API**
- **Post Views dashboard widget**: a chart of Views, Visits or both, with a Views comparison for the selected month. A month in progress is compared with the same days of the previous month.
- **Top Posts dashboard widget**, month by month.
- Sortable Post Views **admin column**, with a Views chart for each post.
- **Traffic Signals** admin column that flags unusual traffic changes compared with the same days of the previous month.
- **Weekly email summary** of how your content performed.
- Exclude bots, logged-in users, specific roles, or IPs
- Manually adjust a post’s views when needed.
- Query and **order content by views** (developer-friendly)
- Custom REST API endpoints
- **Count Interval** to set the fixed window for repeat counts from the same browser.
- One-click data import from **WP-PostViews**, **Statify** and **Page Views Count**
- Show the counter automatically, or place it with the **Post Views block**, shortcode or PHP function.
- **Most Viewed Posts** block and widget.
- **Multisite** compatible
- **WPML/Polylang** compatible; translation-ready (.pot)

= Post Views Counter Pro =

More capability without extra complexity:

- **Fast AJAX counting** that keeps counting light on busy sites.
- **Caching compatibility** and dynamic loading to count through cached pages and refresh displayed counts when a compatible counting mode is selected.
- **Reports**: Views by Date, Post and Author to spot winners, trends and top contributors, plus **Visits in aggregate date reports**.
- **Visit trends and Views per visit** in dashboard insights and email summaries.
- **Performance Insights** that explain how a post’s Views changed, in the admin column, Traffic Signals and email summaries.
- **Daily, weekly and monthly email summaries** with insights.
- **Traffic sources**: aggregate stats on referrers, devices, browsers, operating systems and languages.
- Views for **taxonomy terms, authors** and other archives.
- Customizable **Views Period** (e.g., last 7/30 days) to control the views count timeframe.
- **Export to CSV/XML** to download and share data.
- **Integrations** for ordering by views in popular builders (e.g., **Elementor Pro, Divi, GenerateBlocks**).

[Learn more about Pro →](https://postviewscounter.com/pricing/)

== Installation ==

1. Install Post Views Counter either via the WordPress.org plugin directory, or by uploading the files to your server
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the Post Views Counter settings and set your options.

== Frequently Asked Questions ==

For many frequently asked questions check the [Post Views Counter Docs](https://postviewscounter.com/documentation/).

= Why use Post Views Counter vs Google Analytics? =

Post Views Counter gives you per-post Views and site-wide Visits inside WordPress. It is fast, easy to use and privacy-first, with counting data stored on your server and control over what, who and when you count. Google Analytics may be more than you need when you want content view statistics for editorial decisions.

= Can I use Post Views Counter alongside Google Analytics? =

Of course – many sites use both. Post Views Counter handles on-site, per-post view counts inside WordPress (no third-party scripts), while Google Analytics covers marketing funnels and acquisition.

= What is the difference between Views and Visits? =

A View belongs to the content being viewed and is counted according to your counting settings. A Visit starts with a browser’s first counted view and covers subsequent counted content views within the fixed Count Interval. Viewing another eligible post within that window adds a View without starting another Visit. Later views do not extend the window.

= Why do Visits differ from sessions in Google Analytics? =

Post Views Counter follows the counting rules you choose: which content to count, which visitors to include or exclude, and how often to count them again. These rules may differ from your Google Analytics setup, so the totals are not expected to match.

The time windows also differ. PVC starts a fixed Visit window with the first counted view, using your Count Interval – 24 hours by default. Later views do not extend it. Google Analytics 4 uses an inactivity timeout, which defaults to 30 minutes.

= Why can’t I see Visits for a single post? =

A Visit belongs to your site, not to a post – one Visit often covers several posts. Post Views Counter shows Views for each post and Visits as site-wide totals. Traffic Signals still notice when the visits that start on a post change unusually.

= Why does the Visits chart show no data? =

Visits start accumulating when Visit counting becomes available; earlier Views do not automatically become Visits. Setting Count Interval to 0 stops new Visits but preserves previously counted totals. If browser storage is unavailable, Views can still be counted but Visits are not inferred. Right after an update, the chart can briefly say that Visit data is not available while the database is prepared.

= Is Post Views Counter GDPR compliant? =

Post Views Counter counts views and visits inside WordPress without third-party tracking scripts. Views and Visits are stored as aggregate totals on your server. To apply the Count Interval, it uses a first-party cookie in the visitor’s browser. Pro also offers cookieless browser storage; its optional Strict Counts feature caches IP-derived data associated with content and timestamps to limit repeat counts.

= How do I get support? =

If you’re using the free version, please post your question in the WordPress.org support forum.

If you’ve purchased Post Views Counter Pro, your license includes one year of updates and premium support. You can contact us directly through our dedicated support channel available after logging into your account at [Post Views Counter](https://postviewscounter.com/), and our team will get back to you.

== Screenshots ==

1. screenshot-1.png
2. screenshot-2.png

== Changelog ==

= 1.8.0 =
* New: Site-wide Visits tracking.
* New: Views, Visits and combined display modes in the Post Views dashboard chart.
* New: Monthly Views comparisons in the Post Views dashboard widget.
* New: Custom Format for frontend Views counters in Display settings, shortcodes and PHP functions.
* New: Traffic Signals for changes in visits starting on a post.
* Fix: Block editor counter editing permissions, duplicate saves and error reporting.
* Fix: Counter input validation and Bulk Edit updates for saved posts.
* Fix: Access checks for admin column Views charts.
* Fix: Exclude non-post counts from post charts and summary emails.
* Fix: Traffic Signals and modal periods follow Count Time, including month-end comparisons.
* Fix: Preserve daily history when Cleanup Interval is set to 0.
* Fix: Prevent false Traffic Signals when daily history is incomplete.
* Fix: Keep keyboard focus inside the Views chart modal after navigating between periods.
* Fix: Restore the Views counter in the block editor on WordPress 6.4.
* Fix: Restore lifetime totals in pvc_get_views() when no period is specified.
* Tweak: Limit form-based counter edits to the submitted post; use pvc_update_post_views() for programmatic updates.
* Tweak: Use stored totals for manual Views edits, independently of pvc_get_post_views filters.
* Tweak: Remove pvcArgsQuickEdit.nonce from post lists; retain the save_bulk_post_views AJAX action for compatibility.
* Tweak: Reduce Traffic Signals reads to one database query per posts list page.
* Tweak: Use native Views column sorting with a stable tie-break.
* Tweak: Unify the frontend counter label to Views.
* Tweak: Replace frontend Dashicons with a CSS counter icon.
* Tweak: Retire the Icon Class setting; custom icons now require the pvc_counter_icon_class or pvc_counter_icon filter.

= 1.7.15 =
* Fix: Improve Statify import reliability with deterministic batching and collation-safe cursors.
* Fix: Isolate provider failures and report transaction outcomes accurately.
* Fix: Preserve import strategy compatibility and correct strategy statistics.
* Fix: Restore WordPress 7.1 block editor compatibility, including iframed placeholder styles and block cleanup.
* Tweak: Improve block editor controls and admin toolbar accessibility.

= 1.7.14 =
* Fix: Counter loading states and target metadata.
* Tweak: Pass raw post views shortcode attributes to filters.
* Tweak: Improve license settings integration and compatibility.

= 1.7.13 =
* Fix: Save post views correctly from WooCommerce product Quick Edit.

= 1.7.12 =
* Fix: Prevent widget fatal errors when pvc_most_viewed_posts loads before the widgets API is ready.
* Fix: Refresh dashboard period navigation data for widgets.
* Fix: Improve email summary recipient validation and persist the separate test recipient setting.
* Tweak: Constrain total post view lookups to the total period.

= 1.7.11 =
* New: Weekly email summaries with scheduling, test send, and template-based rendering.

= 1.7.10 =
* New: Session-based counting migration with legacy compatibility.
* Fix: Admin post views ordering fallback.
* Tweak: Normalize Count Interval to hours-only.

= 1.7.9 =
* New: Lazy-load widgets loading.
* New (Pro): Add Kadence Blocks integration for Posts block ordering by views
* New (Pro): Add CoBlocks integration for Posts and Post Carousel ordering by views

= 1.7.8 =
* Fix: Harden option-backed in_array() checks to prevent PHP 8.x TypeErrors.
* Fix: Add IPv6 support for excluded IP matching in settings and counting.
* Tweak: Update internal build tooling.

= 1.7.7 =
* Fix: Prevent undefined array key warnings when saving Display settings menu position.

= 1.7.6 =
* Fix: Prevent SQL errors in pvc_get_post_views function.

= 1.7.5 =
* Tweak: Optimize traffic signals database query performance.
* Tweak: Admin columns display priority and traffic signals comparison alignment.

= 1.7.4 =
* New: Month-over-Month anomaly detection traffic signals in admin columns.
* Tweak: Add loading state UI to modal charts.
* Fix: Month period rollover calculation in chart navigation.
* Fix: Cookie validation for empty or malformed segments in frontend.

= 1.7.3 =
* Fix: Settings validation for exclude/restrict display checkbox fields.
* Fix: Map nested array format to flat field keys before validation.
* Fix: Restrict display merge logic using correct field names.

= 1.7.2 =
* Fix: jQuery wrapper applied to vanilla JS files in build output.

= 1.7.1 =
* New: Enhanced settings UI with modern theme and improved visual design.
* Tweak: Improved settings field conditional visibility and validation.
* Tweak: Better taxonomy display controls and IP exclusion field descriptions.
* Fix: JS redeclaration errors by wrapping editor bundles in IIFE.
* Fix: Conditional visibility for nested import settings fields.

= 1.7.0 =
* New: Integrations page with user-controlled handling for third-party plugins.
* New (Pro): Beaver Builder integration for ordering posts by views in modules.
* New (Pro): Improved JetEngine integration copy and settings UI.
* Tweak: Refactored settings into modular page classes with backward compatibility.
* Fix: Editor JS scope collisions by wrapping editor bundles in IIFE.

= 1.6.1 =
* Tweak: Switch to Vite build system for improved development workflow.
* Fix (Pro): Fixed empty user agent handling in Fast AJAX mode.
* Tweak: Additional UI improvements for settings pages.

= 1.6.0 =
* New: Dedicated import framework with provider-aware analysis/reporting and strategy selector.
* New: Option to import views from Statify and Page Views Count plugins.
* New (Pro): Additional import strategies (skip existing, keep higher count, fill empty-only).
* New: Plugin Status panel now surfaces detected PVC database tables for easier troubleshooting.
* Tweak: Settings UI reorganized with refreshed copy, clearer visitor exclusion controls, and a polished Other tab experience.
* Tweak: Menu placement option moved to Display settings and mirrored for backward compatibility.

= 1.5.9 =
* New: Admin column modal chart with post views data
* New: Extended admin column modal with yearly and weekly views data (Pro)
* New: Admin column modal chart for terms and users (Pro)

= 1.5.8 =
* Tweak: Updated default value for object cache flushing interval
* Tweak: Treat empty or missing user agent as bot

= 1.5.7 =
* New: Count visits by referrer (Pro)
* Prevent duplicate AJAX calls in REST API mode
* Fix: Major improvements for FastAjax handling (Pro)
* Fix: Major object cache support improvements (Pro)
* Fix: Apply crawler/bot check filter for REST API endpoints
* Tweak: Remove unused storage and mutator methods

= 1.5.6 =
* New: Count visits by device, browser and OS (Pro)
* New: Count visits by browser language (Pro)
* New: Traffic Information dashboard widget (Pro)
* New: HTTP request improvements for caching and security (Pro)
* New: Client size bot detection (Pro)
* Tweak: Fix and simplify post views shortcode for loops
* Tweak: Adjust the post views display in Gutenberg editor
* Tweak: Check db query results and log error

= 1.5.5 =
* New: Count Time option to store the views in GMT or Local time (Pro)
* New: Reports extended with Author Posts and Author Archive (Pro)
* New: Counting Jet Engine Profile Builder user profiles as archive view (Pro)
* Tweak: Improved logic for Admin Display and Admin Edit
* Tweak: Settings UI improvements

= 1.5.4 =
* New: Caching compatibility option (Pro)

= 1.5.3 =
* Tweak: WordPress 6.8 compatibility
* Tweak: Move admin column options to Display settings
* Tweak: Added pvc_current_scheme_color filter hook to adjust chart colors

= 1.5.2 =
* Tweak: Updated crawlers list
* Tweak: Updated Chart.js to 4.4.8
* New: Add orderby post_views support to Elementor Pro posts query (Pro)
* New: Add orderby post_views support to Divi theme blog module (Pro)
* New: Add orderby post_views support to GenerateBlocks query (Pro)
* New: Option to exclude AI bots visits from counting (Pro)

= 1.5.1 =
* Fix: Undefined variable $post_type warning in admin columns

= 1.5.0 =
* Fix: Deprecated DateTime dynamic property
* Tweak:Implement AJAX queue for saving dashboard user options
* Tweak: Update bot detection class
* Tweak: Add widget loaded JS event
* Tweak: Fix typo in widget tooltip
* Tweak: Improve dahboard widgets UI
* New: Dashboard widgets revamp (Pro)
* New: Added weekly and yearly dashboard widgets navigation (Pro)
* New: Added trend (increase/decrease) to dashboard widget charts (Pro)
* New: Taxonomy & Terms selection in Views by Post reports (Pro)

= 1.4.8 =
* New: Introducing Post Views block
* New: Introducing Most Viewed Posts block
* Tweak: Updated Chart.js to 4.4.6

= 1.4.7 =
* New: Dynamic views loading option (Pro)
* Fix: Multi-sorting queries with post_views orderby parameter

= 1.4.6 =
* Fix: Bulk posts selection
* Fix: Additional SQL queries escaping
* Tweak: Call to undefined function is_favicon()
* Tweak: Enqueue main script in header instead of footer
* Tweak: Better JS error handling
* Tweak: Updated Chart.js to 4.4.2

= 1.4.5 =
* Fix: Post views bulk saving security
* Tweak: Removed WP Rocket as bot in crawler detection

= 1.4.4 =
* New: Option to enter meta_key for importing the views
* New: Revamped Reports for Views by Date, Views by Post and Views by Author (Pro)
* New: REST API support for post, site, term and user views (Pro)
* New: Views Period option to display views from a selected time period instead of total (Pro)
* New: [site-views] shortcode for total site views display (Pro)
* Tweak: Improved icon handling
* Tweak: Updated crawler detection

= 1.4.3 =
* Tweak: Update languages file

= 1.4.2 =
* New: Option to select position of the plugin menu

= 1.4.1 =
* Fix: Frontpage views not recorded properly

= 1.4 =
* New: Introducing Post Views Counter Pro
* New: Fast Ajax views counting mode (Pro)
* New: Google AMP support (Pro)
* New: Taxonomy term views (Pro)
* New: Author archive views (Pro)
* New: Cookies/Cookieless data storage option (Pro)
* New: Dedicated Reports page (Pro)
* New: Exporting views to CSV or XML files (Pro)
* Tweak: Improved validation and sanitization
* Tweak: Chart.js updated to 4.3.0

= 1.3.13 =
* New: Compatibility with WP 6.2 and PHP 8.2
* Fix: Invalid year in seconds
* Fix: Possible invalid cookie data in views storage
* Fix: Default database prefix
* Tweak: Switch from wp_localize_script to wp_add_inline_script
* Tweak: Updated bot detection


= 1.3.12 =
* Fix: Frontend Javascript rewritten from jQuery to Vanilla JS
* Fix: Admin Bar Style loading on every page
* Fix: Network initialization process for new sites
* Fix: IP address encryption
* Fix: REST API endpoints
* Fix: Removed couple of deprecated functions
* Tweak: Updated chart.js script to version 3.9.1
* Tweak: Added SameSite attribute to cookie

= 1.3.11 =
* Fix: Potentailly incorrect counting of post views in edge case db queries
* Fix: Possible empty chart in dashboard
* Fix: Incorrect saving of dashboard widget user options
* Tweak: Updated Chart.js to version 3.7.0

= 1.3.10 =
* Fix: Post views column not working properly
* Tweak: Switched to openssl_encrypt method for IP encryption
* Tweak: Improved user input escaping

= 1.3.9 =
* Tweak: Remove unnecessary plugin files

= 1.3.8 =
* Tweak: Improved user input escaping

= 1.3.7 =
* Tweak: Implemented internal settings API

= 1.3.6 =
* Fix: Option to hide admin bar chart

= 1.3.5 =
* New: Option to hide admin bar chart
* Fix: Small security bug with views label
* Tweak: Remove unnecessary CSS on every page

= 1.3.4 =
* New: Post Views stats preview in the admin bar
* New: Top Posts data available in the dashboard widget
* Tweak: Improved privacy using IP encrypting
* Tweak: PHP 8.x compatibility

= 1.3.3 =
* Fix: PHP Notice: Trying to get property 'colors' of non-object
* Fix: PHP Notice: register_rest_route was called incorrectly

= 1.3.2 =
* New: Introducing dashboard widget navigation
* New: Counter support for Media (attachments)
* Tweak: Extended views query for handling complex date/time requests

= 1.3.1 =
* Fix: Gutenberg CSS file missing
* Tweak: POT translation file update

= 1.3 =
* New: Gutenberg compatibility
* New: Additional options in widgets: post author and display style
* Fix: Undefined variables when IP saving enabled
* Fix: Check cookie not being triggered in Fast Ajax mode
* Fix: Invalid arguments in implode function causing warning
* Fix: Thumbnail size option did not show up after thumbnail checkbox was checked
* Fix: Saving post (in quick edit mode too) did not update post views

= 1.2.14 =
* Fix: Bulk edit post views count reset issue

= 1.2.13 =
* New: Experimental Fast AJAX counter method (10+ times faster)

= 1.2.12 =
* New: GDPR compatibility with Cookie Notice plugin

= 1.2.11 =
* Tweak: Additional IP expiration checks added as an option

= 1.2.10 =
* New: Additional transient based IP expiration checks
* Tweak: Chart.js script update to 2.7.1

= 1.2.9 =
* Fix: WooCommerce products list table broken

= 1.2.8 =
* New: Multisite compatibility
* Fix: Undefined index post_views_column on post_views_counter/includes/settings.php
* Tweak: Improved user IP handling

= 1.2.7 =
* Fix: Chart data not updating for object cached installs due to missing expire parameter
* Fix: Bug preventing hiding the counter based on user role.
* Fix: Undefined notice in the admin dashboard request

= 1.2.6 =
* Fix: Hardcoded post_views database table prefix

= 1.2.5 =
* New: REST API counter mode
* New: Adjust dashboard chart colors to admin color scheme
* Tweak: Dashboard chart query optimization
* Tweak: post_views database table optimization
* Tweak: Added plugin documentation link

= 1.2.4 =
* New: Advanced crawler detection
* Tweak: Chart.js script update to 2.4.0

= 1.2.3 =
* New: IP wildcard support
* Tweak: Delete post_views database table on deactivation

= 1.2.2 =
* Fix: Notice undefined variable: post_ids, thanks to [zytzagoo](https://github.com/zytzagoo)
* Tweak: Switched translation files storage, from local to WP repository

= 1.2.1 =
* New: Option to display post views on select page types
* Tweak: Dashboard widget query optimization

= 1.2.0 =
* New: Dashboard post views stats widget
* Fix: A couple of typos in method names

= 1.1.4 =
* Fix: Dashicons link broken.
* Tweak: Confirmed WordPress 4.4 compatibility

= 1.1.3 =
* Fix: Duplicated views count in custom post types
* Fix: Exclude visitors checkboxes not working

= 1.1.2 =
* Fix: Most viewed posts widget broken

= 1.1.1 =
* Tweak: Enable edit views on new post.
* Tweak: Extend WP_Query post data with post_views

= 1.1.0 =
* New: Quick post views edit
* New: Bulk post views edit
* Tweak: Admin UI improvements

= 1.0.12 =
* New: Italian translation, thanks to [Rene Querin](http://www.q-design.it)

= 1.0.11 =
* New: French translation, thanks to [Theophil Bethel](http://reseau-chretien-gironde.fr/)

= 1.0.10 =
* New: Option to limit post views editing to admins only

= 1.0.9 =
* New: Spanish translation, thanks to [Carlos Rodriguez](http://cglevel.com/)

= 1.0.8 =
* New: Croation translation, thanks to [Tomas Trkulja](http://zytzagoo.net/blog/)

= 1.0.7 =
* New: Possibility to manually set views count for each post
* New: Plugin development moved to [dFactory GitHub Repository](https://github.com/dfactoryplugins)

= 1.0.6 =
* New: Object cache support, thanks to [Tomas Trkulja](http://zytzagoo.net/blog/)
* New: Hebrew translation, thanks to [Ahrale Shrem](http://atar4u.com/)

= 1.0.5 =
* Tweak: Added number_format_i18n for displayed views count
* Tweak: Additional action hook for developers

= 1.0.4 =
* Fix: Possible issue with remove_post_views_count function

= 1.0.3 =
* New: Russian translation, thanks to moonkir
* Fix: Remove [post-views] shortcode from post excerpts if excerpt is empty

= 1.0.2 =
* Fix: Pluggable functions initialized too lately

= 1.0.0 =
Initial release

== Upgrade Notice ==

= 1.8.0 =
Adds site-wide Visits, dashboard comparisons and Custom Format for the Views counter, with counting and editing fixes. If you use Pro, update both plugins and purge full-page caches so dynamic counters use the new markup.
