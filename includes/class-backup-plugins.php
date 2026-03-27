<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Backup_Plugins {

    /** @var WPSB_Backup_Files */
    private $files_handler;

    public function __construct() {
        $this->files_handler = new WPSB_Backup_Files();
    }

    /**
     * Add the plugins directory to a ZipArchive and prepend a manifest file.
     *
     * @param  ZipArchive $zip Open ZipArchive instance.
     * @return true|WP_Error
     */
    public function backup( ZipArchive $zip ) {
        // Write manifest first
        $manifest = $this->build_manifest();
        $zip->addFromString( 'plugins-manifest.txt', $manifest );

        // Add the plugins directory
        $result = $this->files_handler->add_directory_to_zip(
            $zip,
            WP_PLUGIN_DIR,
            'wordpress/wp-content/plugins/',
            array() // no extra exclusions beyond defaults
        );

        return $result;
    }

    /**
     * Build a text manifest listing all installed plugins with status and version.
     *
     * @return string
     */
    private function build_manifest() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', array() );

        $lines   = array();
        $lines[] = 'WP Simple Backup — Plugins Manifest';
        $lines[] = 'Generated: ' . date( 'Y-m-d H:i:s' );
        $lines[] = 'Site: ' . get_bloginfo( 'url' );
        $lines[] = str_repeat( '-', 60 );

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $status   = in_array( $plugin_file, $active_plugins, true ) ? 'ACTIVE' : 'inactive';
            $name     = $plugin_data['Name'];
            $version  = $plugin_data['Version'];
            $lines[]  = sprintf( '[%s] %s v%s  (%s)', $status, $name, $version, $plugin_file );
        }

        $lines[] = str_repeat( '-', 60 );
        $lines[] = 'Total plugins: ' . count( $all_plugins );
        $lines[] = 'Active plugins: ' . count( array_intersect( array_keys( $all_plugins ), $active_plugins ) );

        return implode( "\n", $lines ) . "\n";
    }
}
