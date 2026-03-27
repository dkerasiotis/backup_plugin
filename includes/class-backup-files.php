<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Backup_Files {

    /**
     * Directories / path fragments to always exclude from backups.
     *
     * @var array
     */
    private $default_exclusions = array();

    public function __construct() {
        $this->default_exclusions = array(
            rtrim( WPSB_BACKUP_DIR, '/' ),                  // our own backup dir
            rtrim( WP_CONTENT_DIR, '/' ) . '/cache',        // cache
            rtrim( WP_CONTENT_DIR, '/' ) . '/upgrade',      // WP upgrade temp files
            rtrim( WP_CONTENT_DIR, '/' ) . '/updraft',      // UpdraftPlus backups
            rtrim( WP_CONTENT_DIR, '/' ) . '/backups',      // generic backup dirs
        );
    }

    /**
     * Add the entire wp-content directory to a ZipArchive.
     *
     * @param  ZipArchive $zip         Open ZipArchive instance.
     * @param  string     $zip_prefix  Prefix inside the ZIP (e.g. 'wordpress/').
     * @return true|WP_Error
     */
    public function add_wp_content( ZipArchive $zip, $zip_prefix = 'wordpress/' ) {
        return $this->add_directory_to_zip( $zip, WP_CONTENT_DIR, $zip_prefix, $this->default_exclusions );
    }

    /**
     * Recursively add a directory to a ZipArchive.
     *
     * @param  ZipArchive $zip        Open ZipArchive instance.
     * @param  string     $dir        Absolute filesystem path to directory.
     * @param  string     $prefix     Prefix to use inside the ZIP archive.
     * @param  array      $exclusions Absolute paths to skip.
     * @return true|WP_Error
     */
    public function add_directory_to_zip( ZipArchive $zip, $dir, $prefix, $exclusions = array() ) {
        $dir = rtrim( $dir, '/' );

        if ( ! is_dir( $dir ) ) {
            return new WP_Error( 'dir_not_found', "Directory not found: {$dir}" );
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD // silently skip symlinks-to-files and unreadable dirs
            );

            foreach ( $iterator as $item ) {
                $real_path = $item->getRealPath();

                // Skip broken symlinks (getRealPath() returns false)
                if ( false === $real_path ) {
                    continue;
                }

                // Skip excluded paths (children are also caught by prefix-matching in is_excluded)
                if ( $this->is_excluded( $real_path, $exclusions ) ) {
                    continue;
                }

                // Skip unreadable files
                if ( $item->isFile() && ! $item->isReadable() ) {
                    continue;
                }

                // Build the archive path
                $relative_path = substr( $real_path, strlen( $dir ) + 1 );
                $archive_path  = $prefix . str_replace( DIRECTORY_SEPARATOR, '/', $relative_path );

                if ( $item->isDir() ) {
                    $zip->addEmptyDir( $archive_path );
                } elseif ( $item->isFile() ) {
                    $zip->addFile( $real_path, $archive_path );
                }
            }
        } catch ( Exception $e ) {
            return new WP_Error( 'iterator_error', $e->getMessage() );
        }

        return true;
    }

    /**
     * Check if a path should be excluded.
     *
     * @param  string $path       Filesystem path to check.
     * @param  array  $exclusions List of excluded base paths.
     * @return bool
     */
    private function is_excluded( $path, $exclusions ) {
        foreach ( $exclusions as $excluded ) {
            $excluded = rtrim( $excluded, '/' );
            if ( $path === $excluded || strpos( $path, $excluded . DIRECTORY_SEPARATOR ) === 0 ) {
                return true;
            }
        }

        // Always skip .git and node_modules
        $basename = basename( $path );
        if ( in_array( $basename, array( '.git', 'node_modules', '.svn' ), true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Return the default exclusions list (for subclasses / extensions).
     *
     * @return array
     */
    public function get_exclusions() {
        return $this->default_exclusions;
    }
}
