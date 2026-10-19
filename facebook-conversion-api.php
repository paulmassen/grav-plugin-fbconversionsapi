<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Framework\Psr7\Response;
use RocketTheme\Toolbox\Event\Event;

/**
 * Facebook (Meta) Conversions API – server-side events for Grav.
 *
 * Compatible with Grav 1.7 (classic Admin) and Grav 2 (Admin2 / API plugin).
 *
 * - PageView / Twig events are queued and sent in onShutdown, after the
 *   response has been flushed to the visitor (no impact on page load time).
 * - Bots, non-GET requests, error pages and unknown hosts are ignored.
 * - The access token is never written to the logs; identical errors are
 *   logged at most once per hour and sending is paused after a
 *   configuration error (invalid token, missing permission on the pixel…).
 */
class FacebookConversionAPIPlugin extends Plugin
{
    private const CONFIG = 'plugins.facebook-conversion-api.';
    private const PAUSE_CACHE_KEY = 'fbcapi-paused';
    private const DEFAULT_API_VERSION = 'v25.0';
    private const TWIG_FUNCTION = 'fb_conversion_event';

    /** Fields of user_data that Meta expects SHA-256 hashed (normalized beforehand). */
    private const HASHED_FIELDS = ['em', 'ph', 'fn', 'ln', 'db', 'ge', 'ct', 'st', 'zp', 'country', 'external_id'];

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|scan|facebookexternalhit|preview|headless|lighthouse|pingdom|uptime|monitor|python|curl|wget|httpclient|okhttp|java\/|go-http|scrapy|axios|node-fetch|libwww|guzzle|php\//i';

    /** @var array<int, array> Events waiting to be sent on shutdown */
    private $queue = [];

