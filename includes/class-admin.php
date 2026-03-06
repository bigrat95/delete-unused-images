<?php
defined('ABSPATH') || exit;

class DUI_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_dui_start_scan', [__CLASS__, 'ajax_start_scan']);
        add_action('wp_ajax_dui_scan_batch', [__CLASS__, 'ajax_scan_batch']);
        add_action('wp_ajax_dui_get_results', [__CLASS__, 'ajax_get_results']);
        add_action('wp_ajax_dui_trash_single', [__CLASS__, 'ajax_trash_single']);
        add_action('wp_ajax_dui_trash_bulk', [__CLASS__, 'ajax_trash_bulk']);
        add_action('wp_ajax_dui_trash_all_batch', [__CLASS__, 'ajax_trash_all_batch']);
        add_action('wp_ajax_dui_delete_single', [__CLASS__, 'ajax_delete_single']);
        add_action('wp_ajax_dui_delete_bulk', [__CLASS__, 'ajax_delete_bulk']);
        add_action('wp_ajax_dui_whitelist_single', [__CLASS__, 'ajax_whitelist_single']);
        add_action('wp_ajax_dui_whitelist_bulk', [__CLASS__, 'ajax_whitelist_bulk']);
        add_action('wp_ajax_dui_remove_whitelist', [__CLASS__, 'ajax_remove_whitelist']);
        add_action('wp_ajax_dui_remove_whitelist_bulk', [__CLASS__, 'ajax_remove_whitelist_bulk']);
        add_action('wp_ajax_dui_restore_single', [__CLASS__, 'ajax_restore_single']);
        add_action('wp_ajax_dui_restore_bulk', [__CLASS__, 'ajax_restore_bulk']);
        add_action('wp_ajax_dui_save_cron_settings', [__CLASS__, 'ajax_save_cron_settings']);

        // Cron hook
        add_action('dui_scheduled_cleanup', [__CLASS__, 'run_scheduled_cleanup']);
    }

    public static function activate() {
        update_option('dui_version', DUI_VERSION);
        // Initialize whitelist as empty
        if (false === get_option('dui_whitelist')) {
            update_option('dui_whitelist', [], false);
        }
    }

    public static function deactivate() {
        delete_option('dui_scan_results');
        delete_option('dui_scan_used_ids');
        delete_option('dui_scan_date');
        wp_clear_scheduled_hook('dui_scheduled_cleanup');
    }

    public static function add_menu() {
        add_submenu_page(
            'upload.php',
            __('Delete Unused Images', 'delete-unused-images'),
            __('Unused Images', 'delete-unused-images'),
            'manage_options',
            'delete-unused-images',
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'media_page_delete-unused-images') return;

        wp_enqueue_style(
            'dui-admin-css',
            DUI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DUI_VERSION
        );

        wp_enqueue_script(
            'dui-admin-js',
            DUI_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            DUI_VERSION,
            true
        );

        wp_localize_script('dui-admin-js', 'duiObj', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('dui_nonce'),
            'strings' => [
                'scanning'       => __('Scanning...', 'delete-unused-images'),
                'scan_complete'  => __('Scan complete!', 'delete-unused-images'),
                'confirm_trash'  => __('Trash this file?', 'delete-unused-images'),
                'confirm_delete' => __('Permanently delete this file? This cannot be undone.', 'delete-unused-images'),
                'confirm_bulk_trash'  => __('Trash all selected files?', 'delete-unused-images'),
                'confirm_bulk_delete' => __('Permanently delete all selected files? This cannot be undone.', 'delete-unused-images'),
                'no_selection'        => __('No files selected.', 'delete-unused-images'),
                'confirm_trash_all'   => __('Trash ALL unused images? This will process all pages in batches.', 'delete-unused-images'),
            ],
        ]);
    }

    // ─── Page render ──────────────────────────────────────────────────

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        $scan_date = get_option('dui_scan_date', '');
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'unused';
        $whitelist = get_option('dui_whitelist', []);
        $base_url = admin_url('upload.php?page=delete-unused-images');
        ?>
        <div class="wrap">
            <h1><?php _e('Delete Unused Images', 'delete-unused-images'); ?></h1>

            <div class="postbox" style="margin-top:20px;">
                <div class="inside" id="dui-stats">
                    <?php self::render_stats(); ?>
                </div>
            </div>

            <p>
                <button type="button" id="dui-scan-btn" class="button button-primary">
                    <?php _e('Scan for Unused Media', 'delete-unused-images'); ?>
                </button>
                <?php if ($scan_date): ?>
                    <span class="description" style="margin-left:10px;">
                        <?php printf(__('Last scan: %s', 'delete-unused-images'), date_i18n('M j, Y g:i a', strtotime($scan_date))); ?>
                    </span>
                <?php endif; ?>
            </p>

            <div id="dui-progress-wrap" style="display:none;margin-bottom:15px;">
                <div style="background:#e0e0e0;height:20px;border-radius:3px;overflow:hidden;max-width:500px;">
                    <div id="dui-progress-fill" style="background:#2271b1;height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <p class="description" id="dui-progress-text">0%</p>
            </div>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url($base_url . '&tab=unused'); ?>"
                   class="nav-tab <?php echo $tab === 'unused' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Unused', 'delete-unused-images'); ?>
                    <span class="count" id="dui-unused-count">(0)</span>
                </a>
                <a href="<?php echo esc_url($base_url . '&tab=whitelist'); ?>"
                   class="nav-tab <?php echo $tab === 'whitelist' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Whitelist', 'delete-unused-images'); ?>
                    <span class="count" id="dui-whitelist-count">(<?php echo count($whitelist); ?>)</span>
                </a>
                <a href="<?php echo esc_url($base_url . '&tab=trash'); ?>"
                   class="nav-tab <?php echo $tab === 'trash' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Trash', 'delete-unused-images'); ?>
                    <span class="count" id="dui-trash-count">(<?php echo wp_count_posts('attachment')->trash; ?>)</span>
                </a>
            </nav>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <label><input type="checkbox" id="dui-select-all"> <?php _e('Select All', 'delete-unused-images'); ?></label>
                    <?php if ($tab === 'unused'): ?>
                        <button type="button" class="button" id="dui-bulk-trash-btn"><?php _e('Trash Selected', 'delete-unused-images'); ?></button>
                        <button type="button" class="button" id="dui-bulk-whitelist-btn"><?php _e('Whitelist Selected', 'delete-unused-images'); ?></button>
                        <button type="button" class="button" id="dui-trash-all-btn" style="color:#b32d2e;"><?php _e('Trash All Unused', 'delete-unused-images'); ?></button>
                    <?php elseif ($tab === 'whitelist'): ?>
                        <button type="button" class="button" id="dui-bulk-remove-whitelist-btn"><?php _e('Remove from Whitelist', 'delete-unused-images'); ?></button>
                    <?php elseif ($tab === 'trash'): ?>
                        <button type="button" class="button" id="dui-bulk-restore-btn"><?php _e('Restore Selected', 'delete-unused-images'); ?></button>
                        <button type="button" class="button" id="dui-bulk-delete-btn"><?php _e('Delete Permanently', 'delete-unused-images'); ?></button>
                    <?php endif; ?>
                    <span id="dui-selected-info" class="description" style="margin-left:8px;"></span>
                </div>
                <div class="alignright">
                    <select id="dui-filter-type" style="vertical-align:middle;">
                        <option value=""><?php _e('All Types', 'delete-unused-images'); ?></option>
                        <option value="jpg"><?php _e('JPG', 'delete-unused-images'); ?></option>
                        <option value="jpeg"><?php _e('JPEG', 'delete-unused-images'); ?></option>
                        <option value="png"><?php _e('PNG', 'delete-unused-images'); ?></option>
                        <option value="gif"><?php _e('GIF', 'delete-unused-images'); ?></option>
                        <option value="webp"><?php _e('WebP', 'delete-unused-images'); ?></option>
                        <option value="svg"><?php _e('SVG', 'delete-unused-images'); ?></option>
                        <option value="pdf"><?php _e('PDF', 'delete-unused-images'); ?></option>
                        <option value="mp4"><?php _e('MP4', 'delete-unused-images'); ?></option>
                    </select>
                    <input type="search" id="dui-search" placeholder="<?php esc_attr_e('Search files...', 'delete-unused-images'); ?>" style="vertical-align:middle;">
                    <button type="button" id="dui-search-btn" class="button"><?php _e('Search', 'delete-unused-images'); ?></button>
                </div>
            </div>

            <div id="dui-results">
                <?php self::render_results_table($tab); ?>
            </div>

            <div class="tablenav bottom" id="dui-pagination"></div>

            <div class="postbox" style="margin-top:30px;">
                <div class="postbox-header"><h2 style="padding:8px 12px;margin:0;"><?php _e('Scheduled Auto-Cleanup', 'delete-unused-images'); ?></h2></div>
                <div class="inside">
                    <?php
                    $cron_enabled = get_option('dui_cron_enabled', false);
                    $cron_frequency = get_option('dui_cron_frequency', 'daily');
                    $next_run = wp_next_scheduled('dui_scheduled_cleanup');
                    ?>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Enable Auto-Cleanup', 'delete-unused-images'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="dui-cron-enabled" <?php checked($cron_enabled); ?>>
                                    <?php _e('Automatically scan and trash unused images on a schedule', 'delete-unused-images'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Frequency', 'delete-unused-images'); ?></th>
                            <td>
                                <select id="dui-cron-frequency">
                                    <option value="daily" <?php selected($cron_frequency, 'daily'); ?>><?php _e('Daily', 'delete-unused-images'); ?></option>
                                    <option value="twicedaily" <?php selected($cron_frequency, 'twicedaily'); ?>><?php _e('Twice Daily', 'delete-unused-images'); ?></option>
                                    <option value="weekly" <?php selected($cron_frequency, 'weekly'); ?>><?php _e('Weekly', 'delete-unused-images'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Next Scheduled Run', 'delete-unused-images'); ?></th>
                            <td>
                                <span id="dui-next-run">
                                    <?php echo $next_run ? date_i18n('M j, Y g:i a', $next_run) : __('Not scheduled', 'delete-unused-images'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="dui-save-cron-btn" class="button button-primary"><?php _e('Save Settings', 'delete-unused-images'); ?></button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_stats() {
        $scan_results = get_option('dui_scan_results', []);
        $total = (new DUI_Scanner())->get_total_attachment_count();
        $unused_count = count($scan_results);
        $used_count = $total - $unused_count;
        $whitelist = get_option('dui_whitelist', []);

        $unused_size = 0;
        if (!empty($scan_results)) {
            foreach ($scan_results as $item) {
                $unused_size += (int)($item['file_size'] ?? 0);
            }
        }
        ?>
        <table class="form-table">
            <tr>
                <th><?php _e('Total Media', 'delete-unused-images'); ?></th>
                <td><strong><?php echo number_format_i18n($total); ?></strong></td>
                <th><?php _e('In Use', 'delete-unused-images'); ?></th>
                <td><strong><?php echo number_format_i18n($used_count); ?></strong></td>
                <th><?php _e('Unused', 'delete-unused-images'); ?></th>
                <td><strong><?php echo number_format_i18n($unused_count); ?></strong></td>
            </tr>
            <tr>
                <th><?php _e('Space to Free', 'delete-unused-images'); ?></th>
                <td><strong><?php echo size_format($unused_size); ?></strong></td>
                <th><?php _e('Whitelisted', 'delete-unused-images'); ?></th>
                <td><strong><?php echo number_format_i18n(count($whitelist)); ?></strong></td>
                <td colspan="2"></td>
            </tr>
        </table>
        <?php
    }

    private static function render_results_table($tab, $page = 1, $per_page = 20, $search = '', $orderby = 'date', $order = 'desc', $filter_type = '') {
        $scanner = new DUI_Scanner();
        $all_items = [];
        $total_items = 0;

        if ($tab === 'unused') {
            $scan_results = get_option('dui_scan_results', []);
            $whitelist = get_option('dui_whitelist', []);
            $scan_results = array_filter($scan_results, function($item) use ($whitelist) {
                return !in_array((int)$item['id'], $whitelist, true);
            });
            $all_items = array_values($scan_results);

        } elseif ($tab === 'whitelist') {
            $whitelist = get_option('dui_whitelist', []);
            foreach ($whitelist as $id) {
                $info = $scanner->get_attachment_info($id);
                if ($info) $all_items[] = $info;
            }

        } elseif ($tab === 'trash') {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'trash' ORDER BY ID ASC"
            );
            foreach ($rows as $row) {
                $info = $scanner->get_attachment_info($row->ID);
                if ($info) $all_items[] = $info;
            }
        }

        // Filter by search
        if ($search) {
            $search_lower = strtolower($search);
            $all_items = array_filter($all_items, function($item) use ($search_lower) {
                return strpos(strtolower($item['title'] ?? ''), $search_lower) !== false
                    || strpos(strtolower($item['url'] ?? ''), $search_lower) !== false
                    || strpos(strtolower($item['ext'] ?? ''), $search_lower) !== false
                    || strpos((string)($item['id'] ?? ''), $search_lower) !== false;
            });
        }

        // Filter by file type
        if ($filter_type) {
            $filter_lower = strtolower($filter_type);
            $all_items = array_filter($all_items, function($item) use ($filter_lower) {
                return strtolower($item['ext'] ?? '') === $filter_lower;
            });
        }

        $all_items = array_values($all_items);

        // Sort
        if ($orderby && !empty($all_items)) {
            usort($all_items, function($a, $b) use ($orderby, $order) {
                switch ($orderby) {
                    case 'name':
                        $cmp = strcasecmp($a['title'] ?? '', $b['title'] ?? '');
                        break;
                    case 'size':
                        $cmp = ((int)($a['file_size'] ?? 0)) - ((int)($b['file_size'] ?? 0));
                        break;
                    case 'type':
                        $cmp = strcasecmp($a['ext'] ?? '', $b['ext'] ?? '');
                        break;
                    case 'date':
                    default:
                        $cmp = strcmp($a['date'] ?? '', $b['date'] ?? '');
                        break;
                }
                return $order === 'asc' ? $cmp : -$cmp;
            });
        }

        $total_items = count($all_items);
        $items = array_slice($all_items, ($page - 1) * $per_page, $per_page);
        $total_pages = ceil($total_items / $per_page);

        if (empty($items)) {
            echo '<p class="description">';
            if ($tab === 'unused') {
                _e('No unused media found. Run a scan to detect unused files.', 'delete-unused-images');
            } elseif ($tab === 'whitelist') {
                _e('No whitelisted items.', 'delete-unused-images');
            } else {
                _e('Trash is empty.', 'delete-unused-images');
            }
            echo '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th class="check-column"><input type="checkbox" class="dui-select-all-header"></th>';
        echo '<th>' . __('File', 'delete-unused-images') . '</th>';
        echo '<th class="dui-sortable" data-sort="name" style="cursor:pointer;">' . __('Name', 'delete-unused-images') . self::sort_indicator('name', $orderby, $order) . '</th>';
        echo '<th class="dui-sortable" data-sort="size" style="cursor:pointer;">' . __('Size', 'delete-unused-images') . self::sort_indicator('size', $orderby, $order) . '</th>';
        echo '<th class="dui-sortable" data-sort="type" style="cursor:pointer;">' . __('Type', 'delete-unused-images') . self::sort_indicator('type', $orderby, $order) . '</th>';
        echo '<th class="dui-sortable" data-sort="date" style="cursor:pointer;">' . __('Date', 'delete-unused-images') . self::sort_indicator('date', $orderby, $order) . '</th>';
        echo '<th>' . __('Actions', 'delete-unused-images') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $id = (int) $item['id'];
            $size_formatted = size_format($item['file_size']);
            $date_formatted = date_i18n('Y/m/d', strtotime($item['date']));
            $thumb = self::get_thumb_html($item);
            $edit_url = admin_url("post.php?post={$id}&action=edit");

            echo '<tr data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '">';
            echo '<th class="check-column"><input type="checkbox" class="dui-item-cb" value="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '"></th>';
            echo '<td>' . $thumb . '</td>';
            echo '<td><strong>' . esc_html($item['title']) . '</strong><br><span class="description">' . esc_html(wp_basename($item['url'] ?? '')) . '</span></td>';
            echo '<td>' . esc_html($size_formatted) . '</td>';
            echo '<td><code>' . esc_html(strtoupper($item['ext'])) . '</code></td>';
            echo '<td>' . esc_html($date_formatted) . '</td>';
            echo '<td>';

            if ($tab === 'unused') {
                echo '<a href="' . esc_url($item['url']) . '" target="_blank" class="button button-small">' . __('View', 'delete-unused-images') . '</a> ';
                echo '<a href="' . esc_url($edit_url) . '" target="_blank" class="button button-small">' . __('Edit', 'delete-unused-images') . '</a> ';
                echo '<button type="button" class="button button-small dui-whitelist-btn" data-id="' . esc_attr($id) . '">' . __('Whitelist', 'delete-unused-images') . '</button> ';
                echo '<button type="button" class="button button-small dui-trash-btn" data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '">' . __('Trash', 'delete-unused-images') . '</button>';
            } elseif ($tab === 'whitelist') {
                echo '<a href="' . esc_url($item['url']) . '" target="_blank" class="button button-small">' . __('View', 'delete-unused-images') . '</a> ';
                echo '<button type="button" class="button button-small dui-remove-whitelist-btn" data-id="' . esc_attr($id) . '">' . __('Remove', 'delete-unused-images') . '</button>';
            } elseif ($tab === 'trash') {
                echo '<button type="button" class="button button-small dui-restore-btn" data-id="' . esc_attr($id) . '">' . __('Restore', 'delete-unused-images') . '</button> ';
                echo '<button type="button" class="button button-small dui-delete-btn" data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '" style="color:#b32d2e;">' . __('Delete', 'delete-unused-images') . '</button>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<script>document.getElementById("dui-pagination").dataset.totalPages = ' . (int) $total_pages . '; document.getElementById("dui-pagination").dataset.currentPage = ' . (int) $page . '; document.getElementById("dui-pagination").dataset.totalItems = ' . (int) $total_items . ';</script>';
    }

    private static function sort_indicator($col, $orderby, $order) {
        if ($col !== $orderby) {
            return ' <span style="color:#c3c4c7;">&#x25B5;&#x25BF;</span>';
        }
        $arrow = $order === 'asc' ? '&#x25B4;' : '&#x25BE;';
        return ' <span>' . $arrow . '</span>';
    }

    private static function get_thumb_html($item) {
        $mime = $item['mime'] ?? '';
        if (strpos($mime, 'image') !== false && !empty($item['thumb_url'])) {
            return '<img src="' . esc_url($item['thumb_url']) . '" alt="" width="40" height="40" style="object-fit:cover;">';
        }
        return wp_get_attachment_image($item['id'], [40, 40]) ?: '<span class="dashicons dashicons-media-default" style="font-size:20px;color:#8c8f94;"></span>';
    }

    // ─── AJAX handlers ────────────────────────────────────────────────

    private static function verify_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        if (!check_ajax_referer('dui_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce.');
        }
    }

    /**
     * Step 1: Start scan — collect used IDs (heavy query, done once).
     */
    public static function ajax_start_scan() {
        self::verify_request();

        $scanner = new DUI_Scanner();
        $used_ids = $scanner->collect_used_ids();
        $total = $scanner->get_total_attachment_count();

        // Store used IDs for batch processing
        update_option('dui_scan_used_ids', $used_ids, false);
        // Clear old results
        delete_option('dui_scan_results');

        wp_send_json_success([
            'total'    => $total,
            'used'     => count($used_ids),
            'message'  => sprintf(__('Found %d used media. Scanning %d total attachments...', 'delete-unused-images'), count($used_ids), $total),
        ]);
    }

    /**
     * Step 2: Process a batch of attachments — check if unused.
     */
    public static function ajax_scan_batch() {
        self::verify_request();

        $offset = (int) ($_POST['offset'] ?? 0);
        $batch_size = 50;

        $scanner = new DUI_Scanner();
        $used_ids = get_option('dui_scan_used_ids', []);
        $whitelist = get_option('dui_whitelist', []);

        global $wpdb;
        $attachment_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status != 'trash'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $batch_size, $offset
        ));

        $unused_batch = [];
        foreach ($attachment_ids as $id) {
            $id = (int) $id;
            if (!in_array($id, $used_ids, true) && !in_array($id, $whitelist, true)) {
                $info = $scanner->get_attachment_info($id);
                if ($info) {
                    $unused_batch[] = $info;
                }
            }
        }

        // Append to stored results
        $existing = get_option('dui_scan_results', []);
        $existing = array_merge($existing, $unused_batch);
        update_option('dui_scan_results', $existing, false);

        $processed = $offset + count($attachment_ids);
        $total = $scanner->get_total_attachment_count();
        $done = count($attachment_ids) < $batch_size;

        if ($done) {
            update_option('dui_scan_date', current_time('mysql'), false);
            // Cleanup temp
            delete_option('dui_scan_used_ids');
        }

        wp_send_json_success([
            'processed'    => $processed,
            'total'        => $total,
            'unused_found' => count($existing),
            'done'         => $done,
        ]);
    }

    /**
     * Get paginated results (refreshes table via AJAX).
     */
    public static function ajax_get_results() {
        self::verify_request();

        $tab = sanitize_text_field($_POST['tab'] ?? 'unused');
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $search = sanitize_text_field($_POST['search'] ?? '');
        $orderby = sanitize_text_field($_POST['orderby'] ?? 'date');
        $order = sanitize_text_field($_POST['order'] ?? 'desc');
        $filter_type = sanitize_text_field($_POST['filter_type'] ?? '');

        if (!in_array($orderby, ['name', 'size', 'type', 'date'], true)) $orderby = 'date';
        if (!in_array($order, ['asc', 'desc'], true)) $order = 'desc';

        ob_start();
        self::render_results_table($tab, $page, 20, $search, $orderby, $order, $filter_type);
        $html = ob_get_clean();

        ob_start();
        self::render_stats();
        $stats_html = ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'stats' => $stats_html,
        ]);
    }

    /**
     * Trash a single unused file.
     */
    public static function ajax_trash_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $result = wp_trash_post($post_id);
        if ($result) {
            self::remove_from_scan_results($post_id);
            wp_send_json_success(['message' => __('File moved to trash.', 'delete-unused-images')]);
        }
        wp_send_json_error(__('Could not trash file.', 'delete-unused-images'));
    }

    /**
     * Trash multiple files.
     */
    public static function ajax_trash_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $trashed = 0;
        foreach ($ids as $id) {
            if (wp_trash_post($id)) {
                self::remove_from_scan_results($id);
                $trashed++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('%d files moved to trash.', 'delete-unused-images'), $trashed),
            'count'   => $trashed,
        ]);
    }

    /**
     * Permanently delete a single file.
     */
    public static function ajax_delete_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $result = wp_delete_attachment($post_id, true);
        if ($result) {
            self::remove_from_scan_results($post_id);
            wp_send_json_success(['message' => __('File permanently deleted.', 'delete-unused-images')]);
        }
        wp_send_json_error(__('Could not delete file.', 'delete-unused-images'));
    }

    /**
     * Permanently delete multiple files.
     */
    public static function ajax_delete_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $deleted = 0;
        foreach ($ids as $id) {
            if (wp_delete_attachment($id, true)) {
                self::remove_from_scan_results($id);
                $deleted++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('%d files permanently deleted.', 'delete-unused-images'), $deleted),
            'count'   => $deleted,
        ]);
    }

    /**
     * Add to whitelist.
     */
    public static function ajax_whitelist_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $whitelist = get_option('dui_whitelist', []);
        if (!in_array($post_id, $whitelist, true)) {
            $whitelist[] = $post_id;
            update_option('dui_whitelist', $whitelist, false);
        }

        wp_send_json_success(['message' => __('Added to whitelist.', 'delete-unused-images')]);
    }

    /**
     * Add multiple to whitelist.
     */
    public static function ajax_whitelist_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $whitelist = get_option('dui_whitelist', []);
        $added = 0;

        foreach ($ids as $id) {
            if ($id > 0 && !in_array($id, $whitelist, true)) {
                $whitelist[] = $id;
                $added++;
            }
        }
        update_option('dui_whitelist', $whitelist, false);

        wp_send_json_success([
            'message' => sprintf(__('%d items added to whitelist.', 'delete-unused-images'), $added),
            'count'   => $added,
        ]);
    }

    /**
     * Remove from whitelist.
     */
    public static function ajax_remove_whitelist() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $whitelist = get_option('dui_whitelist', []);
        $whitelist = array_values(array_diff($whitelist, [$post_id]));
        update_option('dui_whitelist', $whitelist, false);

        wp_send_json_success(['message' => __('Removed from whitelist.', 'delete-unused-images')]);
    }

    /**
     * Remove multiple from whitelist.
     */
    public static function ajax_remove_whitelist_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $whitelist = get_option('dui_whitelist', []);
        $whitelist = array_values(array_diff($whitelist, $ids));
        update_option('dui_whitelist', $whitelist, false);

        wp_send_json_success([
            'message' => sprintf(__('%d items removed from whitelist.', 'delete-unused-images'), count($ids)),
            'count'   => count($ids),
        ]);
    }

    /**
     * Restore from trash.
     */
    public static function ajax_restore_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $result = wp_untrash_post($post_id);
        if ($result) {
            wp_send_json_success(['message' => __('File restored.', 'delete-unused-images')]);
        }
        wp_send_json_error(__('Could not restore file.', 'delete-unused-images'));
    }

    /**
     * Restore multiple from trash.
     */
    public static function ajax_restore_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $restored = 0;
        foreach ($ids as $id) {
            if (wp_untrash_post($id)) {
                $restored++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('%d files restored.', 'delete-unused-images'), $restored),
            'count'   => $restored,
        ]);
    }

    /**
     * Trash all unused in batches (AJAX).
     */
    public static function ajax_trash_all_batch() {
        self::verify_request();

        $batch_size = 50;
        $scan_results = get_option('dui_scan_results', []);
        $whitelist = get_option('dui_whitelist', []);

        // Filter out whitelisted
        $scan_results = array_filter($scan_results, function($item) use ($whitelist) {
            return !in_array((int)$item['id'], $whitelist, true);
        });
        $scan_results = array_values($scan_results);

        $total = count($scan_results);
        $batch = array_slice($scan_results, 0, $batch_size);
        $trashed = 0;

        foreach ($batch as $item) {
            if (wp_trash_post((int)$item['id'])) {
                self::remove_from_scan_results((int)$item['id']);
                $trashed++;
            }
        }

        $remaining = $total - $trashed;

        wp_send_json_success([
            'trashed'   => $trashed,
            'remaining' => max(0, $remaining),
            'total'     => $total,
            'done'      => $remaining <= 0,
        ]);
    }

    /**
     * Save cron settings.
     */
    public static function ajax_save_cron_settings() {
        self::verify_request();

        $enabled = !empty($_POST['enabled']);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');

        if (!in_array($frequency, ['daily', 'twicedaily', 'weekly'], true)) {
            $frequency = 'daily';
        }

        update_option('dui_cron_enabled', $enabled, false);
        update_option('dui_cron_frequency', $frequency, false);

        // Clear existing schedule
        wp_clear_scheduled_hook('dui_scheduled_cleanup');

        $next_run = '';
        if ($enabled) {
            wp_schedule_event(time() + 60, $frequency, 'dui_scheduled_cleanup');
            $next_run = date_i18n('M j, Y g:i a', wp_next_scheduled('dui_scheduled_cleanup'));
        }

        wp_send_json_success([
            'message'  => $enabled
                ? sprintf(__('Auto-cleanup enabled (%s). Next run: %s', 'delete-unused-images'), $frequency, $next_run)
                : __('Auto-cleanup disabled.', 'delete-unused-images'),
            'next_run' => $next_run ?: __('Not scheduled', 'delete-unused-images'),
        ]);
    }

    /**
     * Cron callback: scan + trash unused images.
     */
    public static function run_scheduled_cleanup() {
        $scanner = new DUI_Scanner();
        $used_ids = $scanner->collect_used_ids();
        $whitelist = get_option('dui_whitelist', []);
        $total = $scanner->get_total_attachment_count();

        global $wpdb;
        $batch_size = 100;
        $offset = 0;
        $all_unused = [];

        while (true) {
            $attachment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_status != 'trash'
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                $batch_size, $offset
            ));

            if (empty($attachment_ids)) break;

            foreach ($attachment_ids as $id) {
                $id = (int) $id;
                if (!in_array($id, $used_ids, true) && !in_array($id, $whitelist, true)) {
                    $info = $scanner->get_attachment_info($id);
                    if ($info) {
                        $all_unused[] = $info;
                        wp_trash_post($id);
                    }
                }
            }

            $offset += $batch_size;
            if (count($attachment_ids) < $batch_size) break;
        }

        // Update scan results and date
        update_option('dui_scan_results', [], false);
        update_option('dui_scan_date', current_time('mysql'), false);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private static function remove_from_scan_results($post_id) {
        $results = get_option('dui_scan_results', []);
        $results = array_filter($results, function($item) use ($post_id) {
            return (int) $item['id'] !== (int) $post_id;
        });
        update_option('dui_scan_results', array_values($results), false);
    }
}
