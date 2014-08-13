<?php
/*
Plugin Name: VersionPress
Plugin URI: http://versionpress.net/
Description: Git-versioning plugin for WordPress
Author: Agilio
Version: 1.0
*/

defined('ABSPATH') or die("Direct access not allowed");

register_activation_hook(__FILE__, 'versionpress_activate');
register_deactivation_hook(__FILE__, 'versionpress_deactivate');
add_action('admin_post_deactivation_canceled', 'versionpress_admin_post_deactivation_canceled');
add_action('admin_post_deactivation_keep_repo', 'versionpress_admin_post_deactivation_keep_repo');
// uninstallation is handler in uninstall.php

add_action( 'admin_menu', 'versionpress_admin_menu');

if(isActive()) {
    registerHooks();
}

function registerHooks() {
    global $wpdb, $versionPressContainer;
    $storageFactory = $versionPressContainer->resolve(VersionPressServices::STORAGE_FACTORY);
    $committer = $versionPressContainer->resolve(VersionPressServices::COMMITTER);

    /**
     *  Hook for saving taxonomies into files
     *  WordPress creates plain INSERT query and executes it using wpdb::query method instead of wpdb::insert.
     *  It's too difficult to parse every INSERT query, that's why the WordPress hook is used.
     */
    add_action('save_post', createUpdatePostTermsHook($storageFactory->getStorage('posts'), $wpdb));

    add_filter('update_feedback', function () {
        touch(get_home_path() . 'versionpress.maintenance');
    });
    add_action('_core_updated_successfully', function () use ($committer) {
        require(get_home_path() . '/wp-includes/version.php'); // load constants (like $wp_version)
        /** @var string $wp_version */
        $changeInfo = new WordPressUpdateChangeInfo($wp_version);
        $committer->forceChangeInfo($changeInfo);
    });

    add_action('activated_plugin', function ($pluginName) use ($committer) {
        $committer->forceChangeInfo(new PluginChangeInfo($pluginName, 'activate'));
    });

    add_action('deactivated_plugin', function ($pluginName) use ($committer) {
        $committer->forceChangeInfo(new PluginChangeInfo($pluginName, 'deactivate'));
    });

    add_action('upgrader_process_complete', function ($upgrader, $hook_extra) use ($committer) {
        if ($hook_extra['type'] == 'core' && $hook_extra['action'] == 'update') return; // handled by different hook
        $pluginName = $hook_extra['plugin'];
        $committer->forceChangeInfo(new PluginChangeInfo($pluginName, 'update'));
    }, 10, 2);

    register_shutdown_function(array($committer, 'commit'));
}

function createUpdatePostTermsHook(EntityStorage $storage, wpdb $wpdb) {

    return function ($postId) use ($storage, $wpdb) {
        $post = get_post($postId);
        $postType = $post->post_type;
        $taxonomies = get_object_taxonomies($postType);

        $vpIdTableName = $wpdb->prefix . 'vp_id';

        $postVpId = $wpdb->get_var("SELECT HEX(vp_id) FROM $vpIdTableName WHERE id = $postId AND `table` = 'posts'");

        $postUpdateData = array('vp_id' => $postVpId);

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);
            if ($terms)
                $postUpdateData[$taxonomy] = array_map(function ($term) use ($wpdb, $vpIdTableName) {
                    return $wpdb->get_var("SELECT HEX(vp_id) FROM $vpIdTableName WHERE id = {$term->term_id} AND `table` = 'terms'");
                }, $terms);
        }

        if (count($taxonomies) > 0)
            $storage->save($postUpdateData);
    };
}

//----------------------------------
// Activation and deactivation
//----------------------------------

function versionpress_activate() {
    copy(dirname(__FILE__) . '/_db.php', WP_CONTENT_DIR . '/db.php');
}

/**
 * Deactivation is a two-step process with a warning screen. See
 * `versionpress_admin_post_deactivation_canceled()` and `versionpress_admin_post_deactivation_keep_repo()`
 */
function versionpress_deactivate() {


    $deactivatePath = 'admin.php?page=versionpress/administration/deactivate.php';
    $deactivateUrl = admin_url($deactivatePath);

    wp_redirect($deactivateUrl);
    die();
}

/**
 * Handler of situation where user canceled the deactivation
 */
function versionpress_admin_post_deactivation_canceled() {
    wp_redirect(admin_url('plugins.php'));
}

/**
 * Handler of situation where user confirmed the deactivation. Most
 * of the actual work is done here.
 */
function versionpress_admin_post_deactivation_keep_repo() {

    unlink(WP_CONTENT_DIR . '/db.php');
    unlink(__DIR__ . '/.active');

    FileSystem::getWpFilesystem()->rmdir(__DIR__ . '/db');

    global $wpdb;

    $table_prefix = $wpdb->prefix;

    $queries[] = "DROP VIEW IF EXISTS `{$table_prefix}vp_reference_details`";
    $queries[] = "DROP TABLE IF EXISTS `{$table_prefix}vp_references`";
    $queries[] = "DROP TABLE IF EXISTS `{$table_prefix}vp_id`";

    foreach ($queries as $query) {
        $wpdb->query($query);
    }


    deactivate_plugins("versionpress/versionpress.php", true);
    wp_redirect(admin_url("plugins.php"));

}

function isActive() {
    return defined('VERSIONPRESS_PLUGIN_DIR') && file_exists(VERSIONPRESS_PLUGIN_DIR . '/.active');
}

function versionpress_admin_menu() {
    add_menu_page(
        'VersionPress',
        'VersionPress',
        'manage_options',
        'versionpress/administration/index.php',
        '',
        null,
        0.001234987
    );

    if(isActive())
        add_submenu_page(
            'versionpress/administration/index.php',
            'Synchronization',
            'Synchronization',
            'manage_options',
            'versionpress/administration/sync.php'
        );

    // Support for deactivate.php - add it to the internal $_registered_pages array
    // See e.g. http://blog.wpessence.com/wordpress-admin-page-without-menu-item/
    global $_registered_pages;
    $menu_slug = plugin_basename("versionpress/administration/deactivate.php");
    $hookname = get_plugin_page_hookname( $menu_slug, '' );
    $_registered_pages[$hookname] = true;

}


