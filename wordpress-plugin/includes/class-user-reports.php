<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ZLL_User_Reports {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Called by activation hook in main plugin file
    public static function activate_plugin() {
        self::get_instance()->create_reports_table();
    }

    private function __construct() {
        add_action('show_user_profile', array($this, 'report_user_button'));
        add_action('edit_user_profile', array($this, 'report_user_button'));
        add_action('init', array($this, 'handle_report_submission'));
        add_action('admin_menu', array($this, 'add_reports_admin_page'));
        add_shortcode('zll_report_user', array($this, 'frontend_report_form'));
    }

    // Create the reports table
    public function create_reports_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'zll_user_reports';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            reported_user_id BIGINT NOT NULL,
            reporter_user_id BIGINT NOT NULL,
            reason TEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Show the "Report User" button on user profiles (admin area)
    public function report_user_button($user) {
        if (get_current_user_id() && get_current_user_id() != $user->ID) {
            ?>
            <form method="post" style="margin-top:1em;">
                <input type="hidden" name="zll_report_user_id" value="<?php echo esc_attr($user->ID); ?>">
                <input type="text" name="zll_report_reason" placeholder="Reason for report" required style="width:300px;">
                <?php wp_nonce_field('zll_report_user', 'zll_report_user_nonce'); ?>
                <button type="submit" class="button button-danger">Report User</button>
            </form>
            <?php
            if (isset($_GET['report']) && $_GET['report'] === 'success') {
                echo '<div class="notice notice-success"><p>User reported successfully!</p></div>';
            }
        }
    }

    // Front-end report form via shortcode [zll_report_user]
    public function frontend_report_form($atts) {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to report a user.</p>';
        }

        $current_user_id = get_current_user_id();

        // Fetch all users except the current user
        $users = get_users(array(
            'exclude' => array($current_user_id),
            'meta_key' => 'zll_discord_username',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'fields' => array('ID')
        ));

        if (empty($users)) {
            return '<p>No users available to report.</p>';
        }

        ob_start();
        ?>
        <style>
        .zll-discord-form {
            background: #313338;
            border-radius: 8px;
            padding: 24px 32px;
            max-width: 400px;
            color: #fff;
            font-family: 'gg sans', 'Segoe UI', 'Arial', sans-serif;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            margin-bottom: 2em;
        }
        .zll-discord-form label {
            display: block;
            margin-bottom: 8px;
            color: #b5bac1;
            font-size: 15px;
            font-weight: 500;
        }
        .zll-discord-form select,
        .zll-discord-form input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 16px;
            border: none;
            border-radius: 4px;
            background: #232428;
            color: #fff;
            font-size: 15px;
            transition: background 0.2s;
        }
        .zll-discord-form select:focus,
        .zll-discord-form input[type="text"]:focus {
            outline: none;
            background: #383a40;
        }
        .zll-discord-form button {
            background: #5865f2;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 10px 22px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 8px;
        }
        .zll-discord-form button:hover {
            background: #4752c4;
        }
        .zll-discord-form .notice-success {
            background: #248046;
            color: #fff;
            border-radius: 4px;
            padding: 8px 12px;
            margin-top: 16px;
            font-size: 14px;
        }
        </style>
        <form method="post" class="zll-discord-form">
            <label for="zll_report_user_id">Select user to report</label>
            <select name="zll_report_user_id" id="zll_report_user_id" required>
                <option value="">-- Select User --</option>
                <?php
                foreach ($users as $user) {
                    $discord_username = get_user_meta($user->ID, 'zll_discord_username', true);
                    $discord_discriminator = get_user_meta($user->ID, 'zll_discord_discriminator', true);
                    $display = $discord_username ? esc_html($discord_username) : esc_html(get_userdata($user->ID)->user_login);
                    if ($discord_discriminator) {
                        $display .= '#' . esc_html($discord_discriminator);
                    }
                    echo '<option value="' . esc_attr($user->ID) . '">' . $display . '</option>';
                }
                ?>
            </select>
            <label for="zll_report_reason">Reason for report</label>
            <input type="text" name="zll_report_reason" id="zll_report_reason" placeholder="Reason for report" required>
            <?php wp_nonce_field('zll_report_user', 'zll_report_user_nonce'); ?>
            <button type="submit">Report User</button>
            <?php
            if (isset($_GET['report']) && $_GET['report'] === 'success') {
                echo '<div class="notice-success"><p>User reported successfully!</p></div>';
            }
            ?>
        </form>
        <?php
        return ob_get_clean();
    }

    // Handle report submissions (admin and front-end)
    public function handle_report_submission() {
        if (
            isset($_POST['zll_report_user_id'], $_POST['zll_report_reason'], $_POST['zll_report_user_nonce']) &&
            wp_verify_nonce($_POST['zll_report_user_nonce'], 'zll_report_user')
        ) {
            global $wpdb;
            $table = $wpdb->prefix . 'zll_user_reports';
            $reported_user_id = intval($_POST['zll_report_user_id']);
            $reporter_user_id = get_current_user_id();
            $reason = sanitize_text_field($_POST['zll_report_reason']);
            $wpdb->insert($table, [
                'reported_user_id' => $reported_user_id,
                'reporter_user_id' => $reporter_user_id,
                'reason' => $reason,
                'status' => 'open',
            ]);
            // Optional: Notify admin via email or Discord webhook here
            wp_redirect(add_query_arg('report', 'success', $_SERVER['REQUEST_URI']));
            exit;
        }

        // Handle admin actions: resolve or ban
        if (
            is_admin() &&
            isset($_POST['zll_report_action'], $_POST['zll_report_id'], $_POST['_wpnonce']) &&
            in_array($_POST['zll_report_action'], array('resolve', 'ban')) &&
            current_user_can('manage_options') &&
            wp_verify_nonce($_POST['_wpnonce'], 'zll_report_action_' . intval($_POST['zll_report_id']))
        ) {
            global $wpdb;
            $table = $wpdb->prefix . 'zll_user_reports';
            $report_id = intval($_POST['zll_report_id']);
            $action = $_POST['zll_report_action'];
            $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $report_id));
            if ($report) {
                if ($action === 'resolve') {
                    $wpdb->update($table, array('status' => 'resolved'), array('id' => $report_id));
                } elseif ($action === 'ban') {
                    // Set report status to banned
                    $wpdb->update($table, array('status' => 'banned'), array('id' => $report_id));
                    // Ban the user (set their role to 'banned', create role if needed)
                    $user = get_userdata($report->reported_user_id);
                    if ($user) {
                        if (!get_role('banned')) {
                            add_role('banned', 'Banned');
                        }
                        $user->set_role('banned');
                    }
                }
            }
            wp_redirect(admin_url('admin.php?page=zll-user-reports'));
            exit;
        }
    }

    // Add the admin page for reports
    public function add_reports_admin_page() {
        add_menu_page(
            'User Reports',
            'User Reports',
            'manage_options',
            'zll-user-reports',
            array($this, 'render_reports_page'),
            'dashicons-flag',
            31
        );
    }

    // Render the admin reports page with actions
    public function render_reports_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'zll_user_reports';
        $reports = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        ?>
        <div class="wrap">
            <h1>User Reports</h1>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Reported User</th>
                        <th>Reporter</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reports as $report): ?>
                    <tr>
                        <td><?php echo esc_html($report->id); ?></td>
                        <td>
                            <?php
                            $reported = get_userdata($report->reported_user_id);
                            echo $reported ? esc_html($reported->user_login) : 'Unknown';
                            ?>
                        </td>
                        <td>
                            <?php
                            $reporter = get_userdata($report->reporter_user_id);
                            echo $reporter ? esc_html($reporter->user_login) : 'Unknown';
                            ?>
                        </td>
                        <td><?php echo esc_html($report->reason); ?></td>
                        <td><?php echo esc_html($report->status); ?></td>
                        <td><?php echo esc_html($report->created_at); ?></td>
                        <td>
                            <?php if ($report->status === 'open'): ?>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('zll_report_action_' . $report->id); ?>
                                    <input type="hidden" name="zll_report_id" value="<?php echo esc_attr($report->id); ?>">
                                    <input type="hidden" name="zll_report_action" value="resolve">
                                    <button type="submit" class="button">Resolve</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('zll_report_action_' . $report->id); ?>
                                    <input type="hidden" name="zll_report_id" value="<?php echo esc_attr($report->id); ?>">
                                    <input type="hidden" name="zll_report_action" value="ban">
                                    <button type="submit" class="button button-danger" onclick="return confirm('Are you sure you want to ban this user?')">Ban User</button>
                                </form>
                            <?php else: ?>
                                <em>No actions</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

// Initialize the class (add this to your plugin's main file or loader)
ZLL_User_Reports::get_instance();
