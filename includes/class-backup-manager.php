<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Backup_Manager {

    /**
     * Steps per component (for progress calculation).
     *
     * @var array
     */
    private $steps_per_component = array(
        'database' => 1,
        'posts'    => 1,
        'files'    => 2,
        'plugins'  => 1,
    );

    /**
     * Run the backup for the given components.
     *
     * @param string $backup_id  Unique backup identifier.
     * @param array  $components Selected backup components.
     */
    public function run( $backup_id, $components ) {
        // Capability check (redundant layer of security)
        if ( ! current_user_can( 'manage_options' ) ) {
            // Running via cron — skip capability check if no user context
            if ( ! did_action( 'wp_loaded' ) || ! is_user_logged_in() ) {
                // Allow cron execution
            } else {
                WPSB_Backup_Logger::fail( $backup_id, 'Permission denied.' );
                return;
            }
        }

        // Raise limits for large backups
        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '256M' );

        // Calculate total steps: components + 1 for finalising
        $total_steps = $this->estimate_steps( $components ) + 1;
        WPSB_Backup_Logger::init( $backup_id, $total_steps );
        WPSB_Backup_Logger::update( $backup_id, 0, 'Starting backup…' );

        $archiver = new WPSB_Backup_Archiver();
        $result   = $archiver->create( $backup_id, $components );

        if ( is_wp_error( $result ) ) {
            WPSB_Backup_Logger::fail( $backup_id, $result->get_error_message() );
            return;
        }

        // Build the download URL (authenticated AJAX endpoint)
        $download_nonce = wp_create_nonce( 'wpsb_download_action' );
        $download_url   = add_query_arg(
            array(
                'action'    => 'wpsb_download_backup',
                'backup_id' => rawurlencode( $backup_id ),
                'nonce'     => $download_nonce,
            ),
            admin_url( 'admin-ajax.php' )
        );

        WPSB_Backup_Logger::complete( $backup_id, $download_url );
    }

    /**
     * Calculate the total number of progress steps for the given components.
     *
     * @param  array $components
     * @return int
     */
    public function estimate_steps( $components ) {
        $total = 0;
        foreach ( $components as $component ) {
            $total += isset( $this->steps_per_component[ $component ] )
                ? $this->steps_per_component[ $component ]
                : 1;
        }
        return max( 1, $total );
    }
}
