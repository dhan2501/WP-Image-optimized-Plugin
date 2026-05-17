<?php
/**
 * Plugin Name: Smart Image Optimizer
 * Plugin URI:  #
 * Description: Comprehensive image optimization – convert formats, compress, resize, lazy load, strip EXIF, bulk optimize, and more.
 * Version:     1.0.0
 * Author:      Dhananjay Gupta
 * License:     GPL-2.0+
 * Text Domain: smart-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SIO_VERSION',     '1.0.0' );
define( 'SIO_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SIO_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'SIO_PLUGIN_FILE', __FILE__ );

require_once SIO_PLUGIN_DIR . 'includes/class-sio-settings.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-optimizer.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-bulk.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-admin.php';

function sio_init() {
    SIO_Settings::get_instance();
    SIO_Optimizer::get_instance();
    SIO_Bulk::get_instance();
    if ( is_admin() ) {
        SIO_Admin::get_instance();
    }
}
add_action( 'plugins_loaded', 'sio_init' );

register_activation_hook( __FILE__, function () {
    $defaults = [
        'convert_format'      => 'webp',
        'compression_quality' => 82,
        'max_width'           => 1920,
        'max_height'          => 1080,
        'enable_lazy_load'    => 1,
        'strip_exif'          => 1,
        'auto_optimize'       => 1,
        'keep_original'       => 1,
        'optimize_thumbnails' => 1,
        'enable_srcset'       => 1,
    ];
    foreach ( $defaults as $key => $val ) {
        if ( false === get_option( 'sio_' . $key ) ) {
            update_option( 'sio_' . $key, $val );
        }
    }
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sio_log (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        attachment_id BIGINT UNSIGNED NOT NULL,
        original_size BIGINT UNSIGNED DEFAULT 0,
        optimized_size BIGINT UNSIGNED DEFAULT 0,
        savings_pct   FLOAT DEFAULT 0,
        format_from   VARCHAR(10) DEFAULT '',
        format_to     VARCHAR(10) DEFAULT '',
        status        VARCHAR(20) DEFAULT 'pending',
        optimized_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
});
