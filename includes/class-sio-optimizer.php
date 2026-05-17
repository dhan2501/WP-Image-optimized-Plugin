<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SIO_Optimizer {
    private static $instance;

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Auto-optimize on upload
        add_filter( 'wp_handle_upload', [ $this, 'handle_upload' ] );
        // Lazy load
        add_filter( 'the_content',      [ $this, 'add_lazy_load' ] );
        add_filter( 'post_thumbnail_html', [ $this, 'add_lazy_load' ] );
        // AJAX single optimize
        add_action( 'wp_ajax_sio_optimize_single', [ $this, 'ajax_optimize_single' ] );
        add_action( 'wp_ajax_sio_get_stats',        [ $this, 'ajax_get_stats' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Upload hook                                                          */
    /* ------------------------------------------------------------------ */
    public function handle_upload( $upload ) {
        if ( ! SIO_Settings::get( 'auto_optimize', true ) ) return $upload;
        if ( ! isset( $upload['file'] ) ) return $upload;

        $mime = $upload['type'] ?? mime_content_type( $upload['file'] );
        if ( strpos( $mime, 'image/' ) !== 0 ) return $upload;

        $this->optimize_file( $upload['file'] );
        return $upload;
    }

    /* ------------------------------------------------------------------ */
    /*  Core optimizer                                                       */
    /* ------------------------------------------------------------------ */
    public function optimize_file( $file_path, $attachment_id = 0 ) {
        if ( ! file_exists( $file_path ) ) return false;

        $s        = SIO_Settings::all();
        $ext      = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $allowed  = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'tif' ];
        if ( ! in_array( $ext, $allowed, true ) ) return false;

        $original_size = filesize( $file_path );
        $result        = [ 'success' => false, 'original_size' => $original_size, 'optimized_size' => $original_size, 'savings' => 0 ];

        // ----- GD path -----
        if ( function_exists( 'imagecreatefromjpeg' ) ) {
            $image = $this->load_image_gd( $file_path, $ext );
            if ( ! $image ) return $result;

            // Strip EXIF: already done by reloading+saving
            // Resize
            $image = $this->maybe_resize( $image, $s['max_width'], $s['max_height'] );

            // Output format
            $target_ext  = $s['convert_format'] === 'none' ? $ext : $s['convert_format'];
            $target_path = $this->swap_extension( $file_path, $target_ext );

            $saved = $this->save_image_gd( $image, $target_path, $target_ext, $s['compression_quality'] );
            imagedestroy( $image );

            if ( $saved ) {
                // Keep original?
                if ( ! $s['keep_original'] && $target_path !== $file_path ) {
                    @unlink( $file_path );
                }
                $optimized_size = filesize( $target_path );
                $savings        = $original_size > 0 ? round( ( 1 - $optimized_size / $original_size ) * 100, 1 ) : 0;
                $result = [
                    'success'        => true,
                    'original_size'  => $original_size,
                    'optimized_size' => $optimized_size,
                    'savings'        => $savings,
                    'format_from'    => $ext,
                    'format_to'      => $target_ext,
                    'output_file'    => $target_path,
                ];
            }
        }

        // Log
        if ( $attachment_id && $result['success'] ) {
            $this->log( $attachment_id, $result );
        }

        return $result;
    }

    private function load_image_gd( $path, $ext ) {
        switch ( $ext ) {
            case 'jpg': case 'jpeg': return @imagecreatefromjpeg( $path );
            case 'png':  return @imagecreatefrompng( $path );
            case 'gif':  return @imagecreatefromgif( $path );
            case 'webp': return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
            case 'bmp':  return function_exists( 'imagecreatefrombmp' ) ? @imagecreatefrombmp( $path ) : false;
            default: return false;
        }
    }

    private function save_image_gd( $image, $path, $ext, $quality ) {
        switch ( $ext ) {
            case 'jpg': case 'jpeg':
                return imagejpeg( $image, $path, $quality );
            case 'png':
                $png_q = (int) round( ( 100 - $quality ) / 11.11 ); // 0-9
                return imagepng( $image, $path, $png_q );
            case 'gif':
                return imagegif( $image, $path );
            case 'webp':
                return function_exists( 'imagewebp' ) ? imagewebp( $image, $path, $quality ) : false;
            case 'avif':
                return function_exists( 'imageavif' ) ? imageavif( $image, $path, $quality ) : false;
            default:
                return imagejpeg( $image, $path, $quality );
        }
    }

    private function maybe_resize( $image, $max_w, $max_h ) {
        $w = imagesx( $image );
        $h = imagesy( $image );
        if ( $w <= $max_w && $h <= $max_h ) return $image;

        $ratio  = min( $max_w / $w, $max_h / $h );
        $new_w  = (int) round( $w * $ratio );
        $new_h  = (int) round( $h * $ratio );
        $resized = imagecreatetruecolor( $new_w, $new_h );

        // Preserve transparency
        imagealphablending( $resized, false );
        imagesavealpha( $resized, true );
        $transparent = imagecolorallocatealpha( $resized, 255, 255, 255, 127 );
        imagefilledrectangle( $resized, 0, 0, $new_w, $new_h, $transparent );

        imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
        imagedestroy( $image );
        return $resized;
    }

    private function swap_extension( $path, $new_ext ) {
        $info = pathinfo( $path );
        return $info['dirname'] . '/' . $info['filename'] . '.' . $new_ext;
    }

    /* ------------------------------------------------------------------ */
    /*  Lazy Load                                                            */
    /* ------------------------------------------------------------------ */
    public function add_lazy_load( $content ) {
        if ( ! SIO_Settings::get( 'enable_lazy_load', true ) ) return $content;
        if ( ! is_string( $content ) ) return $content;
        return preg_replace( '/<img(?![^>]*loading=)([^>]*)>/i', '<img loading="lazy"$1>', $content );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX                                                                 */
    /* ------------------------------------------------------------------ */
    public function ajax_optimize_single() {
        check_ajax_referer( 'sio_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( 'Unauthorized' );

        $id   = absint( $_POST['attachment_id'] ?? 0 );
        $file = get_attached_file( $id );
        if ( ! $file ) wp_send_json_error( 'File not found' );

        $result = $this->optimize_file( $file, $id );

        // Also optimize thumbnails
        if ( SIO_Settings::get( 'optimize_thumbnails', true ) ) {
            $meta = wp_get_attachment_metadata( $id );
            if ( ! empty( $meta['sizes'] ) ) {
                $dir = dirname( $file );
                foreach ( $meta['sizes'] as $size ) {
                    $thumb = $dir . '/' . $size['file'];
                    if ( file_exists( $thumb ) ) $this->optimize_file( $thumb );
                }
            }
        }

        wp_send_json_success( $result );
    }

    public function ajax_get_stats() {
        check_ajax_referer( 'sio_nonce', 'nonce' );
        global $wpdb;
        $table = $wpdb->prefix . 'sio_log';
        $stats = $wpdb->get_row( "SELECT COUNT(*) as total, SUM(original_size) as orig, SUM(optimized_size) as opt FROM $table WHERE status='done'" );
        $savings = ( $stats->orig > 0 ) ? round( ( 1 - $stats->opt / $stats->orig ) * 100, 1 ) : 0;
        wp_send_json_success( [
            'total'          => (int) $stats->total,
            'original_bytes' => (int) $stats->orig,
            'optimized_bytes'=> (int) $stats->opt,
            'savings_pct'    => $savings,
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  DB log                                                               */
    /* ------------------------------------------------------------------ */
    private function log( $attachment_id, $result ) {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'sio_log', [
            'attachment_id'  => $attachment_id,
            'original_size'  => $result['original_size'],
            'optimized_size' => $result['optimized_size'],
            'savings_pct'    => $result['savings'],
            'format_from'    => $result['format_from'],
            'format_to'      => $result['format_to'],
            'status'         => 'done',
            'optimized_at'   => current_time( 'mysql' ),
        ] );
    }
}
