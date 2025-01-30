# Facebook Conversion API Plugin for Grav CMS

This plugin integrates Facebook Conversion API with Grav CMS, allowing you to send server-side events to Facebook for improved conversion tracking and analytics.
<div align="center">
![Plugin Screenshot](plugin-image.webp?raw=true)



[![Buy Me A Coffee](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://www.buymeacoffee.com/paulmassendari)
[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/paulmassendari)
</div>

## Features

- Server-side event tracking
- Automatic PageView events tracking
- Support for custom events
- Test events mode support
- Debug mode for troubleshooting
- No Facebook Pixel required

## Installation

### Manual Installation
The plugin is available through the Grav Package Manager (GPM).

To install the plugin, you can:
1. Browse the Plugin section from Admin
2. Install it with the GPM with `bin/gpm install facebook-conversion-api`
3. To install the plugin manually, download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then rename the folder to `facebook-conversion-api`.

You should now have all the plugin files under:

```
/your/site/grav/user/plugins/facebook-conversion-api
```

## Configuration

Here is the default configuration and an explanation of available options:

```yaml
enabled: true                   # Plugin status
access_token: ''               # Your Facebook Conversion API access token
pixel_id: ''                   # Your Facebook Pixel ID
track_pageviews: true         # Enable automatic PageView event tracking
test_event_code: ''           # Test Event Code (optional)
debug: false                  # Enable debug logging
```

### Getting the Required Credentials

1. Go to [Facebook Events Manager](https://business.facebook.com/events_manager)
2. Select your Pixel
3. Go to Settings
4. Find your Pixel ID
5. Go to "Access Token" section to generate your access token

## Usage

### Automatic PageView Tracking

If `track_pageviews` is enabled, the plugin will automatically send PageView events for each page visited on your site. These events include:
- Page URL
- Page title
- Content type (template)
- Content ID (slug)

### Sending Custom Events

You can send custom events in your Twig templates:

```twig
{# Basic event #}
{% do fb_conversion_event('Purchase', [], {'value': 123.45, 'currency': 'EUR'}) %}

{# Event with user data #}
{% set user_data = {
    'em': user.email|md5,
    'ph': user.phone|md5
} %}

{% set custom_data = {
    'value': order.total,
    'currency': 'EUR',
    'content_name': product.name
} %}

{% do fb_conversion_event('Purchase', user_data, custom_data) %}
```

### PHP Usage

You can also send events from your PHP code:

```php
$fbPlugin = $grav['plugins']['facebook-conversion-api'];

$userData = [
    'em' => hash('md5', $user->email),
    'ph' => hash('md5', $user->phone)
];

$customData = [
    'value' => $order->getAmount(),
    'currency' => 'EUR',
    'content_name' => $product->getName()
];

$fbPlugin->sendEvent('Purchase', $userData, $customData);
```

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
    'em': user.email|md5,
    'ph': user.phone|md5,
    'external_id': user.id|md5
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
    'em' => hash('md5', $user->email),
    'ph' => hash('md5', $user->phone),
    'external_id' => hash('md5', $user->id)
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

## Troubleshooting

If you're having issues:

1. Enable debug mode in the configuration
2. Check the Grav logs for detailed error messages in the Clockwork Debugger
3. Verify your access token and pixel ID
4. Make sure your server can make outbound HTTPS requests
5. Check the Events Manager Test Events tab to see if events are being received

## Requirements

- Grav CMS 1.7+
- PHP 7.3+
- PHP cURL extension

## License

MIT License - see LICENSE file for details


