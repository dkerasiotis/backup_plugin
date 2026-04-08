<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Backup_Archiver {

    /**
     * Create the backup ZIP, calling each requested component.
     *
     * @param  string $backup_id  Unique backup identifier.
     * @param  array  $components List of component keys: database, files, posts, plugins.
     * @return string|WP_Error    Path to finished ZIP on success, WP_Error on failure.
     */
    public function create( $backup_id, $components ) {
        // Ensure storage directory exists
        if ( ! file_exists( WPSB_BACKUP_DIR ) ) {
            wp_mkdir_p( WPSB_BACKUP_DIR );
        }

        $zip_path = WPSB_BACKUP_DIR . 'backup-' . $backup_id . '.zip';
        $work_dir = WPSB_BACKUP_DIR . $backup_id . '/';
        wp_mkdir_p( $work_dir );

        $zip = new ZipArchive();
        $opened = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        if ( true !== $opened ) {
            return new WP_Error( 'zip_open_failed', 'Could not create ZIP archive (error code ' . $opened . ').' );
        }

        $step = 1;

        // Database
        if ( in_array( 'database', $components, true ) ) {
            WPSB_Backup_Logger::update( $backup_id, $step, 'Backing up database…' );
            $sql_path = $work_dir . 'backup.sql';
            $db       = new WPSB_Backup_Database();
            $result   = $db->dump( $sql_path );
            if ( is_wp_error( $result ) ) {
                $zip->close();
                $this->cleanup_work_dir( $work_dir );
                return $result;
            }
            $zip->addFile( $sql_path, 'database/backup.sql' );
            $step++;
        }

        // Posts (WXR)
        if ( in_array( 'posts', $components, true ) ) {
            WPSB_Backup_Logger::update( $backup_id, $step, 'Exporting posts and pages…' );
            $wxr_path = $work_dir . 'posts-export.xml';
            $posts    = new WPSB_Backup_Posts();
            $result   = $posts->export( $wxr_path );
            if ( is_wp_error( $result ) ) {
                $zip->close();
                $this->cleanup_work_dir( $work_dir );
                return $result;
            }
            $zip->addFile( $wxr_path, 'posts/posts-export.xml' );
            $step++;
        }

        // Files (wp-content)
        if ( in_array( 'files', $components, true ) ) {
            WPSB_Backup_Logger::update( $backup_id, $step, 'Backing up wp-content files (this may take a while)…' );
            $files  = new WPSB_Backup_Files();
            $result = $files->add_wp_content( $zip, 'wordpress/' );
            if ( is_wp_error( $result ) ) {
                $zip->close();
                $this->cleanup_work_dir( $work_dir );
                return $result;
            }
            $step++;
        }

        // Plugins only
        if ( in_array( 'plugins', $components, true ) && ! in_array( 'files', $components, true ) ) {
            WPSB_Backup_Logger::update( $backup_id, $step, 'Backing up plugins…' );
            $plugins = new WPSB_Backup_Plugins();
            $result  = $plugins->backup( $zip );
            if ( is_wp_error( $result ) ) {
                $zip->close();
                $this->cleanup_work_dir( $work_dir );
                return $result;
            }
            $step++;
        } elseif ( in_array( 'plugins', $components, true ) && in_array( 'files', $components, true ) ) {
            // Manifest only (files already includes plugins directory)
            $plugins = new WPSB_Backup_Plugins();
            $manifest = $this->get_manifest_content( $plugins );
            $zip->addFromString( 'plugins-manifest.txt', $manifest );
        }

        // Add backup info file
        $this->add_info_file( $zip, $components, $backup_id );

        WPSB_Backup_Logger::update( $backup_id, $step, 'Finalising archive…' );

        // ZipArchive::addFile() is lazy — all file I/O happens here at close() time.
        // Reset the time limit once more right before close() so a slow finalisation
        // on a large site cannot hit a stale limit set earlier in the process.
        @set_time_limit( 0 );
        $closed = $zip->close();

        // Clean up temporary work directory
        $this->cleanup_work_dir( $work_dir );

        if ( false === $closed ) {
            return new WP_Error( 'zip_close_failed', 'Could not finalise ZIP archive. Check available disk space on the server.' );
        }

        if ( ! file_exists( $zip_path ) ) {
            return new WP_Error( 'zip_not_created', 'ZIP file was not created.' );
        }

        return $zip_path;
    }

    /**
     * Stream a ZIP file to the browser as a download and delete it afterwards.
     *
     * @param string $zip_path   Absolute path to the ZIP file.
     * @param string $backup_id  Backup ID (used to verify the path is legitimate).
     */
    public function stream_download( $zip_path, $backup_id ) {
        if ( ! file_exists( $zip_path ) ) {
            wp_die( 'Backup file not found.' );
        }

        // Verify path is inside our backup directory
        $real_zip  = realpath( $zip_path );
        $real_base = realpath( WPSB_BACKUP_DIR );
        if ( ! $real_zip || ! $real_base || strpos( $real_zip, $real_base ) !== 0 ) {
            wp_die( 'Invalid backup path.' );
        }

        $filename = 'wp-backup-' . date( 'Y-m-d-His' ) . '.zip';
        $filesize = filesize( $zip_path );

        // Clear ALL levels of output buffering.
        // ob_end_clean() alone only removes one level; WordPress and plugins
        // can open multiple levels, and any stray output in them will corrupt
        // the HTTP response causing ERR_INVALID_RESPONSE in the browser.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . $filesize );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'X-Accel-Buffering: no' ); // prevent Nginx from buffering the response

        $handle = fopen( $zip_path, 'rb' );
        if ( $handle ) {
            while ( ! feof( $handle ) ) {
                echo fread( $handle, 8192 );
                flush();
            }
            fclose( $handle );
        }

        // Do NOT unlink here — if the browser retries after a failed download
        // the file must still exist. The 24h cleanup in cleanup_old_backups()
        // will remove it automatically.

        exit;
    }

    /**
     * Delete ZIP files in the backup directory older than $max_age_hours.
     *
     * @param int $max_age_hours
     */
    public function cleanup_old_backups( $max_age_hours = 24 ) {
        if ( ! is_dir( WPSB_BACKUP_DIR ) ) {
            return;
        }

        $cutoff = time() - ( $max_age_hours * HOUR_IN_SECONDS );
        $files  = glob( WPSB_BACKUP_DIR . 'backup-*.zip' );

        if ( ! $files ) {
            return;
        }

        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Add a backup-info.txt metadata file to the root of the ZIP.
     */
    private function add_info_file( ZipArchive $zip, $components, $backup_id ) {
        $info  = "WP Simple Backup\n";
        $info .= str_repeat( '=', 40 ) . "\n";
        $info .= 'Backup ID:          ' . $backup_id . "\n";
        $info .= 'Date:               ' . date( 'Y-m-d H:i:s' ) . "\n";
        $info .= 'Site URL:           ' . get_bloginfo( 'url' ) . "\n";
        $info .= 'WordPress Version:  ' . get_bloginfo( 'version' ) . "\n";
        $info .= 'PHP Version:        ' . PHP_VERSION . "\n";
        $info .= 'Components:         ' . implode( ', ', $components ) . "\n";
        $zip->addFromString( 'backup-info.txt', $info );
    }

    /**
     * Get manifest content without adding files to zip (used when 'files' + 'plugins' both selected).
     */
    private function get_manifest_content( WPSB_Backup_Plugins $plugins_handler ) {
        // Use reflection-free approach: create a dummy zip to capture addFromString content
        // Instead, just regenerate the manifest text directly here
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
            $status  = in_array( $plugin_file, $active_plugins, true ) ? 'ACTIVE' : 'inactive';
            $lines[] = sprintf( '[%s] %s v%s  (%s)', $status, $plugin_data['Name'], $plugin_data['Version'], $plugin_file );
        }
        $lines[] = str_repeat( '-', 60 );
        $lines[] = 'Total plugins: ' . count( $all_plugins );

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Recursively delete a directory.
     */
    private function cleanup_work_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getRealPath() );
            } else {
                @unlink( $file->getRealPath() );
            }
        }
        @rmdir( $dir );
    }
}
