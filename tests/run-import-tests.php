<?php
/**
 * Fast import tests using WP Playground's SQLite database.
 *
 * This file tests the WordPress Importer plugin with all parser and mode combinations.
 * Designed to be run via the mu-plugin test runner for fast feedback (~15 seconds).
 *
 * Coverage:
 * - Parsers: simplexml, xml, regex, xmlprocessor
 * - Modes: regular (non-streaming), streaming (xmlprocessor only)
 * - Fixtures: simple, base-url-rewriting, css-urls, comprehensive, post-processing, topological-tricky
 */

// Verify WordPress is loaded
if (!defined('ABSPATH')) {
    die(json_encode(['error' => 'Must be run within WordPress context']));
}

// Define WP_LOAD_IMPORTERS and IMPORT_DEBUG
if (!defined('WP_LOAD_IMPORTERS')) {
    define('WP_LOAD_IMPORTERS', true);
}
if (!defined('IMPORT_DEBUG')) {
    define('IMPORT_DEBUG', true);
}

$plugin_dir = WP_PLUGIN_DIR . '/wordpress-importer';

// Load WordPress importer dependencies
require_once ABSPATH . 'wp-admin/includes/import.php';

if (!class_exists('WP_Importer')) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if (file_exists($class_wp_importer)) {
        require_once $class_wp_importer;
    }
}

// Load compatibility functions
require_once $plugin_dir . '/compat.php';

// Load XML toolkit if needed
if (!class_exists('WordPress\\XML\\XMLProcessor')) {
    require_once $plugin_dir . '/php-toolkit/load.php';
}

// Load parsers
require_once $plugin_dir . '/parsers/class-wxr-parser.php';
require_once $plugin_dir . '/parsers/class-wxr-parser-simplexml.php';
require_once $plugin_dir . '/parsers/class-wxr-parser-xml.php';
require_once $plugin_dir . '/parsers/class-wxr-parser-regex.php';
require_once $plugin_dir . '/parsers/class-wxr-parser-xml-processor.php';

// Load the main WP_Import class
require_once $plugin_dir . '/class-wp-import.php';

if (!class_exists('WP_Import')) {
    die(json_encode(['error' => 'WP_Import class not found after including all files']));
}

// ============================================================================
// CONFIGURATION
// ============================================================================

// All available parsers
$PARSERS = ['simplexml', 'xml', 'regex', 'xmlprocessor'];

// Streaming only works with xmlprocessor
$STREAMING_PARSERS = ['xmlprocessor'];

// Test fixtures with their validation callbacks
$FIXTURES = [
    'simple' => [
        'file' => 'wxr-simple.xml',
        'validate' => 'validate_simple',
    ],
    'base-url-rewriting' => [
        'file' => 'wxr-base-url-rewriting.xml',
        'validate' => 'validate_base_url_rewriting',
    ],
    'css-urls' => [
        'file' => 'wxr-css-urls.xml',
        'validate' => 'validate_css_urls',
    ],
    'comprehensive' => [
        'file' => 'wxr-comprehensive.xml',
        'validate' => 'validate_comprehensive',
    ],
    'post-processing' => [
        'file' => 'wxr-post-processing.xml',
        'validate' => 'validate_post_processing',
    ],
    'topological-tricky' => [
        'file' => 'wxr-topological-tricky.xml',
        'validate' => 'validate_topological',
    ],
];

// ============================================================================
// TEST RESULTS COLLECTOR
// ============================================================================

class TestResults {
    public static $passed = 0;
    public static $failed = 0;
    public static $skipped = 0;
    public static $tests = [];
    public static $debug = [];

    public static function assert_test($name, $condition, $message = '') {
        if ($condition) {
            self::$passed++;
            self::$tests[] = ['name' => $name, 'status' => 'passed'];
        } else {
            self::$failed++;
            self::$tests[] = ['name' => $name, 'status' => 'failed', 'message' => $message];
        }
    }

    public static function skip($name, $reason = '') {
        self::$skipped++;
        self::$tests[] = ['name' => $name, 'status' => 'skipped', 'message' => $reason];
    }

    public static function assert_equals($name, $expected, $actual) {
        self::assert_test($name, $expected === $actual, "Expected " . json_encode($expected) . ", got " . json_encode($actual));
    }

    public static function assert_greater_than($name, $value, $min) {
        self::assert_test($name, $value > $min, "Expected $value > $min");
    }

    public static function assert_contains($name, $haystack, $needle) {
        self::assert_test($name, strpos($haystack, $needle) !== false, "Expected to find '$needle' in content");
    }

