<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Backup_Database {

    /**
     * Dump the WordPress database to a .sql file.
     *
     * @param  string $output_path Full filesystem path for the output .sql file.
     * @return true|WP_Error
     */
    public function dump( $output_path ) {
        // Try mysqldump first
        if ( $this->exec_available() ) {
            $result = $this->dump_via_exec( $output_path );
            if ( true === $result ) {
                return true;
            }
            // Fall through to PHP method on failure
        }

        return $this->dump_via_php( $output_path );
    }

    /**
     * Check whether PHP exec() is available and not disabled.
     *
     * @return bool
     */
    private function exec_available() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }
        $disabled = explode( ',', ini_get( 'disable_functions' ) );
        $disabled = array_map( 'trim', $disabled );
        if ( in_array( 'exec', $disabled, true ) ) {
            return false;
        }
        return true;
    }

    /**
     * Dump the database using the mysqldump binary.
     *
     * @param  string $output_path Output .sql file path.
     * @return true|WP_Error
     */
    private function dump_via_exec( $output_path ) {
        // Write credentials to a temporary .cnf file to avoid exposing password in process list
        $cnf_path = $output_path . '.cnf';
        $cnf_content  = "[mysqldump]\n";
        $cnf_content .= 'user='     . DB_USER     . "\n";
        $cnf_content .= 'password=' . DB_PASSWORD . "\n";
        $cnf_content .= 'host='     . DB_HOST     . "\n";
        file_put_contents( $cnf_path, $cnf_content );
        chmod( $cnf_path, 0600 );

        $db_name = escapeshellarg( DB_NAME );
        $cnf_arg = escapeshellarg( $cnf_path );
        $out_arg = escapeshellarg( $output_path );

        $cmd = "mysqldump --defaults-extra-file={$cnf_arg} --single-transaction --skip-lock-tables --add-drop-table {$db_name} > {$out_arg} 2>&1";

        exec( $cmd, $output_lines, $return_code );

        // Always remove temp credentials file
        @unlink( $cnf_path );

        if ( 0 === $return_code && file_exists( $output_path ) && filesize( $output_path ) > 0 ) {
            return true;
        }

        return new WP_Error( 'mysqldump_failed', implode( "\n", $output_lines ) );
    }

    /**
     * Dump the database using pure PHP (fallback when exec() is unavailable).
     *
     * @param  string $output_path Output .sql file path.
     * @return true|WP_Error
     */
    private function dump_via_php( $output_path ) {
        global $wpdb;

        $handle = fopen( $output_path, 'wb' );
        if ( ! $handle ) {
            return new WP_Error( 'file_open_failed', 'Cannot open output file for database dump.' );
        }

        // Header
        fwrite( $handle, "-- WP Simple Backup\n" );
        fwrite( $handle, '-- Site: ' . get_bloginfo( 'url' ) . "\n" );
        fwrite( $handle, '-- Date: ' . date( 'Y-m-d H:i:s' ) . "\n" );
        fwrite( $handle, '-- WordPress: ' . get_bloginfo( 'version' ) . "\n\n" );
        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n" );
        fwrite( $handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" );
        fwrite( $handle, "SET NAMES utf8mb4;\n\n" );

        // Get all tables
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            fclose( $handle );
            return new WP_Error( 'no_tables', 'No tables found in database.' );
        }

        foreach ( $tables as $table ) {
            $safe_table = '`' . str_replace( '`', '``', $table ) . '`';

            fwrite( $handle, "\n-- Table: {$table}\n" );
            fwrite( $handle, "DROP TABLE IF EXISTS {$safe_table};\n" );

            // CREATE TABLE statement
            $create = $wpdb->get_row( "SHOW CREATE TABLE {$safe_table}", ARRAY_N );
            if ( $create && isset( $create[1] ) ) {
                fwrite( $handle, $create[1] . ";\n\n" );
            }

            // Row data in chunks
            $offset    = 0;
            $chunk     = 500;
            $col_info  = $wpdb->get_results( "SHOW COLUMNS FROM {$safe_table}", ARRAY_A );
            $col_names = array_column( $col_info, 'Field' );

            while ( true ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$safe_table} LIMIT %d OFFSET %d", $chunk, $offset ),
                    ARRAY_N
                );

                if ( empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $values = array();
                    foreach ( $row as $value ) {
                        if ( null === $value ) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . esc_sql( $value ) . "'";
                        }
                    }
                    fwrite( $handle, "INSERT INTO {$safe_table} VALUES (" . implode( ', ', $values ) . ");\n" );
                }

                $offset += $chunk;

                // Free memory
                $wpdb->flush();
            }
        }

        fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
        fclose( $handle );

        return true;
    }
}
