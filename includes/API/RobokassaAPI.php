<?php
/**
 * Обработчик API Robokassa
 *
 * @package RobokassaFluentCart
 * @since 1.0.0
 */


namespace RobokassaFluentCart\API;

use RobokassaFluentCart\Settings\RobokassaSettingsBase;

if (!defined('ABSPATH')) {
    exit; // Прямой доступ не разрешен.
}


class RobokassaAPI
{
    private static $baseUrl = 'https://api.robokassa.ru/';
    private static $settings = null;

    /**
     * Получить экземпляр настроек
     */
    public static function getSettings()
    {
        if (!self::$settings) {
            self::$settings = new RobokassaSettingsBase();
        }
        return self::$settings;
    }


    private static function request($endpoint, $method = 'GET', $data = [])
    {
        // Проверка ввода
        if (empty($endpoint) || !is_string($endpoint)) {
            return new \WP_Error('invalid_endpoint', 'Invalid API endpoint provided');
        }

        
        // Проверка HTTP-метода
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            return new \WP_Error('invalid_method', 'Invalid HTTP method');
        }

        $url = self::$baseUrl . $endpoint;
        $secretKey = self::getSettings()->getSecretKey();

        if (!$secretKey) {
            return new \WP_Error('missing_api_key', 'Robokassa API key is not configured');
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . sanitize_text_field($secretKey),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'RobokassaFluentCart/1.0.0 WordPress/' . get_bloginfo('version'),
            ],
            'timeout' => 30,
            'sslverify' => true, // Всегда проверять SSL
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $statusCode = wp_remote_retrieve_response_code($response);
        
        if ($statusCode >= 400) {
            return new \WP_Error(
                'robokassa_api_error',
                $decoded['message'] ?? 'Unknown Robokassa API error',
                ['status' => $statusCode, 'response' => $decoded]
            );
        }

        return $decoded;
    }


    public static function getRobokassaObject($endpoint, $params = [])
    {
        return self::request($endpoint, 'GET', $params);
    }

    public static function createRobokassaObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }

    public static function deleteRobokassaObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }
}