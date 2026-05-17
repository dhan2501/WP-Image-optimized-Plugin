<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SIO_Admin {
    private static $instance;

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_filter( 'manage_media_columns',       [ $this, 'media_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'media_column_content' ], 10, 2 );
    }

    public function register_menu() {
        add_menu_page(
            'Smart Image Optimizer',
            'Image Optimizer',
            'manage_options',
            'smart-image-optimizer',
            [ $this, 'render_main' ],
            'dashicons-images-alt2',
            85
        );
        add_submenu_page(
            'smart-image-optimizer',
            'Settings',
            'Settings',
            'manage_options',
            'sio-settings',
            [ $this, 'render_settings' ]
        );
        add_submenu_page(
            'smart-image-optimizer',
            'Bulk Optimize',
            'Bulk Optimize',
            'upload_files',
            'sio-bulk',
            [ $this, 'render_bulk' ]
        );
    }

    public function enqueue( $hook ) {
        $pages = [
            'toplevel_page_smart-image-optimizer',
            'image-optimizer_page_sio-settings',
            'image-optimizer_page_sio-bulk',
        ];
        if ( ! in_array( $hook, $pages, true ) && $hook !== 'upload.php' ) return;

        wp_enqueue_style(  'sio-admin', SIO_PLUGIN_URL . 'admin/css/admin.css', [], SIO_VERSION );
        wp_enqueue_script( 'sio-admin', SIO_PLUGIN_URL . 'admin/js/admin.js', [ 'jquery' ], SIO_VERSION, true );
        wp_localize_script( 'sio-admin', 'SIO', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'sio_nonce' ),
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard page                                                       */
    /* ------------------------------------------------------------------ */
    public function render_main() {
        $s = SIO_Settings::all();
        ?>
        <div class="sio-wrap">
            <div class="sio-header">
                <div class="sio-logo">
                    <span class="sio-logo-icon">&#9889;</span>
                    <div>
                        <h1>Smart Image Optimizer</h1>
                        <p>Compress &middot; Convert &middot; Resize &middot; Lazy Load</p>
                    </div>
                </div>
            </div>

            <div class="sio-stats-row" id="sio-stats">
                <div class="sio-stat-card">
                    <div class="stat-num" id="stat-total-num">&#8212;</div>
                    <div class="stat-label">Images Optimized</div>
                </div>
                <div class="sio-stat-card">
                    <div class="stat-num" id="stat-saved-num">&#8212;</div>
                    <div class="stat-label">Space Saved</div>
                </div>
                <div class="sio-stat-card">
                    <div class="stat-num" id="stat-pct-num">&#8212;</div>
                    <div class="stat-label">Avg. Savings</div>
                </div>
                <div class="sio-stat-card">
                    <div class="stat-num"><?php echo esc_html( strtoupper( $s['convert_format'] === 'none' ? 'ORIG' : $s['convert_format'] ) ); ?></div>
                    <div class="stat-label">Output Format</div>
                </div>
            </div>

            <div class="sio-quick-actions">
                <h2>Quick Actions</h2>
                <div class="sio-action-grid">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sio-bulk' ) ); ?>" class="sio-action-btn">
                        <span>&#128194;</span>
                        <strong>Bulk Optimize</strong>
                        <small>Process all images at once</small>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sio-settings' ) ); ?>" class="sio-action-btn">
                        <span>&#9881;</span>
                        <strong>Settings</strong>
                        <small>Configure optimization rules</small>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="sio-action-btn">
                        <span>&#128444;</span>
                        <strong>Media Library</strong>
                        <small>Optimize individual images</small>
                    </a>
                    <button class="sio-action-btn" id="sio-reset-btn">
                        <span>&#128260;</span>
                        <strong>Reset Logs</strong>
                        <small>Clear optimization history</small>
                    </button>
                </div>
            </div>

            <div class="sio-feature-list">
                <h2>Active Features</h2>
                <div class="sio-features-grid">
                    <?php
                    /*
                     * Each entry: [ option_key, label, description ]
                     * $f[0] = option key   $f[1] = label   $f[2] = description
                     */
                    $features = [
                        [ 'enable_lazy_load',    'Lazy Loading',       'Images load only when visible' ],
                        [ 'strip_exif',          'EXIF Stripping',     'Remove metadata from images' ],
                        [ 'auto_optimize',       'Auto-Optimize',      'Optimize on upload' ],
                        [ 'keep_original',       'Keep Originals',     'Backup before conversion' ],
                        [ 'optimize_thumbnails', 'Thumbnail Opt.',     'Optimize all WP sizes' ],
                        [ 'enable_srcset',       'Responsive srcset',  'Smart responsive images' ],
                    ];
                    foreach ( $features as $f ) :
                        $is_on = ! empty( $s[ $f[0] ] );
                    ?>
                        <div class="sio-feature-item <?php echo $is_on ? 'active' : 'inactive'; ?>">
                            <div class="feat-dot"></div>
                            <div>
                                <strong><?php echo esc_html( $f[1] ); ?></strong>
                                <small><?php echo esc_html( $f[2] ); ?></small>
                            </div>
                            <span class="feat-status"><?php echo $is_on ? '&#10003; On' : '&#10007; Off'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Settings page                                                        */
    /* ------------------------------------------------------------------ */
    public function render_settings() {
        $s     = SIO_Settings::all();
        $saved = isset( $_GET['saved'] );
        ?>
        <div class="sio-wrap">
            <div class="sio-header">
                <div class="sio-logo">
                    <span class="sio-logo-icon">&#9881;</span>
                    <div>
                        <h1>Optimization Settings</h1>
                        <p>Fine-tune every aspect of image processing</p>
                    </div>
                </div>
            </div>

            <?php if ( $saved ) : ?>
                <div class="sio-notice sio-notice-success">&#9989; Settings saved successfully!</div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sio-settings-form">
                <input type="hidden" name="action" value="sio_save_settings">
                <?php wp_nonce_field( 'sio_settings_nonce' ); ?>

                <!-- ── Format Conversion ── -->
                <div class="sio-card">
                    <div class="sio-card-header">
                        <div class="sio-card-icon">&#8635;</div>
                        <div>
                            <h3>Format Conversion</h3>
                            <p>Convert images to modern, efficient formats on upload or during bulk processing</p>
                        </div>
                    </div>
                    <div class="sio-card-body">

                        <label class="sio-label">
                            Output Format
                            <span class="sio-hint">All uploaded images will be converted to the selected format</span>
                        </label>

                        <!-- Visual card picker (top 5) -->
                        <div class="sio-format-picker">
                            <?php
                            /*
                             * Each entry: [ value, label, description ]
                             * $fmt[0] = value   $fmt[1] = label   $fmt[2] = description
                             */
                            $formats = [
                                [ 'none', 'Keep Original', 'No conversion' ],
                                [ 'webp', 'WebP',          '~30% smaller than JPEG' ],
                                [ 'avif', 'AVIF',          'Smallest, PHP 8.1+ GD' ],
                                [ 'jpg',  'JPEG',          'Universal compatibility' ],
                                [ 'png',  'PNG',           'Lossless + transparency' ],
                            ];
                            foreach ( $formats as $fmt ) :
                                $sel = ( $s['convert_format'] === $fmt[0] ) ? 'selected' : '';
                            ?>
                                <label class="sio-format-card <?php echo $sel; ?>">
                                    <input type="radio" name="convert_format"
                                           value="<?php echo esc_attr( $fmt[0] ); ?>"
                                           <?php checked( $s['convert_format'], $fmt[0] ); ?> hidden>
                                    <strong><?php echo esc_html( $fmt[1] ); ?></strong>
                                    <small><?php echo esc_html( $fmt[2] ); ?></small>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Full dropdown – all extensions -->
                        <label class="sio-label" style="margin-top:20px;">
                            All Supported Extensions &mdash; Dropdown
                            <span class="sio-hint">Same setting as the cards above; includes every supported extension</span>
                        </label>
                        <select name="convert_format" id="sio-format-select" class="sio-select">
                            <option value="none" <?php selected( $s['convert_format'], 'none' ); ?>>&#8212; Keep Original (no conversion)</option>
                            <optgroup label="Modern Formats (Recommended)">
                                <option value="webp" <?php selected( $s['convert_format'], 'webp' ); ?>>WebP (.webp) &mdash; Best balance, ~30% smaller</option>
                                <option value="avif" <?php selected( $s['convert_format'], 'avif' ); ?>>AVIF (.avif) &mdash; Smallest size, PHP 8.1+ required</option>
                            </optgroup>
                            <optgroup label="Standard Formats">
                                <option value="jpg"  <?php selected( $s['convert_format'], 'jpg'  ); ?>>JPEG (.jpg) &mdash; Universal compatibility</option>
                                <option value="jpeg" <?php selected( $s['convert_format'], 'jpeg' ); ?>>JPEG (.jpeg) &mdash; Same as .jpg</option>
                                <option value="png"  <?php selected( $s['convert_format'], 'png'  ); ?>>PNG (.png) &mdash; Lossless with transparency</option>
                                <option value="gif"  <?php selected( $s['convert_format'], 'gif'  ); ?>>GIF (.gif) &mdash; Animations supported</option>
                            </optgroup>
                            <optgroup label="Legacy / Specialty">
                                <option value="bmp"  <?php selected( $s['convert_format'], 'bmp'  ); ?>>BMP (.bmp) &mdash; Uncompressed bitmap</option>
                                <option value="tiff" <?php selected( $s['convert_format'], 'tiff' ); ?>>TIFF (.tiff) &mdash; Print / archival quality</option>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <!-- ── Compression ── -->
                <div class="sio-card">
                    <div class="sio-card-header">
                        <div class="sio-card-icon">&#128230;</div>
                        <div>
                            <h3>Compression Quality</h3>
                            <p>Higher = better quality but larger file size. Applies to JPEG and WebP output.</p>
                        </div>
                    </div>
                    <div class="sio-card-body">
                        <div class="sio-quality-wrap">
                            <input type="range" name="compression_quality" id="sio-quality-range"
                                   min="1" max="100"
                                   value="<?php echo (int) $s['compression_quality']; ?>"
                                   class="sio-range">
                            <div class="sio-quality-val">
                                <input type="number" id="sio-quality-num"
                                       value="<?php echo (int) $s['compression_quality']; ?>"
                                       min="1" max="100" class="sio-num-input">
                                <span>/ 100</span>
                            </div>
                        </div>
                        <div class="sio-quality-presets">
                            <button type="button" class="sio-preset" data-q="60">Low (60) &mdash; Tiny files</button>
                            <button type="button" class="sio-preset" data-q="75">Good (75) &mdash; Balanced</button>
                            <button type="button" class="sio-preset sio-preset-active" data-q="82">Best (82) &mdash; Recommended</button>
                            <button type="button" class="sio-preset" data-q="92">High (92) &mdash; Near-lossless</button>
                        </div>
                    </div>
                </div>

                <!-- ── Max Dimensions ── -->
                <div class="sio-card">
                    <div class="sio-card-header">
                        <div class="sio-card-icon">&#128208;</div>
                        <div>
                            <h3>Max Dimensions</h3>
                            <p>Images larger than these will be downscaled, keeping the original aspect ratio</p>
                        </div>
                    </div>
                    <div class="sio-card-body sio-two-col">
                        <label class="sio-label">
                            Max Width (px)
                            <input type="number" name="max_width"
                                   value="<?php echo (int) $s['max_width']; ?>"
                                   class="sio-input" min="100" max="9999">
                        </label>
                        <label class="sio-label">
                            Max Height (px)
                            <input type="number" name="max_height"
                                   value="<?php echo (int) $s['max_height']; ?>"
                                   class="sio-input" min="100" max="9999">
                        </label>
                    </div>
                    <div class="sio-dim-presets">
                        <strong>Quick set:</strong>
                        <button type="button" class="sio-dim-preset" data-w="1280" data-h="720">HD 720p</button>
                        <button type="button" class="sio-dim-preset" data-w="1920" data-h="1080">FHD 1080p</button>
                        <button type="button" class="sio-dim-preset" data-w="2560" data-h="1440">QHD 1440p</button>
                        <button type="button" class="sio-dim-preset" data-w="3840" data-h="2160">4K UHD</button>
                    </div>
                </div>

                <!-- ── Feature Toggles ── -->
                <div class="sio-card">
                    <div class="sio-card-header">
                        <div class="sio-card-icon">&#127917;</div>
                        <div>
                            <h3>Feature Toggles</h3>
                            <p>Enable or disable individual optimization features</p>
                        </div>
                    </div>
                    <div class="sio-card-body">
                        <?php
                        /*
                         * IMPORTANT: array structure is [ option_key, label, description ]
                         *   $t[0] = option_key  → used for name="" attribute AND $s[] lookup
                         *   $t[1] = label
                         *   $t[2] = description
                         * No emoji in this array to avoid multibyte key accidents.
                         */
                        $toggles = [
                            [
                                'auto_optimize',
                                'Auto-Optimize on Upload',
                                'Automatically optimize every image when it is uploaded to the media library.',
                            ],
                            [
                                'enable_lazy_load',
                                'Lazy Loading',
                                'Add loading="lazy" attribute to all images in post content and thumbnails.',
                            ],
                            [
                                'strip_exif',
                                'Strip EXIF / Metadata',
                                'Remove GPS, camera info, and other metadata to reduce file size and protect privacy.',
                            ],
                            [
                                'keep_original',
                                'Keep Original Files',
                                'Store a backup of the original image before converting or compressing.',
                            ],
                            [
                                'optimize_thumbnails',
                                'Optimize WordPress Thumbnails',
                                'Also compress all cropped thumbnail sizes generated by WordPress.',
                            ],
                            [
                                'enable_srcset',
                                'Responsive srcset Support',
                                'Enable smart responsive images so browsers pick the best size automatically.',
                            ],
                        ];

                        foreach ( $toggles as $t ) :
                            $option_key = $t[0]; // e.g. 'enable_srcset'
                            $label      = $t[1];
                            $desc       = $t[2];
                            $is_checked = ! empty( $s[ $option_key ] );
                        ?>
                            <div class="sio-toggle-row">
                                <div class="toggle-dot <?php echo $is_checked ? 'dot-on' : 'dot-off'; ?>"></div>
                                <div class="toggle-info">
                                    <strong><?php echo esc_html( $label ); ?></strong>
                                    <small><?php echo esc_html( $desc ); ?></small>
                                </div>
                                <label class="sio-toggle-switch">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $option_key ); ?>"
                                           value="1"
                                           <?php checked( true, $is_checked ); ?>>
                                    <span class="sio-slider"></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sio-save-bar">
                    <button type="submit" class="sio-btn-primary">&#128190; Save All Settings</button>
                </div>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk page                                                            */
    /* ------------------------------------------------------------------ */
    public function render_bulk() {
        ?>
        <div class="sio-wrap">
            <div class="sio-header">
                <div class="sio-logo">
                    <span class="sio-logo-icon">&#128194;</span>
                    <div>
                        <h1>Bulk Image Optimizer</h1>
                        <p>Process your entire media library in one click</p>
                    </div>
                </div>
            </div>

            <div class="sio-card">
                <div class="sio-card-body">
                    <div class="sio-bulk-info">
                        <div class="bulk-stat">
                            <span id="bulk-total">&#8212;</span>
                            <small>Images to optimize</small>
                        </div>
                        <div class="bulk-stat">
                            <span id="bulk-done">0</span>
                            <small>Completed</small>
                        </div>
                        <div class="bulk-stat">
                            <span id="bulk-saved-kb">0 KB</span>
                            <small>Space saved</small>
                        </div>
                    </div>

                    <div class="sio-progress-wrap" id="sio-progress-wrap" style="display:none;">
                        <div class="sio-progress-bar">
                            <div class="sio-progress-fill" id="sio-fill"></div>
                        </div>
                        <div class="sio-progress-label" id="sio-progress-label">Starting&hellip;</div>
                    </div>

                    <div class="sio-bulk-actions">
                        <button class="sio-btn-primary" id="sio-bulk-start">&#9654; Start Bulk Optimization</button>
                        <button class="sio-btn-stop"    id="sio-bulk-stop"  style="display:none;">&#9646;&#9646; Stop</button>
                        <button class="sio-btn-outline" id="sio-bulk-reset">&#8635; Reset &amp; Reprocess All</button>
                    </div>

                    <div id="sio-bulk-log" class="sio-log"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Media Library Column                                                 */
    /* ------------------------------------------------------------------ */
    public function media_column( $columns ) {
        $columns['sio_status'] = '&#9889; Optimized';
        return $columns;
    }

    public function media_column_content( $column, $id ) {
        if ( $column !== 'sio_status' ) return;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sio_log WHERE attachment_id = %d AND status = 'done'",
            $id
        ) );
        if ( $row ) {
            echo '<span class="sio-badge sio-badge-done">&#10003; ' . round( $row->savings_pct, 1 ) . '% saved</span>';
        } else {
            echo '<button class="button sio-optimize-btn" data-id="' . (int) $id . '">Optimize</button>';
        }
    }
}
