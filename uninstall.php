<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin option
delete_option( 'wpsb_version' );

// Clear any leftover transients
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpsb_%' OR option_name LIKE '_transient_timeout_wpsb_%'" );

// Remove backup storage directory
$backup_dir = WP_CONTENT_DIR . '/uploads/wp-simple-backup/';
if ( is_dir( $backup_dir ) ) {
    wpsb_uninstall_rmdir( $backup_dir );
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir
 */
function wpsb_uninstall_rmdir( $dir ) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $items as $item ) {
        if ( $item->isDir() ) {
            @rmdir( $item->getRealPath() );
        } else {
            @unlink( $item->getRealPath() );
        }
    }
    @rmdir( $dir );
}
