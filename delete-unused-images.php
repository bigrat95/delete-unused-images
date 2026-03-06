<?php
/**
 * Plugin Name: Delete Unused Images
 * Plugin URI: https://github.com/bigrat95/delete-unused-images
 * Description: Scan and remove unused media files from your WordPress site to free up disk space. Deep scans post content, ACF fields, WooCommerce, Elementor, theme files, widgets, and more.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: delete-unused-images
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('DUI_VERSION', '1.0.0');
define('DUI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DUI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DUI_BASENAME', plugin_basename(__FILE__));

require_once DUI_PLUGIN_DIR . 'includes/class-scanner.php';
require_once DUI_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, ['DUI_Admin', 'activate']);
register_deactivation_hook(__FILE__, ['DUI_Admin', 'deactivate']);

// Boot
DUI_Admin::init();
