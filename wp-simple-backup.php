<?php
/**
 * Plugin Name: WP Simple Backup
 * Plugin URI:  https://github.com/dkerasiotis/backup_plugin
 * Description: Manual, selective backup of your WordPress site — database, files, posts, and plugins.
 * Version:     1.0.0
 * Author:      WP Simple Backup
 * License:     GPL-2.0+
 * Text Domain: wp-simple-backup
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WPSB_VERSION',    '1.0.0' );
define( 'WPSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPSB_BACKUP_DIR', WP_CONTENT_DIR . '/uploads/wp-simple-backup/' );
define( 'WPSB_BACKUP_URL', WP_CONTENT_URL . '/uploads/wp-simple-backup/' );

// Autoload includes
$wpsb_includes = array(
    'includes/class-backup-security.php',
    'includes/class-backup-logger.php',
    'includes/class-backup-database.php',
    'includes/class-backup-files.php',
    'includes/class-backup-posts.php',
    'includes/class-backup-plugins.php',
    'includes/class-backup-archiver.php',
    'includes/class-backup-manager.php',
    'admin/class-admin-page.php',
    'admin/class-admin-ajax.php',
);
foreach ( $wpsb_includes as $file ) {
    require_once WPSB_PLUGIN_DIR . $file;
}

// Activation hook
register_activation_hook( __FILE__, 'wpsb_activate' );
function wpsb_activate() {
    // Check ZipArchive
    if ( ! class_exists( 'ZipArchive' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'WP Simple Backup requires the ZipArchive PHP extension. Please enable it on your server.', 'wp-simple-backup' ),
            esc_html__( 'Plugin Activation Error', 'wp-simple-backup' ),
            array( 'back_link' => true )
        );
    }

    // Create backup storage directory
    if ( ! file_exists( WPSB_BACKUP_DIR ) ) {
        wp_mkdir_p( WPSB_BACKUP_DIR );
    }

    // Write .htaccess to block direct access (Apache)
    $htaccess = WPSB_BACKUP_DIR . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
    }

    // Write silence index.php
    $index = WPSB_BACKUP_DIR . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }

    add_option( 'wpsb_version', WPSB_VERSION );
}

// Deactivation hook
register_deactivation_hook( __FILE__, 'wpsb_deactivate' );
function wpsb_deactivate() {
    // Remove any scheduled single events
    wp_clear_scheduled_hook( 'wpsb_run_backup' );
}

// WP-Cron: async backup execution
add_action( 'wpsb_run_backup', 'wpsb_cron_run_backup', 10, 2 );
function wpsb_cron_run_backup( $backup_id, $components ) {
    $manager = new WPSB_Backup_Manager();
    $manager->run( $backup_id, $components );
}

// Cleanup old backup files daily
add_action( 'admin_init', 'wpsb_maybe_cleanup' );
function wpsb_maybe_cleanup() {
    $last = get_transient( 'wpsb_last_cleanup' );
    if ( false === $last ) {
        $archiver = new WPSB_Backup_Archiver();
        $archiver->cleanup_old_backups( 24 );
        set_transient( 'wpsb_last_cleanup', time(), HOUR_IN_SECONDS * 12 );
    }
}

// Boot admin functionality
add_action( 'plugins_loaded', 'wpsb_boot' );
function wpsb_boot() {
    if ( is_admin() ) {
        new WPSB_Admin_Page();
        new WPSB_Admin_Ajax();
    }
}
