# v1.2.0
##  10/19/2026

1. [](#new)
    * Grav 2 and Admin2 compatibility (tested on Grav 2.2.10 + Admin2 2.1.35 / API 1.0.51 and Grav 1.7.53.5 + Admin 1.10.60)
    * Declares `compatibility: grav: ['1.7', '2.0']` so the Grav 2 upgrade preflight and GPM no longer flag the plugin as Grav 1.7 only
    * `fb_conversion_event()` allowed in sandboxed page content on Grav 2 (`onBuildTwigSandboxPolicy`)
1. [](#improved)
    * Visitor IP resolved with `Uri::ip()`, honouring the `system.http_x_forwarded.*` proxy settings
    * Minimum Grav version raised to 1.7.0
    * README: Grav 2 / Admin2 section, Twig in page content example

# v1.1.0
##  10/19/2026

1. [](#new)
    * Events are queued and sent in `onShutdown`, after the page has been delivered (no more page load delay)
    * AJAX task `facebookConversion.sendEvent` (e.g. "Add to calendar")
    * `exclude_bots` option (crawlers, scanners, monitoring tools)
    * `allowed_hosts` option (ignore requests on unknown domains / server IP)
    * `pause_on_error` option: sending is paused after a configuration error (invalid token, missing permission)
    * `api_version` option (default v25.0)
    * `fbp` / `fbc` cookies and `fbclid` sent for better event match quality
    * Personal user data (em, ph, fn, ln…) automatically normalized and SHA-256 hashed
1. [](#improved)
    * Payload sent as JSON, `test_event_code` only sent when set
    * No PageView for POST/HEAD, `.json` requests or error pages
1. [](#bugfix)
    * Access token no longer written to the logs
    * Identical errors logged at most once per hour (log was growing by GB)
    * Graph API v17.0 (expired) replaced by v25.0
    * "Plugin status" and "Debug" labels were swapped in the admin
    * Debug logging no longer enabled by default when the setting is missing
    * README: user data must be SHA-256 hashed (not MD5) – now done automatically by the plugin
    * Fixed homepage URL and PHP requirement (>= 7.3) in composer.json

# v1.0.0
##  01/30/2025

1. [](#new)
    * ChangeLog started...
    * Initial Release
