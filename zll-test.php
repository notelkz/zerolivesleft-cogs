<?php
/*
Plugin Name: ZLL Test Endpoint
*/

add_action('init', function() {
    add_rewrite_rule('^zll-discord-callback/?', 'index.php?zll_discord_callback=1', 'top');
    add_rewrite_tag('%zll_discord_callback%', '1');
});

add_action('template_redirect', function() {
    if (get_query_var('zll_discord_callback')) {
        die('ZLL Test Endpoint Works!');
    }
});