    /** @var array|null user_data shared by all events of the current request */
    private $defaultUserData;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized()
    {
        // Classic Admin (Grav 1.7). Admin2 (Grav 2) never runs the page
        // pipeline: it talks to the API plugin, which fires none of the
        // events below, so nothing is tracked from the administration.
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onBuildTwigSandboxPolicy' => ['onBuildTwigSandboxPolicy', 0],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onPageTask.facebookConversion.sendEvent' => ['onPageTaskSendEvent', 0],
            'onShutdown' => ['onShutdown', 0],
        ]);
    }

    public function onTwigInitialized()
    {
        $this->grav['twig']->twig->addFunction(
            new \Twig\TwigFunction(self::TWIG_FUNCTION, [$this, 'queueEvent'])
        );
    }

    /**
     * Grav 2: page content Twig runs in a sandbox. Allow fb_conversion_event()
     * there, as on Grav 1.7 (it only queues an event and exposes no data).
     * This event does not exist on Grav 1.7, where the handler is never called.
     */
    public function onBuildTwigSandboxPolicy(Event $event): void
    {
        $functions = (array) $event['functions'];
        if (!in_array(self::TWIG_FUNCTION, $functions, true)) {
            $functions[] = self::TWIG_FUNCTION;
            $event['functions'] = $functions;
        }
    }

    /**
     * Automatic PageView tracking.
     */
    public function onPageInitialized()
    {
        if (!$this->config->get(self::CONFIG . 'track_pageviews', true)) {
            return;
        }

        $uri = $this->grav['uri'];
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $extension = $uri->extension() ?: 'html';
        if ($method !== 'GET' || $extension !== 'html') {
            // AJAX calls (page.json), HEAD requests from monitors, feeds, etc.
            return;
        }

        $page = $this->grav['page'];
        if (!$page || $page->template() === 'error') {
            return;
        }

        $this->queueEvent('PageView', [], [
            'content_type' => $page->template(),
            'content_name' => $page->title(),
            'content_ids' => [$page->slug()],
        ], uniqid('fb_pageview_', true));
    }

    /**
     * AJAX endpoint (e.g. click on "Add to calendar").
     * POST body: event_name (default AddToCalendar), custom_data (JSON object or string).
     */
    public function onPageTaskSendEvent(Event $event): void
    {
        $request = $event['request'] ?? null;
        if (!$request) {
            $this->sendJsonResponse(['success' => false, 'error' => 'No request'], 400);
            return;
        }

        $body = $request->getParsedBody() ?? [];
        $eventName = isset($body['event_name']) ? (string) $body['event_name'] : 'AddToCalendar';
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $eventName)) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Invalid event name'], 400);
            return;
        }

        $customData = $body['custom_data'] ?? [];
        if (is_string($customData)) {
            $customData = json_decode($customData, true) ?: [];
        }
        if (!is_array($customData)) {
            $customData = [];
        }

        // Grav::close() exits without firing onShutdown: send right away.
        $result = $this->sendEvent($eventName, [], $customData);
        $this->sendJsonResponse(['success' => $result !== null]);
    }

    /**
     * Flush queued events once the response has been sent to the visitor.
     */
    public function onShutdown()
    {
        if (!$this->queue) {
            return;
        }

        $events = $this->queue;
        $this->queue = [];
        $this->sendEvents($events);
    }

    /**
     * Queue an event, sent at the end of the request. Exposed to Twig as fb_conversion_event().
     *
     * @param string $eventName  Standard or custom event name (PageView, ViewContent, Lead, Purchase…)
     * @param array  $userData   Customer information (em, ph, fn, ln, external_id… raw or already hashed)
     * @param array  $customData Event data (value, currency, content_name…)
     * @param string|null $eventId Event ID (used for deduplication with the browser pixel)
     * @return bool true if the event was queued
     */
    public function queueEvent($eventName, $userData = [], $customData = [], $eventId = null): bool
    {
        if (!$this->shouldTrackRequest()) {
            return false;
        }

        $this->queue[] = $this->buildEvent((string) $eventName, (array) $userData, (array) $customData, $eventId);

        return true;
    }

    /**
     * Send an event immediately (kept for backward compatibility).
     *
     * @return array|null Facebook API response or null on error / skipped
     */
    public function sendEvent($eventName, $userData = [], $customData = [], $eventId = null)
    {
        if (!$this->shouldTrackRequest()) {
            return null;
        }

        return $this->sendEvents([$this->buildEvent((string) $eventName, (array) $userData, (array) $customData, $eventId)]);
    }

    /**
     * Is the current request worth reporting to Facebook?
     */
    private function shouldTrackRequest(): bool
    {
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($this->config->get(self::CONFIG . 'exclude_bots', true)
            && ($userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent))) {
            return false;
        }

        $allowedHosts = array_filter(array_map(
            static function ($host) {
                return strtolower(trim((string) $host));
            },
            (array) $this->config->get(self::CONFIG . 'allowed_hosts', [])
        ));
        if ($allowedHosts) {
            $host = strtolower((string) $this->grav['uri']->host());
            if (!in_array($host, $allowedHosts, true)) {
                return false;
            }
        }

        return true;
    }

    private function buildEvent(string $eventName, array $userData, array $customData, $eventId = null): array
    {
        $userData = array_merge($this->defaultUserData(), $this->normalizeUserData($userData));

        $event = [
            'event_name' => $eventName,
            'event_time' => time(),
            'event_source_url' => $this->grav['uri']->url(true),
            'action_source' => 'website',
            'event_id' => $eventId ?: uniqid('fb_', true),
            'user_data' => array_filter($userData, static function ($value) {
                return $value !== null && $value !== '' && $value !== [];
            }),
        ];

        if ($customData) {
            $event['custom_data'] = $customData;
        }

        return $event;
    }

    /**
     * IP, user agent and Facebook click/browser identifiers (improve event match quality).
     */
    private function defaultUserData(): array
    {
        if ($this->defaultUserData === null) {
            $fbc = $_COOKIE['_fbc'] ?? null;
            if (!$fbc && !empty($_GET['fbclid']) && is_string($_GET['fbclid'])) {
                // Format expected by Meta: fb.1.<creation time in ms>.<fbclid>
                $fbc = 'fb.1.' . (int) round(microtime(true) * 1000) . '.' . $_GET['fbclid'];
            }

            $this->defaultUserData = [
                'client_ip_address' => $this->clientIp(),
                'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'fbp' => $_COOKIE['_fbp'] ?? null,
                'fbc' => $fbc,
            ];
        }

        return $this->defaultUserData;
    }

    /**
     * Visitor IP resolved by Grav, which honours the system.http_x_forwarded.*
     * settings (reverse proxy / Cloudflare), falling back to REMOTE_ADDR.
     */
    private function clientIp(): ?string
    {
        $ip = Uri::ip();
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        }

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Hash personal fields that are not already SHA-256 hashed, as required by Meta.
     */
    private function normalizeUserData(array $userData): array
    {
        foreach (self::HASHED_FIELDS as $field) {
            if (!isset($userData[$field]) || $userData[$field] === '' || $userData[$field] === []) {
                continue;
            }
            $values = (array) $userData[$field];
            foreach ($values as $i => $value) {
                $value = (string) $value;
                if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
                    $value = strtolower(trim($value));
                    if ($field === 'ph') {
                        $value = preg_replace('/\D+/', '', $value);
                    }
                    $value = hash('sha256', $value);
                }
                $values[$i] = $value;
            }
            $userData[$field] = is_array($userData[$field]) ? array_values($values) : reset($values);
        }

        return $userData;
    }

    /**
     * POST events to the Conversions API.
     *
     * @return array|null
     */
    private function sendEvents(array $events)
    {
        $accessToken = trim((string) $this->config->get(self::CONFIG . 'access_token'));
        $pixelId = trim((string) $this->config->get(self::CONFIG . 'pixel_id'));

        if ($accessToken === '' || $pixelId === '') {
            $this->logOnce('missing-credentials', 'Facebook Conversion API: access token or pixel ID missing.');
            return null;
        }

        $cache = $this->grav['cache'];
        if ($cache->fetch(self::PAUSE_CACHE_KEY)) {
            return null;
        }

        $payload = ['data' => $events];
        $testEventCode = trim((string) $this->config->get(self::CONFIG . 'test_event_code', ''));
        if ($testEventCode !== '') {
            $payload['test_event_code'] = $testEventCode;
        }

        $debug = (bool) $this->config->get(self::CONFIG . 'debug', false);
        if ($debug) {
            // The access token is not part of $payload: safe to log.
            $this->grav['log']->debug('Facebook Conversion API - sending: ' . json_encode($payload));
        }

        $apiVersion = trim((string) $this->config->get(self::CONFIG . 'api_version', self::DEFAULT_API_VERSION)) ?: self::DEFAULT_API_VERSION;
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events?access_token=%s',
            rawurlencode($apiVersion),
            rawurlencode($pixelId),
            rawurlencode($accessToken)
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseData = is_string($response) ? json_decode($response, true) : null;

        if ($curlError || $httpCode !== 200) {
            $apiError = is_array($responseData) ? ($responseData['error'] ?? []) : [];
            $code = (int) ($apiError['code'] ?? 0);
            $subcode = (int) ($apiError['error_subcode'] ?? 0);
            $message = sprintf(
                'Facebook Conversion API error (pixel %s, %s): HTTP %d, code %d/%d – %s',
                $pixelId,
                $apiVersion,
                $httpCode,
                $code,
                $subcode,
                $curlError ?: ($apiError['message'] ?? 'unknown error')
            );

            // Configuration errors will not fix themselves: pause sending to avoid
            // hammering Facebook (and keeping PHP workers busy) on every page view.
            $isConfigError = in_array($code, [10, 100, 190, 200, 803], true)
                || ($apiError['type'] ?? '') === 'OAuthException';
            if ($isConfigError) {
                $pause = max(0, (int) $this->config->get(self::CONFIG . 'pause_on_error', 3600));
                if ($pause > 0) {
                    $cache->save(self::PAUSE_CACHE_KEY, time(), $pause);
                    $message .= sprintf(' – sending paused for %d s (check the pixel ID and access token).', $pause);
                }
            }

            $this->logOnce('api-' . $httpCode . '-' . $code . '-' . $subcode, $message);
            return null;
        }

        if ($debug) {
            $this->grav['log']->debug('Facebook Conversion API - response: ' . $response);
        }

        return $responseData;
    }

    /**
     * Log a given error at most once per hour.
     */
    private function logOnce(string $key, string $message): void
    {
        $cache = $this->grav['cache'];
        $cacheKey = 'fbcapi-log-' . md5($key);
        if ($cache->fetch($cacheKey)) {
            return;
        }
        $cache->save($cacheKey, 1, 3600);
        $this->grav['log']->error($message);
    }

    /**
     * Send a JSON response and stop execution.
     */
    private function sendJsonResponse(array $data, int $status = 200): void
    {
        $response = new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_THROW_ON_ERROR)
        );
        $this->grav->close($response);
    }
}
