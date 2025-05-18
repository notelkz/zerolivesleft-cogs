<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ZLL_Discord_Role_Sync {

    private static $instance = null;

    private $client_id;
    private $client_secret;
    private $bot_token;
    private $guild_id;
    private $redirect_uri;
    private $authorize_url = 'https://discord.com/api/oauth2/authorize';
    private $token_url    = 'https://discord.com/api/oauth2/token';
    private $user_url     = 'https://discord.com/api/users/@me';
    private $after_login_redirect = 'https://zerolivesleft.net/index.php';

    private function __construct() {
        $this->client_id     = getenv('DISCORD_CLIENT_ID');
        $this->client_secret = getenv('DISCORD_CLIENT_SECRET');
        $this->bot_token     = getenv('DISCORD_BOT_TOKEN');
        $this->guild_id      = getenv('DISCORD_GUILD_ID');

        $this->redirect_uri = site_url( '/discord-callback' );

        if (!empty($this->bot_token) && !empty($this->guild_id)) {
            $this->ensure_discord_roles_exist();
        }

        add_action( 'init', array( $this, 'register_oauth_endpoint' ) );
        add_action( 'template_redirect', array( $this, 'handle_oauth_callback' ) );
        add_action( 'login_form', array( $this, 'add_discord_login_button' ) );
        add_action( 'admin_head', array( $this, 'add_discord_roles_css' ) );
    }

    private function ensure_discord_roles_exist() {
        $discord_roles = $this->get_discord_guild_roles_list();

        if (!empty($discord_roles) && is_array($discord_roles)) {
            foreach ($discord_roles as $role) {
                if (!is_array($role) || !isset($role['name'])) {
                    continue;
                }

                $role_slug = sanitize_title($role['name']);
                if (!get_role($role_slug)) {
                    add_role($role_slug, $role['name']);
                    error_log("Created WordPress role: {$role['name']}");
                }
            }
        }
    }

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_bot_token() {
        return $this->bot_token;
    }

    public function get_guild_id() {
        return $this->guild_id;
    }

    public function add_discord_roles_css() { ?>
        <style>
        /* Styles for Discord roles display */
        .discord-roles {
            display: flex;
            flex-wrap: wrap;
            gap: 4px; /* Adjust spacing between roles */
            margin: 0;
            padding: 0;
            list-style: none; /* Remove bullet points */
        }

        .discord-role {
            display: inline-block;
            padding: 2px 6px; /* Adjust padding within role labels */
            border-radius: 3px; /* Slightly rounded corners */
            font-size: 13px; /* Slightly smaller font size */
            font-weight: 500;
            color: #fff; /* White text */
            background-color: #36393f; /* Default Discord dark gray */
            margin: 0;
        }

        /* Styles for WordPress roles display */
        .wp-roles {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .wp-role {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
            font-weight: 500;
            color: #fff;
            background-color: #5865F2; /* Discord blurple color */
            margin: 0;
        }
        </style>
    <?php }


    public function register_oauth_endpoint() {
        add_rewrite_rule( '^discord-callback/?', 'index.php?zll_discord_callback=1', 'top' );
        add_rewrite_tag( '%zll_discord_callback%', '1' );
    }

    public function handle_oauth_callback() {
        if ( get_query_var( 'zll_discord_callback' ) ) {
            if ( isset( $_GET['code'] ) ) {
                $code = sanitize_text_field( $_GET['code'] );
                $token = $this->get_access_token( $code );
                if ( $token ) {
                    $user = $this->get_discord_user( $token );
                    if ( $user ) {
                        $can_login = apply_filters( 'zll_before_user_login', true, $user );
                        if ( $can_login ) {
                            $this->login_or_register_user( $user );
                        } else {
                            wp_redirect( wp_login_url() . '?login=failed&reason=banned' );
                            exit;
                        }
                    }
                }
            }
            wp_redirect( $this->after_login_redirect );
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
            error_log('Discord access token error: ' . $response->get_error_message());
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['access_token'] ?? false; // Use null coalescing operator
    }

    private function get_discord_user( $access_token ) {
        $response = wp_remote_get( $this->user_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            )
        ) );
        if ( is_wp_error( $response ) ) {
            error_log('Discord user fetch error: ' . $response->get_error_message());
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['id'] ) ? $body : false;
    }

    public function get_discord_guild_roles_list() {
        if (empty($this->bot_token) || empty($this->guild_id)) {
            error_log('Discord bot token or guild ID is missing');
            return array();
        }

        $url = "https://discord.com/api/guilds/{$this->guild_id}/roles";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bot ' . $this->bot_token
            )
        ));

        if (is_wp_error($response)) {
            error_log('Discord roles fetch error: ' . $response->get_error_message());
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            error_log('Empty response from Discord API');
            return array();
        }

        $roles = json_decode($body, true);
        if (!is_array($roles)) {
            error_log('Invalid response from Discord API: ' . $body);
            return array();
        }

        return $roles;
    }

    public function get_discord_member_roles( $discord_id ) {
        if (empty($this->bot_token) || empty($this->guild_id)) {
            error_log('Discord bot token or guild ID is missing');
            return array();
        }

        $url = "https://discord.com/api/guilds/{$this->guild_id}/members/{$discord_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bot ' . $this->bot_token
            )
        ));

        if (is_wp_error($response)) {
            error_log('Discord member roles fetch error: ' . $response->get_error_message());
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['roles']) && is_array($body['roles']) ? $body['roles'] : array();
    }

    private function login_or_register_user( $discord_user ) {
        if (!isset($discord_user['id'])) {
            error_log('No Discord ID found in user data');
            return false;
        }

        $discord_id    = $discord_user['id'];
        $username      = $discord_user['username'] ?? 'discord_' . $discord_id;
        $discriminator = $discord_user['discriminator'] ?? '';
        $avatar        = $discord_user['avatar'] ?? '';
        $email         = $discord_user['email'] ?? $discord_id . '@discord.local';

        error_log('Processing user: ' . $username . ' (Discord ID: ' . $discord_id . ')');

        $user_query = new WP_User_Query( array(
            'meta_key'    => 'zll_discord_id',
            'meta_value'  => $discord_id,
            'number'      => 1
        ) );
        $users = $user_query->get_results();

        if ( ! empty( $users ) ) {
            $user_id = $users[0]->ID;
            error_log('Found existing user with ID: ' . $user_id);
        } else {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $user_id = $user->ID;
                error_log('Found user by email with ID: ' . $user_id);
            } else {
                $random_password = wp_generate_password( 12, false );
                $user_id = wp_create_user( $username, $random_password, $email );

                if ( is_wp_error( $user_id ) ) {
                    error_log('Failed to create user: ' . $user_id->get_error_message());
                    return false;
                }
                error_log('Created new user with ID: ' . $user_id);
            }
        }

        update_user_meta( $user_id, 'zll_discord_id', $discord_id );
        update_user_meta( $user_id, 'zll_discord_username', $username );
        update_user_meta( $user_id, 'zll_discord_discriminator', $discriminator );
        update_user_meta( $user_id, 'zll_discord_avatar', $avatar );

        $discord_roles = $this->get_discord_member_roles( $discord_id );
        $all_discord_roles = $this->get_discord_guild_roles_list();

        error_log('Discord roles for user: ' . print_r($discord_roles, true));

        if ( !empty( $discord_roles ) && is_array($discord_roles) ) {
            $user = new WP_User( $user_id );

            $user->set_role(''); // Removes all roles

            foreach ( $all_discord_roles as $role ) {
                if (in_array($role['id'], $discord_roles)) {
                    $role_slug = sanitize_title($role['name']);
                    $role_name = $role['name'];

                    if (!get_role($role_slug)) {
                        add_role($role_slug, $role_name);
                        error_log("Created new WordPress role: {$role_name}");
                    }

                    $user->add_role($role_slug);
                    error_log("Added role {$role_name} to user {$username}");
                }
            }
        }

        wp_set_auth_cookie( $user_id, true );
        wp_set_current_user( $user_id );
        do_action( 'wp_login', $username, get_user_by( 'id', $user_id ) );

        return true;
    }

    public static function background_sync_all_users() {
        $instance = self::get_instance();

        $args = array(
            'meta_key'     => 'zll_discord_id',
            'meta_compare' => 'EXISTS',
            'number'       => -1,
            'fields'       => 'all',
        );
        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();

        if ( empty( $users ) ) {
            return;
        }

        foreach ( $users as $user ) {
            $discord_id = get_user_meta( $user->ID, 'zll_discord_id', true );
            if ( ! $discord_id ) {
                continue;
            }

            $discord_roles = $instance->get_discord_member_roles( $discord_id );

            if ( empty( $discord_roles ) ) {
                $wp_user = new WP_User( $user->ID );
                $wp_user->set_role( 'inactive' );
                error_log( "Set user {$user->user_login} (ID: {$user->ID}) to Inactive (not in Discord server)" );
            } else {
                $all_discord_roles = $instance->get_discord_guild_roles_list();
                $wp_user = new WP_User( $user->ID );
                $wp_user->set_role( '' ); // Remove all roles

                foreach ( $all_discord_roles as $role ) {
                    if ( in_array( $role['id'], $discord_roles ) ) {
                        $role_slug = sanitize_title( $role['name'] );
                        $role_name = $role['name'];
                        if ( ! get_role( $role_slug ) ) {
                            add_role( $role_slug, $role_name );
                        }
                        $wp_user->add_role( $role_slug );
                    }
                }
                error_log( "Synced Discord roles for user {$user->user_login} (ID: {$user->ID})" );
            }
        }
    }
}
