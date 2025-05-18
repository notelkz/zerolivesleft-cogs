<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZLL_OAuth {

    private static $instance = null;

    // Discord OAuth2 credentials (replace with your actual values)
    private $client_id     = '1314683522082148442';
    private $client_secret = 'uWoognQ06iL3m00cV-cu63XhZAgDKYN4';
    private $redirect_uri  = '';
    private $authorize_url = 'https://discord.com/api/oauth2/authorize';
    private $token_url     = 'https://discord.com/api/oauth2/token';
    private $user_url      = 'https://discord.com/api/users/@me';

    private function __construct() {
        $this->redirect_uri = site_url( '/zll-discord-callback' );

        // Register login URL
        add_action( 'init', array( $this, 'register_oauth_endpoint' ) );
        add_action( 'template_redirect', array( $this, 'handle_oauth_callback' ) );

        // Add a login button (example: on login page)
        add_action( 'login_form', array( $this, 'add_discord_login_button' ) );
    }

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_oauth_endpoint() {
        add_rewrite_rule( '^zll-discord-callback/?', 'index.php?zll_discord_callback=1', 'top' );
        add_rewrite_tag( '%zll_discord_callback%', '1' );
    }

    public function handle_oauth_callback() {
        if ( get_query_var( 'zll_discord_callback' ) ) {
            // Step 1: Get code from Discord
            if ( isset( $_GET['code'] ) ) {
                $code = sanitize_text_field( $_GET['code'] );
                // Step 2: Exchange code for access token
                $token = $this->get_access_token( $code );
                if ( $token ) {
                    // Step 3: Get user info from Discord
                    $user = $this->get_discord_user( $token );
                    if ( $user ) {
                        // Step 4: Log in or register WP user
                        $this->login_or_register_user( $user );
                    }
                }
            }
            // Redirect to home or dashboard
            wp_redirect( home_url() );
            exit;
        }
    }

    public function add_discord_login_button() {
        $url = $this->get_authorize_url();
        echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Log in with Discord</a></p>';
    }

    private function get_authorize_url() {
        $params = array(
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'identify email guilds'
        );
        return $this->authorize_url . '?' . http_build_query( $params );
    }

    private function get_access_token( $code ) {
        $response = wp_remote_post( $this->token_url, array(
            'body' => array(
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirect_uri,
                'scope'         => 'identify email guilds'
            )
        ) );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['access_token'] ) ? $body['access_token'] : false;
    }

    private function get_discord_user( $access_token ) {
        $response = wp_remote_get( $this->user_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            )
        ) );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['id'] ) ? $body : false;
    }

    private function login_or_register_user( $discord_user ) {
        $discord_id = $discord_user['id'];
        $email      = isset( $discord_user['email'] ) ? $discord_user['email'] : $discord_id . '@discord.local';
        $username   = isset( $discord_user['username'] ) ? $discord_user['username'] : 'discord_' . $discord_id;

        // Try to find user by Discord ID (stored in usermeta)
        $user_query = new WP_User_Query( array(
            'meta_key'    => 'zll_discord_id',
            'meta_value'  => $discord_id,
            'number'      => 1
        ) );
        $users = $user_query->get_results();

        if ( ! empty( $users ) ) {
            // User exists, log them in
            $user_id = $users[0]->ID;
        } else {
            // Register new user (or find by email)
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $user_id = $user->ID;
            } else {
                $random_password = wp_generate_password( 12, false );
                $user_id = wp_create_user( $username, $random_password, $email );
            }
            // Store Discord ID in usermeta
            update_user_meta( $user_id, 'zll_discord_id', $discord_id );
        }

        // Log in the user
        wp_set_auth_cookie( $user_id, true );
        wp_set_current_user( $user_id );
        do_action( 'wp_login', $username, get_user_by( 'id', $user_id ) );
    }
}
