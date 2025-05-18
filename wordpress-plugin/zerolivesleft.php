<?php
/*
Plugin Name: ZeroLivesLeft Discord Role Sync
Description: Syncs Discord roles to WordPress, handles bans, user reports, and sets inactive users.
Version: 1.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ZLL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once ZLL_PLUGIN_DIR . 'includes/class-oauth.php';
require_once ZLL_PLUGIN_DIR . 'includes/class-moderation.php';
require_once ZLL_PLUGIN_DIR . 'includes/class-user-reports.php';

// --- 1. Add the Inactive role on plugin activation ---
register_activation_hook(__FILE__, function() {
    if ( ! get_role( 'inactive' ) ) {
        add_role( 'inactive', 'Inactive' );
    }
    ZLL_User_Reports::activate_plugin(); // Activate user reports
});

// --- 2. Schedule the background sync ---
if ( ! wp_next_scheduled( 'zll_discord_roles_cron' ) ) {
    wp_schedule_event( time(), 'daily', 'zll_discord_roles_cron' ); // Sync hourly
}
add_action( 'zll_discord_roles_cron', array( 'ZLL_Discord_Role_Sync', 'background_sync_all_users' ) );

// --- 3. Unschedule on plugin deactivation ---
register_deactivation_hook( __FILE__, function() {
    $timestamp = wp_next_scheduled( 'zll_discord_roles_cron' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'zll_discord_roles_cron' );
    }
});

// --- Initialize classes ---
ZLL_Discord_Role_Sync::get_instance();
ZLL_Moderation::get_instance();
ZLL_User_Reports::get_instance();

// --- Show Discord info in WP Admin user profile ---
add_action( 'show_user_profile', 'zll_show_discord_info' );
add_action( 'edit_user_profile', 'zll_show_discord_info' );

function zll_show_discord_info( $user ) {
    $discord_id    = get_user_meta( $user->ID, 'zll_discord_id', true );
    $discord_user  = get_user_meta( $user->ID, 'zll_discord_username', true );
    $discriminator = get_user_meta( $user->ID, 'zll_discord_discriminator', true );
    $avatar        = get_user_meta( $user->ID, 'zll_discord_avatar', true );

    if ( $discord_id ) {
        $avatar_url = $avatar
            ? 'https://cdn.discordapp.com/avatars/' . $discord_id . '/' . $avatar . '.png'
            : '';

        $oauth = ZLL_Discord_Role_Sync::get_instance();
        $discord_roles = $oauth->get_discord_member_roles( $discord_id );
        $all_discord_roles = $oauth->get_discord_guild_roles_list();

        $role_info = array();
        if ( is_array( $all_discord_roles ) ) {
            foreach ( $all_discord_roles as $role ) {
                if ( is_array( $role ) && isset( $role['id'] ) ) {
                    $role_info[$role['id']] = array(
                        'name'  => $role['name'] ?? 'Unknown Role',
                        'color' => $role['color'] ? sprintf( '#%06X', $role['color'] ) : '#36393f'
                    );
                }
            }
        }

        echo '<h3>Discord Info</h3>';
        echo '<table class="form-table">';
        echo '<tr><th>Discord ID</th><td>' . esc_html( $discord_id ) . '</td></tr>';
        echo '<tr><th>Username</th><td>' . esc_html( $discord_user );
        if ( $discriminator ) {
            echo '#' . esc_html( $discriminator );
        }
        echo '</td></tr>';
        if ( $avatar_url ) {
            echo '<tr><th>Avatar</th><td><img src="' . esc_url( $avatar_url ) . '" width="64" height="64" style="border-radius:32px;"></td></tr>';
        }

        echo '<tr><th>Discord Roles</th><td>';
        if ( ! empty( $discord_roles ) && is_array( $discord_roles ) ) {
            echo '<ul class="discord-roles" style="margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 4px;">';
            foreach ( $discord_roles as $role_id ) {
                if ( isset( $role_info[$role_id] ) ) {
                    $style = sprintf( 'background-color: %s; display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; color: #fff;', esc_attr( $role_info[$role_id]['color'] ) );
                    echo '<li class="discord-role" style="' . $style . '">' . esc_html( $role_info[$role_id]['name'] ) . '</li>';
                }
            }
            echo '</ul>';
        } else {
            echo 'No Discord roles found';
        }
        echo '</td></tr>';

        echo '<tr><th>WordPress Roles</th><td>';
        $wp_roles = $user->roles;
        if ( ! empty( $wp_roles ) ) {
            echo '<ul class="wp-roles" style="margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 4px;">';
            foreach ( $wp_roles as $role ) {
                $style = 'background-color: #5865F2; display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; color: #fff;';
                echo '<li class="wp-role" style="' . $style . '">' . esc_html( $role ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo 'No WordPress roles assigned';
        }
        echo '</td></tr>';

        echo '</table>';
    }
}
