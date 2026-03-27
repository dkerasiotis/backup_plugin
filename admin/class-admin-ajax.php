<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Admin_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_wpsb_start_backup',    array( $this, 'handle_start_backup' ) );
        add_action( 'wp_ajax_wpsb_check_progress',  array( $this, 'handle_check_progress' ) );
        add_action( 'wp_ajax_wpsb_download_backup', array( $this, 'handle_download' ) );
        // No nopriv variants — admin-only
    }

    /**
     * AJAX: Start a new backup.
     * Validates input, schedules a WP-Cron single event, returns the backup_id immediately.
     */
    public function handle_start_backup() {
        WPSB_Backup_Security::verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'wpsb_backup_action'
        );
        WPSB_Backup_Security::check_capability();

        $raw_components = isset( $_POST['components'] ) ? (array) $_POST['components'] : array();
        $components     = WPSB_Backup_Security::sanitize_components( $raw_components );

        if ( empty( $components ) ) {
            wp_send_json_error( array( 'message' => 'No valid components selected.' ) );
        }

        $backup_id = 'wpsb_' . uniqid( '', true );

        // Initialise logger so polling can start immediately
        $manager = new WPSB_Backup_Manager();
        WPSB_Backup_Logger::init( $backup_id, $manager->estimate_steps( $components ) + 1 );
        WPSB_Backup_Logger::update( $backup_id, 0, 'Queued — starting soon…' );

        // Schedule async execution via WP-Cron
        wp_schedule_single_event( time(), 'wpsb_run_backup', array( $backup_id, $components ) );
        spawn_cron();

        wp_send_json_success( array( 'backup_id' => $backup_id ) );
    }

    /**
     * AJAX: Poll the progress of a running backup.
     */
    public function handle_check_progress() {
        WPSB_Backup_Security::verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'wpsb_backup_action'
        );
        WPSB_Backup_Security::check_capability();

        $raw_id    = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
        $backup_id = WPSB_Backup_Security::sanitize_backup_id( $raw_id );

        if ( false === $backup_id ) {
            wp_send_json_error( array( 'message' => 'Invalid backup ID.' ) );
        }

        $progress = WPSB_Backup_Logger::get( $backup_id );
        if ( false === $progress ) {
            wp_send_json_error( array( 'message' => 'Backup not found or expired.' ) );
        }

        wp_send_json_success( $progress );
    }

    /**
     * AJAX: Stream the backup ZIP file to the browser.
     * Uses GET parameters (linked from admin page) with its own nonce.
     */
    public function handle_download() {
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpsb_backup_action' ) ) {
            wp_die( 'Security check failed.', 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.', 403 );
        }

        $raw_id    = isset( $_GET['backup_id'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_id'] ) ) : '';
        $backup_id = WPSB_Backup_Security::sanitize_backup_id( $raw_id );

        if ( false === $backup_id ) {
            wp_die( 'Invalid backup ID.' );
        }

        $zip_path = WPSB_BACKUP_DIR . 'backup-' . $backup_id . '.zip';

        $archiver = new WPSB_Backup_Archiver();
        $archiver->stream_download( $zip_path, $backup_id );
    }
}
