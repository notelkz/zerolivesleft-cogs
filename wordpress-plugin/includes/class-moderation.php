<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ZLL_Moderation {
    private static $instance = null;
    private $bot_token;
    private $guild_id;

    private function __construct() {
        // Get credentials from main plugin class
        $oauth = ZLL_Discord_Role_Sync::get_instance();
        $this->bot_token = $oauth->get_bot_token();
        $this->guild_id = $oauth->get_guild_id();

        // Add hooks
        add_action('admin_menu', array($this, 'add_moderation_menu'));
        add_action('admin_init', array($this, 'check_for_actions'));
        
        // Add ban check to login process
        add_filter('zll_before_user_login', array($this, 'check_user_ban_status'), 10, 2);
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Check if a Discord user is banned
    public function is_user_banned($discord_id) {
        if (empty($this->bot_token) || empty($this->guild_id) || empty($discord_id)) {
            return false;
        }

        $url = "https://discord.com/api/guilds/{$this->guild_id}/bans/{$discord_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bot ' . $this->bot_token
            )
        ));

        // If we get a 200 response, the user is banned
        return wp_remote_retrieve_response_code($response) === 200;
    }

    // Check ban status during login
    public function check_user_ban_status($can_login, $discord_user) {
        if (!isset($discord_user['id'])) {
            return $can_login;
        }

        // Check if user is banned on Discord
        if ($this->is_user_banned($discord_user['id'])) {
            // Store ban status in WordPress
            update_user_meta($discord_user['id'], 'zll_discord_banned', true);
            return false; // Prevent login
        }

        return $can_login;
    }

    // Add moderation menu to WordPress admin
    public function add_moderation_menu() {
        add_menu_page(
            'Discord Moderation',
            'Discord Mod',
            'manage_options',
            'zll-discord-moderation',
            array($this, 'render_moderation_page'),
            'dashicons-shield',
            30
        );
    }

    // Render moderation dashboard
    public function render_moderation_page() {
        // Check if user has permission
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get banned users
        $banned_users = $this->get_banned_users();

        ?>
        <div class="wrap">
            <h1>Discord Moderation</h1>

            <h2>Banned Users</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Discord ID</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (!empty($banned_users) && is_array($banned_users)) {
                        foreach ($banned_users as $ban) {
                            if (isset($ban['user'])) {
                                $user = $ban['user'];
                                $reason = isset($ban['reason']) ? $ban['reason'] : 'No reason provided';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($user['username'] ?? 'Unknown'); ?></td>
                                    <td><?php echo esc_html($user['id'] ?? 'Unknown'); ?></td>
                                    <td><?php echo esc_html($reason); ?></td>
                                    <td>
                                        <?php if (isset($user['id'])): ?>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('unban_user_' . $user['id']); ?>
                                            <input type="hidden" name="action" value="unban_user">
                                            <input type="hidden" name="user_id" value="<?php echo esc_attr($user['id']); ?>">
                                            <button type="submit" class="button button-secondary">Unban</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                    } else {
                        ?>
                        <tr>
                            <td colspan="4">No banned users found.</td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // Get list of banned users
    private function get_banned_users() {
        if (empty($this->bot_token) || empty($this->guild_id)) {
            return array();
        }

        $url = "https://discord.com/api/guilds/{$this->guild_id}/bans";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bot ' . $this->bot_token
            )
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching Discord bans: ' . $response->get_error_message());
            return array();
        }

        $bans = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($bans)) {
            error_log('Invalid response from Discord bans API');
            return array();
        }

        return $bans;
    }

    // Handle unban actions
    public function check_for_actions() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'unban_user') {
            return;
        }

        if (!isset($_POST['user_id']) || !wp_verify_nonce($_POST['_wpnonce'], 'unban_user_' . $_POST['user_id'])) {
            wp_die('Invalid request');
        }

        $discord_id = sanitize_text_field($_POST['user_id']);
        $this->unban_user($discord_id);

        wp_redirect(admin_url('admin.php?page=zll-discord-moderation&unbanned=1'));
        exit;
    }

    // Unban a user
    private function unban_user($discord_id) {
        if (empty($this->bot_token) || empty($this->guild_id)) {
            return false;
        }

        $url = "https://discord.com/api/guilds/{$this->guild_id}/bans/{$discord_id}";
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bot ' . $this->bot_token
            )
        ));

        if (!is_wp_error($response)) {
            // Remove ban status from WordPress
            $user = get_users(array(
                'meta_key' => 'zll_discord_id',
                'meta_value' => $discord_id,
                'number' => 1
            ));

            if (!empty($user)) {
                delete_user_meta($user[0]->ID, 'zll_discord_banned');
            }

            return true;
        }

        return false;
    }
}
