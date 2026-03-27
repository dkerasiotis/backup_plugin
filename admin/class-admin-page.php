<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Register the plugin page under Tools.
     */
    public function register_menu() {
        add_management_page(
            __( 'Simple Backup', 'wp-simple-backup' ),
            __( 'Simple Backup', 'wp-simple-backup' ),
            'manage_options',
            'wp-simple-backup',
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue CSS and JS only on our page.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'tools_page_wp-simple-backup' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wpsb-admin',
            WPSB_PLUGIN_URL . 'admin/assets/css/admin.css',
            array(),
            WPSB_VERSION
        );

        wp_enqueue_script(
            'wpsb-admin',
            WPSB_PLUGIN_URL . 'admin/assets/js/admin.js',
            array( 'jquery' ),
            WPSB_VERSION,
            true
        );

        wp_localize_script( 'wpsb-admin', 'wpsb_ajax', array(
            'ajaxurl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'wpsb_backup_action' ),
            'strings'      => array(
                'starting'     => __( 'Starting backup…', 'wp-simple-backup' ),
                'done'         => __( 'Backup complete!', 'wp-simple-backup' ),
                'error'        => __( 'Backup failed.', 'wp-simple-backup' ),
                'no_component' => __( 'Please select at least one backup component.', 'wp-simple-backup' ),
                'download'     => __( 'Download Backup', 'wp-simple-backup' ),
                'running'      => __( 'Backup in progress — please do not close this page.', 'wp-simple-backup' ),
            ),
        ) );
    }

    /**
     * Render the admin page HTML.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-simple-backup' ) );
        }
        ?>
        <div class="wrap" id="wpsb-admin-wrap">
            <h1><?php esc_html_e( 'WP Simple Backup', 'wp-simple-backup' ); ?></h1>
            <p class="wpsb-subtitle"><?php esc_html_e( 'Select what to back up, then click Start Backup. A ZIP file will be prepared for download.', 'wp-simple-backup' ); ?></p>

            <div class="wpsb-card">
                <h2><?php esc_html_e( 'Backup Components', 'wp-simple-backup' ); ?></h2>

                <div class="wpsb-components">

                    <label class="wpsb-component-label">
                        <input type="checkbox" name="wpsb_components" value="database" checked>
                        <span class="wpsb-component-icon">🗄️</span>
                        <span class="wpsb-component-info">
                            <strong><?php esc_html_e( 'Database', 'wp-simple-backup' ); ?></strong>
                            <small><?php esc_html_e( 'Full SQL dump of all WordPress database tables', 'wp-simple-backup' ); ?></small>
                        </span>
                    </label>

                    <label class="wpsb-component-label">
                        <input type="checkbox" name="wpsb_components" value="files">
                        <span class="wpsb-component-icon">📁</span>
                        <span class="wpsb-component-info">
                            <strong><?php esc_html_e( 'Files (wp-content)', 'wp-simple-backup' ); ?></strong>
                            <small><?php esc_html_e( 'All themes, plugins, and uploaded media', 'wp-simple-backup' ); ?></small>
                        </span>
                    </label>

                    <label class="wpsb-component-label">
                        <input type="checkbox" name="wpsb_components" value="posts">
                        <span class="wpsb-component-icon">📝</span>
                        <span class="wpsb-component-info">
                            <strong><?php esc_html_e( 'Posts &amp; Pages', 'wp-simple-backup' ); ?></strong>
                            <small><?php esc_html_e( 'All content exported as WordPress XML (WXR) — importable on any WordPress site', 'wp-simple-backup' ); ?></small>
                        </span>
                    </label>

                    <label class="wpsb-component-label">
                        <input type="checkbox" name="wpsb_components" value="plugins">
                        <span class="wpsb-component-icon">🔌</span>
                        <span class="wpsb-component-info">
                            <strong><?php esc_html_e( 'Plugins only', 'wp-simple-backup' ); ?></strong>
                            <small><?php esc_html_e( 'Just the wp-content/plugins directory + a manifest of installed plugins', 'wp-simple-backup' ); ?></small>
                        </span>
                    </label>

                </div><!-- .wpsb-components -->

                <div class="wpsb-actions">
                    <button id="wpsb-start-backup" class="button button-primary button-hero">
                        <?php esc_html_e( 'Start Backup', 'wp-simple-backup' ); ?>
                    </button>
                </div>
            </div><!-- .wpsb-card -->

            <!-- Progress section (hidden until backup starts) -->
            <div class="wpsb-card" id="wpsb-progress" style="display:none;">
                <h2><?php esc_html_e( 'Backup Progress', 'wp-simple-backup' ); ?></h2>
                <p id="wpsb-progress-notice" class="wpsb-notice"><?php esc_html_e( 'Backup in progress — please do not close this page.', 'wp-simple-backup' ); ?></p>

                <div class="wpsb-progress-bar-track">
                    <div class="wpsb-progress-bar-fill" id="wpsb-progress-bar" style="width:0%"></div>
                </div>
                <p id="wpsb-progress-message" class="wpsb-progress-message"></p>

                <ul id="wpsb-progress-log" class="wpsb-log"></ul>
            </div>

            <!-- Download section (hidden until backup is done) -->
            <div class="wpsb-card" id="wpsb-download" style="display:none;">
                <h2><?php esc_html_e( 'Download Ready', 'wp-simple-backup' ); ?></h2>
                <p><?php esc_html_e( 'Your backup is ready. Click the button below to download it. The file will be automatically deleted after download.', 'wp-simple-backup' ); ?></p>
                <a id="wpsb-download-link" href="#" class="button button-primary button-hero wpsb-download-btn">
                    ⬇ <?php esc_html_e( 'Download Backup (.zip)', 'wp-simple-backup' ); ?>
                </a>
                <p class="wpsb-hint"><?php esc_html_e( 'Want to create another backup? Reload the page or click Start Backup again.', 'wp-simple-backup' ); ?></p>
            </div>

        </div><!-- .wrap -->
        <?php
    }
}
