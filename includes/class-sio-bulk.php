<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SIO_Bulk {
    private static $instance;

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_sio_bulk_next',  [ $this, 'ajax_bulk_next' ] );
        add_action( 'wp_ajax_sio_bulk_reset', [ $this, 'ajax_bulk_reset' ] );
        add_action( 'wp_ajax_sio_bulk_count', [ $this, 'ajax_bulk_count' ] );
    }

    public function ajax_bulk_count() {
        check_ajax_referer( 'sio_nonce', 'nonce' );
        wp_send_json_success( [ 'count' => $this->get_unoptimized_count() ] );
    }

    public function ajax_bulk_next() {
        check_ajax_referer( 'sio_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( 'Unauthorized' );

        $ids = $this->get_unoptimized_ids( 1 );
        if ( empty( $ids ) ) {
            wp_send_json_success( [ 'done' => true ] );
            return;
        }

        $id      = $ids[0];
        $file    = get_attached_file( $id );
        $result  = SIO_Optimizer::get_instance()->optimize_file( $file, $id );
        $remaining = $this->get_unoptimized_count();

        wp_send_json_success( [
            'done'           => false,
            'attachment_id'  => $id,
            'result'         => $result,
            'remaining'      => $remaining,
        ] );
    }

    public function ajax_bulk_reset() {
        check_ajax_referer( 'sio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sio_log" );
        wp_send_json_success();
    }

    private function get_unoptimized_ids( $limit = 10 ) {
        global $wpdb;
        $done = $wpdb->get_col( "SELECT attachment_id FROM {$wpdb->prefix}sio_log WHERE status='done'" );
        $exclude = ! empty( $done ) ? 'AND ID NOT IN (' . implode( ',', array_map( 'intval', $done ) ) . ')' : '';
        return $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='attachment'
             AND post_mime_type LIKE 'image/%'
             $exclude
             ORDER BY ID ASC
             LIMIT $limit"
        );
    }

    private function get_unoptimized_count() {
        global $wpdb;
        $done = $wpdb->get_col( "SELECT attachment_id FROM {$wpdb->prefix}sio_log WHERE status='done'" );
        $exclude = ! empty( $done ) ? 'AND ID NOT IN (' . implode( ',', array_map( 'intval', $done ) ) . ')' : '';
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='attachment'
             AND post_mime_type LIKE 'image/%'
             $exclude"
        );
    }
}
