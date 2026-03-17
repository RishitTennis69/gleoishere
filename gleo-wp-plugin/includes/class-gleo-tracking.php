<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Gleo_Tracking {
    private $api_url = 'http://localhost:3000/v1/analytics/bot-hit';

    public function __construct() {
        add_action( 'template_redirect', array( $this, 'detect_ai_bots' ) );
    }

    public function detect_ai_bots() {
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if ( empty( $user_agent ) ) return;

        $bots = array(
            'GPTBot'          => 'OpenAI GPTBot',
            'ChatGPT-User'    => 'OpenAI ChatGPT',
            'ClaudeBot'       => 'Anthropic Claude',
            'Google-Extended' => 'Google Gemini (Extended)',
            'PerplexityBot'   => 'Perplexity AI',
            'OAI-SearchBot'   => 'OpenAI SearchBot',
            'cohere-ai'       => 'Cohere AI'
        );

        foreach ( $bots as $key => $name ) {
            if ( stripos( $user_agent, $key ) !== false ) {
                $this->log_bot_hit( $name );
                break;
            }
        }
    }

    private function log_bot_hit( $bot_name ) {
        // Use a unique ID for the site - for now we'll use the domain
        $site_id = parse_url( get_site_url(), PHP_URL_HOST );
        $path = $_SERVER['REQUEST_URI'];

        // Async request to Node API
        wp_remote_post( $this->api_url, array(
            'blocking'    => false,
            'timeout'     => 1,
            'redirection' => 0,
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'body'        => wp_json_encode( array(
                'site_id'      => $site_id,
                'bot_name'     => $bot_name,
                'request_path' => $path,
                'status_code'  => http_response_code() ?: 200
            ) )
        ) );
    }
}

new Gleo_Tracking();
