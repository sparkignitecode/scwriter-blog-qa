<?php
/**
 * Plugin Name: SCwriter Blog QA
 * Plugin URI: https://sparkmembership.com
 * Description: SEO QA checks for WordPress blog posts generated with SCwriter.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Tested up to: 6.8.3
 * Requires PHP: 8.0
 * Author: sparkmembership.com
 * Author URI: https://sparkmembership.com/
 * License: GPLv2 or later
 * Text Domain: scwriter-blog-qa
 * Domain Path: /languages/
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

define( 'BLOGQA_NAME', 'SCwriter Blog QA' );
define( 'BLOGQA_PREFIX', 'blogqa' );
define( 'BLOGQA_VERSION', '0.1.0' );
define( 'BLOGQA_FILE', __FILE__ );
define( 'BLOGQA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BLOGQA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/src/html_document.php';
require_once __DIR__ . '/src/post_data.php';
require_once __DIR__ . '/src/pillar_post_fetcher.php';
require_once __DIR__ . '/src/link_classifier.php';
require_once __DIR__ . '/src/checks/base.php';
require_once __DIR__ . '/src/checks/keyword_placement.php';
require_once __DIR__ . '/src/checks/content_quality.php';
require_once __DIR__ . '/src/checks/metadata.php';
require_once __DIR__ . '/src/checks/images.php';
require_once __DIR__ . '/src/checks/location.php';
require_once __DIR__ . '/src/checks/keyword_cluster.php';
require_once __DIR__ . '/src/checks/ai_strategy.php';
require_once __DIR__ . '/src/checks/pillar_post.php';
require_once __DIR__ . '/src/checker.php';
require_once __DIR__ . '/src/api/qa_endpoint.php';
require_once __DIR__ . '/src/api/spark_seo_endpoint.php';
require_once __DIR__ . '/src/dashboard.php';
require_once __DIR__ . '/src/wp.php';

new BlogQA\BlogQA_WP();