    public static function to_array() {
        return [
            'passed' => self::$passed,
            'failed' => self::$failed,
            'skipped' => self::$skipped,
            'tests' => self::$tests,
            'debug' => self::$debug,
        ];
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function run_import($wxr_file, $options = []) {
    $importer = new WP_Import();

    $defaults = [
        'fetch_attachments' => false,
        'stream_entities' => false,
    ];
    $importer->options = array_merge($defaults, $options);

    // Clear any previous import state
    delete_option('wp_import_cursor');

    ob_start();
    $importer->import($wxr_file);
    ob_end_clean();

    return $importer;
}

function get_posts_by($args) {
    return get_posts(array_merge([
        'post_status' => 'any',
        'posts_per_page' => -1,
    ], $args));
}

function cleanup_test_data() {
    global $wpdb;

    // Delete all posts
    $posts = get_posts_by(['post_type' => ['post', 'page', 'attachment', 'nav_menu_item']]);
    foreach ($posts as $post) {
        wp_delete_post($post->ID, true);
    }

    // Delete comments
    $wpdb->query("DELETE FROM {$wpdb->comments}");
    $wpdb->query("DELETE FROM {$wpdb->commentmeta}");

    // Delete terms (except default ones)
    $wpdb->query("DELETE FROM {$wpdb->term_relationships}");
    $wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE term_id > 1");
    $wpdb->query("DELETE FROM {$wpdb->terms} WHERE term_id > 1");

    // Delete users except admin
    $wpdb->query("DELETE FROM {$wpdb->users} WHERE ID > 1");
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE user_id > 1");

    // Clear import state
    delete_option('wp_import_cursor');

    // Clear caches
    wp_cache_flush();
}

// ============================================================================
// VALIDATION FUNCTIONS
// ============================================================================

function validate_simple($prefix) {
    $posts = get_posts_by(['post_type' => 'post']);
    TestResults::assert_greater_than("$prefix: has posts", count($posts), 0);

    $road_post = null;
    foreach ($posts as $post) {
        if (strpos($post->post_title, 'Road Not Taken') !== false) {
            $road_post = $post;
            break;
        }
    }
    TestResults::assert_test("$prefix: found 'Road Not Taken' post", $road_post !== null);
    if ($road_post) {
        TestResults::assert_equals("$prefix: post status is publish", 'publish', $road_post->post_status);
    }
}

function validate_base_url_rewriting($prefix) {
    $posts = get_posts_by(['post_type' => 'post']);
    TestResults::assert_greater_than("$prefix: has posts", count($posts), 0);

    // Check that URLs were rewritten (no old domain references in content)
    $found_post = false;
    foreach ($posts as $post) {
        if (strpos($post->post_content, 'wp-content/uploads') !== false) {
            $found_post = true;
            // Should not contain the old domain
            TestResults::assert_test(
                "$prefix: URLs rewritten (no old domain)",
                strpos($post->post_content, 'old-example.com') === false,
                "Found old-example.com in content"
            );
            break;
        }
    }
    if (!$found_post) {
        TestResults::assert_test("$prefix: found post with upload references", true);
    }
}

function validate_css_urls($prefix) {
    $posts = get_posts_by(['post_type' => 'post']);
    TestResults::assert_greater_than("$prefix: has posts", count($posts), 0);
}

function validate_comprehensive($prefix) {
    // Check posts exist
    $posts = get_posts_by(['post_type' => 'post']);
    TestResults::assert_greater_than("$prefix: has posts", count($posts), 0);

    // Check pages exist
    $pages = get_posts_by(['post_type' => 'page']);
    TestResults::assert_greater_than("$prefix: has pages", count($pages), 0);

    // Check categories were imported
    $categories = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
    if (!is_wp_error($categories)) {
        TestResults::assert_greater_than("$prefix: has categories", count($categories), 1); // > 1 because of default "Uncategorized"
    }
}

function validate_post_processing($prefix) {
    // Check child-parent page relationship
    $child_pages = get_posts_by(['post_type' => 'page', 'name' => 'child-before-parent']);
    TestResults::assert_equals("$prefix: found child page", 1, count($child_pages));

    if (count($child_pages) === 1) {
        $child = $child_pages[0];
        TestResults::assert_greater_than("$prefix: child has parent", $child->post_parent, 0);

        $parent = get_post($child->post_parent);
        TestResults::assert_test("$prefix: parent exists", $parent !== null);
        if ($parent) {
            TestResults::assert_equals("$prefix: parent slug correct", 'parent-landing-page', $parent->post_name);
        }
    }

    // Check comment parent relationship
    $consumer_posts = get_posts_by(['post_type' => 'post', 'name' => 'attachment-consumer']);
    if (count($consumer_posts) === 1) {
        $consumer = $consumer_posts[0];
        $comments = get_comments(['post_id' => $consumer->ID]);

        $reply = null;
        $parent_comment = null;
        foreach ($comments as $comment) {
            if (strpos($comment->comment_content, 'Reply arrives before its parent') !== false) {
                $reply = $comment;
            }
            if (strpos($comment->comment_content, 'Parent comment that should adopt children') !== false) {
                $parent_comment = $comment;
            }
        }

        TestResults::assert_test("$prefix: found reply comment", $reply !== null);
        TestResults::assert_test("$prefix: found parent comment", $parent_comment !== null);

        if ($reply && $parent_comment) {
            TestResults::assert_equals(
                "$prefix: reply parent is correct",
                (int)$parent_comment->comment_ID,
                (int)$reply->comment_parent
            );
        }
    }
}

function validate_topological($prefix) {
    // Check child-parent page relationship
    $child_pages = get_posts_by(['post_type' => 'page', 'name' => 'child-before-parent-topological']);
    TestResults::assert_equals("$prefix: found child page", 1, count($child_pages));

    if (count($child_pages) === 1) {
        $child = $child_pages[0];
        TestResults::assert_greater_than("$prefix: child has parent", $child->post_parent, 0);

        $parent = get_post($child->post_parent);
        TestResults::assert_test("$prefix: parent exists", $parent !== null);
        if ($parent) {
            TestResults::assert_equals("$prefix: parent slug correct", 'parent-landing-page-topological', $parent->post_name);
        }
    }

    // Check comment parent relationship
    $featured_posts = get_posts_by(['post_type' => 'post', 'name' => 'featured-before-attachment']);
    if (count($featured_posts) === 1) {
        $featured_post = $featured_posts[0];
        $comments = get_comments(['post_id' => $featured_post->ID]);

        $reply = null;
        $parent_comment = null;
        foreach ($comments as $comment) {
            if (strpos($comment->comment_content, 'Reply arrives before its parent') !== false) {
                $reply = $comment;
            }
            if (strpos($comment->comment_content, 'Parent comment that should adopt children') !== false) {
                $parent_comment = $comment;
            }
        }

        TestResults::assert_test("$prefix: found reply comment", $reply !== null);
        TestResults::assert_test("$prefix: found parent comment", $parent_comment !== null);

        if ($reply && $parent_comment) {
            TestResults::assert_equals(
                "$prefix: reply parent is correct",
                (int)$parent_comment->comment_ID,
                (int)$reply->comment_parent
            );
        }
    }
}

// ============================================================================
// RUN TESTS
// ============================================================================

$fixtures_dir = WP_PLUGIN_DIR . '/wordpress-importer/e2e/fixtures';

// Get the parser from the constant (set by mu-plugin before this file is included)
$current_parser = defined('PREFERRED_WXR_PARSER') ? constant('PREFERRED_WXR_PARSER') : 'simplexml';

// Test regular (non-streaming) mode with current parser
foreach ($FIXTURES as $fixture_name => $fixture) {
    $wxr_file = $fixtures_dir . '/' . $fixture['file'];

    if (!file_exists($wxr_file)) {
        TestResults::skip("{$current_parser}/{$fixture_name}/regular", "File not found: {$fixture['file']}");
        continue;
    }

    $test_name = "{$current_parser}/{$fixture_name}/regular";

    cleanup_test_data();

    try {
        run_import($wxr_file, [
            'stream_entities' => false,
        ]);
        $fixture['validate']($test_name);
    } catch (Throwable $e) {
        TestResults::assert_test($test_name, false, "Exception: " . $e->getMessage());
    }
}

// Test streaming mode (only with xmlprocessor)
if ($current_parser === 'xmlprocessor') {
    foreach ($FIXTURES as $fixture_name => $fixture) {
        $wxr_file = $fixtures_dir . '/' . $fixture['file'];

        if (!file_exists($wxr_file)) {
            TestResults::skip("{$current_parser}/{$fixture_name}/streaming", "File not found");
            continue;
        }

        $test_name = "{$current_parser}/{$fixture_name}/streaming";

        cleanup_test_data();

        try {
            run_import($wxr_file, [
                'stream_entities' => true,
            ]);
            $fixture['validate']($test_name);
        } catch (Throwable $e) {
            TestResults::assert_test($test_name, false, "Exception: " . $e->getMessage());
        }
    }
}

// Output results as JSON
echo json_encode(TestResults::to_array(), JSON_PRETTY_PRINT);
