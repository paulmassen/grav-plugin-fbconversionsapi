<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

class FacebookConversionAPIPlugin extends Plugin
{
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
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onPageInitialized' => ['onPageInitialized', 0]  // Ajout de l'événement pour les pages vues
        ]);
    }

    public function onTwigInitialized()
    {
        $this->grav['twig']->twig->addFunction(
            new \Twig\TwigFunction('fb_conversion_event', [$this, 'sendEvent'])
        );
    }

    /**
     * Gérer le suivi automatique des pages vues
     */
    public function onPageInitialized()
    {
        if ($this->config->get('plugins.facebook-conversion-api.track_pageviews', true)) {
            $page = $this->grav['page'];
            $uri = $this->grav['uri'];

            // Préparer les données de l'événement PageView
            $eventData = [
                'event_name' => 'PageView',
                'event_time' => time(),
                'action_source' => 'website',
                'event_id' => uniqid('fb_pageview_', true),
                'user_data' => [],  // Pas de données utilisateur pour les pages vues simples
                'custom_data' => [
                    'content_type' => $page->template(),
                    'content_name' => $page->title(),
                    'content_id' => $page->slug(),
                    'url' => $uri->url(true)
                ]
            ];

            // Envoyer l'événement PageView
            $this->sendEvent('PageView', [], $eventData['custom_data'], $eventData['event_id']);
        }
    }

    /**
     * Envoie un événement à Facebook Conversion API
     * 
     * @param string $eventName Nom de l'événement (ex: 'Purchase', 'Lead', etc.)
     * @param array $userData Données de l'utilisateur (email_hash, phone_hash, etc.)
     * @param array $customData Données personnalisées de l'événement (valeur, devise, etc.)
     * @param string $eventId Identifiant unique de l'événement (optionnel)
     * @return array|null Réponse de l'API Facebook ou null en cas d'erreur
     */
    public function sendEvent($eventName, $userData = [], $customData = [], $eventId = null)
    {
        $accessToken = $this->config->get('plugins.facebook-conversion-api.access_token');
        $pixelId = $this->config->get('plugins.facebook-conversion-api.pixel_id');
        
        if (!$accessToken || !$pixelId) {
            $this->grav['log']->error('Facebook Conversion API: Token d\'accès ou ID de pixel manquant');
            return null;
        }

        // Préparer les données de l'événement
        $eventData = [
            'data' => [[
                'event_name' => $eventName,
                'event_time' => time(),
                'event_source_url' => $this->grav['uri']->url(true),
                'action_source' => 'website',
                'event_id' => $eventId ?? uniqid('fb_', true),
                'user_data' => array_merge([
                    'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ], $userData),
                'custom_data' => $customData
            ]],
            'access_token' => $accessToken,
            'test_event_code' => $this->config->get('plugins.facebook-conversion-api.test_event_code', ''),
        ];

        // Activer le debug logging si configuré
        if ($this->config->get('plugins.facebook-conversion-api.debug', true)) {
            $this->grav['log']->debug('Facebook Conversion API - Envoi événement: ' . print_r($eventData, true));
        }

        // Envoyer à l'API Facebook
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://graph.facebook.com/v17.0/{$pixelId}/events",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($eventData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseData = json_decode($response, true);
        
        if ($error || $httpCode !== 200) {
            $errorMessage = sprintf(
                "Facebook Conversion API Error:\nHTTP Code: %d\nCURL Error: %s\nResponse: %s\nData sent: %s",
                $httpCode,
                $error,
                json_encode($responseData, JSON_PRETTY_PRINT),
                json_encode($eventData, JSON_PRETTY_PRINT)
            );
            $this->grav['log']->error($errorMessage);
            return null;
        }

        return $responseData;
    }
}

