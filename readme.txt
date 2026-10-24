=== Slow Query Analyzer ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Finds slow and repeated MySQL queries and explains why they are slow. Only profiles your own admin session — visitors are never affected.

== How it works ==

1. Go to Tools → Slow Queries and click "Start profiling" (15 min – 24 h).
2. Browse the pages you want to check, front end or admin, in the same browser.
   The admin bar shows the query count, query time and slow queries for each page.
3. Return to Tools → Slow Queries:
   * Slow queries – grouped by shape, with the plugin/theme that ran them. "Analyze" runs EXPLAIN and gives suggestions.
   * Profiled pages – per-page totals, slow queries and repeated (N+1) queries.
   * Database health – autoloaded options, object cache, transients, revisions, largest tables, server settings.

== Visitor safety ==

* Profiling is off until an administrator starts it, and it ends automatically when the chosen time runs out, when you click Stop, or when you log out.
* It is tied to a signed, HttpOnly cookie in your browser. Every request without that cookie (all visitors) skips the plugin entirely; query logging (SAVEQUERIES) is never turned on for them.
* Even with the cookie, data is only stored when the request belongs to that same logged-in administrator.

== Notes ==

* Do not set SAVEQUERIES in wp-config.php. If it is set to true, it logs queries for every visitor; if it is set to false, this plugin cannot record anything.
* Queries that run before plugins load (a handful of core queries) are not captured.
* Deleting the plugin removes its tables and options.
