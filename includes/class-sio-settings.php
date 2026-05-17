<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SIO_Settings {
    private static $instance;

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_post_sio_save_settings', [ $this, 'save' ] );
    }

    public static function get( $key, $default = null ) {
        return get_option( 'sio_' . $key, $default );
    }

    public static function all() {
        return [
            'convert_format'      => self::get( 'convert_format', 'webp' ),
            'compression_quality' => (int) self::get( 'compression_quality', 82 ),
            'max_width'           => (int) self::get( 'max_width', 1920 ),
            'max_height'          => (int) self::get( 'max_height', 1080 ),
            'enable_lazy_load'    => (bool) self::get( 'enable_lazy_load', true ),
            'strip_exif'          => (bool) self::get( 'strip_exif', true ),
            'auto_optimize'       => (bool) self::get( 'auto_optimize', true ),
            'keep_original'       => (bool) self::get( 'keep_original', true ),
            'optimize_thumbnails' => (bool) self::get( 'optimize_thumbnails', true ),
            'enable_srcset'       => (bool) self::get( 'enable_srcset', true ),
        ];
    }

    public function save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'sio_settings_nonce' );

        $fields = [
            'convert_format'      => 'sanitize_text_field',
            'compression_quality' => 'intval',
            'max_width'           => 'intval',
            'max_height'          => 'intval',
        ];
        foreach ( $fields as $field => $cb ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_option( 'sio_' . $field, $cb( $_POST[ $field ] ) );
            }
        }

        $toggles = [ 'enable_lazy_load', 'strip_exif', 'auto_optimize', 'keep_original', 'optimize_thumbnails', 'enable_srcset' ];
        foreach ( $toggles as $t ) {
            update_option( 'sio_' . $t, isset( $_POST[ $t ] ) ? 1 : 0 );
        }

        wp_redirect( admin_url( 'admin.php?page=smart-image-optimizer&saved=1' ) );
        exit;
    }
}
