<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Backup_Logger {

    const TTL = 3600; // 1 hour

    /**
     * Initialise a progress entry for a new backup.
     *
     * @param string $backup_id  Unique backup identifier.
     * @param int    $total_steps Total number of steps for progress calculation.
     */
    public static function init( $backup_id, $total_steps = 1 ) {
        $data = array(
            'status'      => 'starting',
            'step'        => 0,
            'total_steps' => max( 1, (int) $total_steps ),
            'message'     => __( 'Initialising backup…', 'wp-simple-backup' ),
            'log'         => array(),
            'started_at'  => time(),
        );
        set_transient( 'wpsb_progress_' . $backup_id, $data, self::TTL );
    }

    /**
     * Update progress after completing a step.
     *
     * @param string $backup_id Unique backup identifier.
     * @param int    $step      Current step number.
     * @param string $message   Human-readable status message.
     * @param string $status    'running', 'done', or 'error'.
     */
    public static function update( $backup_id, $step, $message, $status = 'running' ) {
        $data = self::get( $backup_id );
        if ( ! $data ) {
            $data = array(
                'status'      => $status,
                'step'        => $step,
                'total_steps' => $step,
                'log'         => array(),
                'started_at'  => time(),
            );
        }
        $data['status']  = $status;
        $data['step']    = (int) $step;
        $data['message'] = $message;
        $data['log'][]   = array(
            'time'    => date( 'H:i:s' ),
            'message' => $message,
        );
        set_transient( 'wpsb_progress_' . $backup_id, $data, self::TTL );
    }

    /**
     * Mark a backup as successfully completed.
     *
     * @param string $backup_id    Unique backup identifier.
     * @param string $download_url AJAX URL to download the resulting ZIP.
     */
    public static function complete( $backup_id, $download_url ) {
        $data = self::get( $backup_id );
        if ( ! $data ) {
            $data = array( 'log' => array(), 'total_steps' => 1, 'started_at' => time() );
        }
        $data['status']       = 'done';
        $data['step']         = $data['total_steps'];
        $data['message']      = __( 'Backup complete!', 'wp-simple-backup' );
        $data['download_url'] = $download_url;
        $data['log'][]        = array(
            'time'    => date( 'H:i:s' ),
            'message' => __( 'Backup complete! Ready for download.', 'wp-simple-backup' ),
        );
        set_transient( 'wpsb_progress_' . $backup_id, $data, self::TTL );
    }

    /**
     * Mark a backup as failed.
     *
     * @param string $backup_id Unique backup identifier.
     * @param string $error     Error message.
     */
    public static function fail( $backup_id, $error ) {
        $data = self::get( $backup_id );
        if ( ! $data ) {
            $data = array( 'log' => array(), 'total_steps' => 1, 'step' => 0, 'started_at' => time() );
        }
        $data['status']  = 'error';
        $data['message'] = $error;
        $data['log'][]   = array(
            'time'    => date( 'H:i:s' ),
            'message' => 'ERROR: ' . $error,
        );
        set_transient( 'wpsb_progress_' . $backup_id, $data, self::TTL );
    }

    /**
     * Retrieve progress data for a backup.
     *
     * @param  string $backup_id Unique backup identifier.
     * @return array|false       Progress array or false if not found / expired.
     */
    public static function get( $backup_id ) {
        return get_transient( 'wpsb_progress_' . $backup_id );
    }

    /**
     * Delete progress data for a backup.
     *
     * @param string $backup_id Unique backup identifier.
     */
    public static function clear( $backup_id ) {
        delete_transient( 'wpsb_progress_' . $backup_id );
    }
}
