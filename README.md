<div align="center">
    
# Facebook Conversion API Plugin for Grav CMS




![](https://github.com/paulmassen/grav-plugin-fbconversionsapi/blob/main/plugin-image.png?raw=true)


[![Buy Me A Coffee](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://www.buymeacoffee.com/paulmassendari)
[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/paulmassendari)

Support to the Grav CMS 👇
<a href="https://opencollective.com/grav" target="_blank">

  <img src="https://opencollective.com/webpack/donate/button@2x.png?color=blue" width=250 />
</a>
</div>

This plugin integrates Facebook Conversion API with Grav CMS, allowing you to send server-side events to Facebook for improved conversion tracking and analytics.
## Features

- Server-side event tracking
- Automatic PageView events tracking
- Support for custom events (Twig, PHP and AJAX)
- Events sent **after** the page has been delivered: no impact on page load time
- Bots, unknown domains, AJAX/HEAD requests and error pages are ignored
- Personal data (email, phone, name…) automatically normalized and SHA-256 hashed
- `fbp` / `fbc` cookies and `fbclid` forwarded for a better event match quality
- Errors logged once per hour (the access token is never logged) and sending paused after a configuration error
- Test events mode support
- Debug mode for troubleshooting
- No Facebook Pixel required
- Compatible with **Grav 1.7** (classic Admin) and **Grav 2** (Admin2)

## Installation

### Manual Installation
The plugin is not (yet) listed in the Grav Package Manager (GPM). To install it:

1. Download the zip of the [latest release](https://github.com/paulmassen/grav-plugin-fbconversionsapi/releases/latest) and unzip it under `/your/site/grav/user/plugins`. Then rename the folder to `facebook-conversion-api`.
2. Or clone the repository:
   ```bash
   cd /your/site/grav/user/plugins
   git clone https://github.com/paulmassen/grav-plugin-fbconversionsapi.git facebook-conversion-api
   ```

You should now have all the plugin files under:

```
/your/site/grav/user/plugins/facebook-conversion-api
```

## Configuration

Here is the default configuration and an explanation of available options:

```yaml
enabled: true            # Plugin status
debug: false             # Log every event sent and every API response
test_event_code: ''      # Test Event Code (testing only, leave empty in production)
pixel_id: ''             # Your Facebook Pixel (dataset) ID
access_token: ''         # Conversions API access token (generated from the same dataset)
api_version: v25.0       # Graph / Marketing API version
track_pageviews: true    # Send a PageView event for each page view
exclude_bots: true       # Ignore crawlers, scanners, monitoring tools…
allowed_hosts: []        # Only track these domains, e.g. [www.example.com] (empty = all)
pause_on_error: 3600     # Pause sending N seconds after a configuration error (0 = never)
```

> **Tip:** if your site is the default virtual host of the server, any domain pointing to your IP
> (and every scanner hitting the IP directly) will be served by Grav. Set `allowed_hosts` to your
> real domain(s) so these requests are not sent to Facebook.

### Getting the Required Credentials

1. Go to [Facebook Events Manager](https://business.facebook.com/events_manager)
2. Select your Pixel (dataset)
3. Go to Settings
4. Copy the dataset / Pixel ID
5. In the "Conversions API" section, click "Generate access token"

The access token **must be generated from the same dataset** as the Pixel ID. Otherwise Facebook answers
`Object with ID '…' does not exist, cannot be loaded due to missing permissions` (error 100, subcode 33).

## Usage

### Automatic PageView Tracking

If `track_pageviews` is enabled, the plugin will automatically send PageView events for each page visited on your site. These events include:
- Page URL
- Page title
- Content type (template)
- Content IDs (slug)

PageView events are only sent for `GET` requests on HTML pages (not for `.json` AJAX calls, feeds, `HEAD` requests or error pages).

### When are events sent?

Events created during the page rendering (automatic PageView and `fb_conversion_event()` in Twig) are queued
and sent in a single API call on Grav's `onShutdown` event, once the response has been delivered to the visitor.

### User data and hashing

Meta requires personal data to be normalized and **SHA-256** hashed. You can pass raw values: the fields
`em`, `ph`, `fn`, `ln`, `db`, `ge`, `ct`, `st`, `zp`, `country` and `external_id` are trimmed, lowercased
(phone: digits only) and hashed automatically. Values that are already SHA-256 hashes are left untouched.

> Do **not** hash these fields with MD5: Meta would not be able to match them.

`client_ip_address`, `client_user_agent`, `fbp` (`_fbp` cookie) and `fbc` (`_fbc` cookie or `fbclid` URL parameter)
are added automatically.

### Sending Custom Events

You can send custom events in your Twig templates:

```twig
{# Basic event #}
{% do fb_conversion_event('Purchase', [], {'value': 123.45, 'currency': 'EUR'}) %}

{# Event with user data (raw values, hashed by the plugin) #}
{% set user_data = {
    'em': user.email,
    'ph': user.phone
} %}

{% set custom_data = {
    'value': order.total,
    'currency': 'EUR',
    'content_name': product.name
} %}

{% do fb_conversion_event('Purchase', user_data, custom_data) %}
```

`fb_conversion_event()` can also be used in page content (Markdown with Twig processing enabled),
for example on a "thank you" page:

```markdown
---
title: Thank you
process:
  twig: true
---

{% do fb_conversion_event('Lead', [], {'content_name': 'Newsletter'}) %}
```

On Grav 2, page content Twig runs in a security sandbox: the plugin allows `fb_conversion_event`
in it automatically (`onBuildTwigSandboxPolicy`). Twig in page content must still be enabled under
Configuration > Security > Twig in Content.

### PHP Usage

You can also send events from your PHP code:

```php
$fbPlugin = $grav['plugins']['facebook-conversion-api'];

$userData = [
    'em' => $user->email,   // raw values, hashed by the plugin
    'ph' => $user->phone
];

$customData = [
    'value' => $order->getAmount(),
    'currency' => 'EUR',
    'content_name' => $product->getName()
];

// Sent immediately
$fbPlugin->sendEvent('Purchase', $userData, $customData);

// Or queued and sent after the response has been delivered
$fbPlugin->queueEvent('Purchase', $userData, $customData);
```

### Sending Events from JavaScript (AJAX)

The plugin exposes the `facebookConversion.sendEvent` page task, useful to track clicks
(e.g. an "Add to calendar" button). POST to any page URL with the `.json` extension:

```twig
<script>
fetch({{ (base_url_relative|rtrim('/') ~ page.rawRoute ~ '.json')|json_encode|raw }}, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'task=facebookConversion.sendEvent&event_name=AddToCalendar&custom_data='
        + encodeURIComponent(JSON.stringify({ content_name: {{ page.title|json_encode|raw }} }))
}).catch(function () {});
</script>
```

- Use `page.rawRoute`, not `page.url`: on the home page `page.url` is `/`, which gives `/.json`,
  a "hidden file" URL that most nginx configurations reject with a 403.
- `event_name`: letters, digits and underscores only (default `AddToCalendar`)
- `custom_data`: JSON object
- Response: `{"success": true|false}`

### Standard Events

Here are the standard events you can use:
- PageView
- Purchase
- Lead
- CompleteRegistration
- AddToCart
- InitiateCheckout
- Subscribe
- Contact
- ViewContent

### ViewContent Event Example

The ViewContent event is particularly useful for tracking when users view specific content like products, articles, or services. Here's how to implement it:

```twig
{# Basic ViewContent for a product page #}
{% set custom_data = {
    'content_type': page.template,
    'content_name': page.title,
    'content_id': page.header.product_id,
    'currency': 'EUR',
    'value': page.header.price
} %}

{% do fb_conversion_event('ViewContent', [], custom_data) %}

{# Advanced ViewContent with user data and additional parameters #}
{% set user_data = {
    'em': user.email,
    'ph': user.phone,
    'external_id': user.id
} %}

{% set custom_data = {
    'content_type': 'product',
    'content_name': product.name,
    'content_id': product.sku,
    'content_category': product.category,
    'currency': 'EUR',
    'value': product.price,
    'content_ids': [product.sku],
    'description': product.description|slice(0, 100)
} %}

{% do fb_conversion_event('ViewContent', user_data, custom_data) %}
```

PHP implementation:

```php
$fbPlugin = $grav['plugins']['facebook-conversion-api'];

// Basic ViewContent
$customData = [
    'content_type' => $page->template(),
    'content_name' => $page->title(),
    'content_id' => $page->header()->product_id,
    'currency' => 'EUR',
    'value' => $page->header()->price
];

$fbPlugin->sendEvent('ViewContent', [], $customData);

// Advanced ViewContent
$userData = [
    'em' => $user->email,
    'ph' => $user->phone,
    'external_id' => (string) $user->id
];

$customData = [
    'content_type' => 'product',
    'content_name' => $product->getName(),
    'content_id' => $product->getSku(),
    'content_category' => $product->getCategory(),
    'currency' => 'EUR',
    'value' => $product->getPrice(),
    'content_ids' => [$product->getSku()],
    'description' => substr($product->getDescription(), 0, 100)
];

$fbPlugin->sendEvent('ViewContent', $userData, $customData);
```

Key parameters for ViewContent events:
- `content_type`: Type of content (product, article, etc.)
- `content_name`: Name of the content
- `content_id`: Unique identifier
- `content_category`: Category of the content
- `currency`: Currency for the value (if applicable)
- `value`: Monetary value (if applicable)
- `description`: Brief description of the content
- `content_ids`: Array of content identifiers (useful for multiple items)

### Testing Events

1. Get your Test Event Code from Facebook Events Manager
2. Add it to your configuration:
```yaml
test_event_code: 'TEST123'  # Replace with your test code
```
3. Enable debug mode to see detailed logs:
```yaml
debug: true
```
4. **Empty `test_event_code` once your tests are done**: while it is set, events only appear in the
   "Test events" tab and are not used for measurement or optimization.

## Troubleshooting

If you're having issues:

1. Enable debug mode in the configuration
2. Check `logs/grav.log` (each distinct error is logged at most once per hour)
3. Verify your access token and pixel ID — the token must be generated from the same dataset
4. After a configuration error (invalid token, missing permission…) sending is paused for `pause_on_error`
   seconds: clear the Grav cache (`bin/grav clearcache`) to retry immediately after fixing the configuration
5. Make sure your server can make outbound HTTPS requests
6. Check the Events Manager Test Events tab to see if events are being received

## Requirements

- Grav CMS 1.7 or Grav 2.x (`compatibility: grav: ['1.7', '2.0']`)
- PHP 7.3+ (Grav 2 itself requires PHP 8.3+)
- PHP cURL extension

## Grav 2 / Admin2

- The plugin settings are fully editable in **Admin2**. The access token is masked in the form
  (`********`) and preserved when you save other settings; type a new value to replace it.
- Admin2 talks to Grav through the API plugin, which does not run the page pipeline: browsing
  the administration never sends events to Facebook.
- The visitor IP is resolved with Grav's `Uri::ip()`. On Grav 2 forwarded headers are opt-in:
  if your site is behind a reverse proxy or Cloudflare, enable the matching
  `system.http_x_forwarded.*` setting so Facebook receives the real visitor IP.

## License

MIT License - see LICENSE file for details


