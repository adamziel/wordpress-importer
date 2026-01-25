<?php
/**
 * Fast import tests using WP Playground's SQLite database.
 *
 * This file is designed to be included by the mu-plugin test runner.
 * It tests the WordPress Importer plugin's streaming import functionality.
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

// Test results collector using a class to avoid global variable issues
class TestResults {
    public static $passed = 0;
    public static $failed = 0;
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

    public static function assert_equals($name, $expected, $actual) {
        self::assert_test($name, $expected === $actual, "Expected " . json_encode($expected) . ", got " . json_encode($actual));
    }

    public static function assert_greater_than($name, $value, $min) {
        self::assert_test($name, $value > $min, "Expected $value > $min");
    }

    public static function to_array() {
        return [
            'passed' => self::$passed,
            'failed' => self::$failed,
            'tests' => self::$tests,
            'debug' => self::$debug,
        ];
    }
}

// Shorthand functions
function assert_test($name, $condition, $message = '') {
    TestResults::assert_test($name, $condition, $message);
}

function assert_equals($name, $expected, $actual) {
    TestResults::assert_equals($name, $expected, $actual);
}

function assert_greater_than($name, $value, $min) {
    TestResults::assert_greater_than($name, $value, $min);
}

// Helper to run an import
function run_import($wxr_file, $options = []) {
    $importer = new WP_Import();

    $defaults = [
        'fetch_attachments' => false,
        'stream_entities' => true,
    ];
    $importer->options = array_merge($defaults, $options);

    // Clear any previous import state
    delete_option('wp_import_cursor');

    ob_start();
    $importer->import($wxr_file);
    ob_end_clean();

    return $importer;
}

// Helper to get posts by criteria
function get_posts_by($args) {
    return get_posts(array_merge([
        'post_status' => 'any',
        'posts_per_page' => -1,
    ], $args));
}

// Helper to clean up between tests
function cleanup_test_data() {
    global $wpdb;

    $posts = get_posts_by(['post_type' => ['post', 'page', 'attachment']]);
    foreach ($posts as $post) {
        wp_delete_post($post->ID, true);
    }
    $wpdb->query("DELETE FROM {$wpdb->comments}");
    $wpdb->query("DELETE FROM {$wpdb->commentmeta}");
    delete_option('wp_import_cursor');
}

// ============================================================================
// TEST: Simple import with streaming
// ============================================================================
$wxr_simple = WP_PLUGIN_DIR . '/wordpress-importer/e2e/fixtures/wxr-simple.xml';
TestResults::$debug['wxr_simple_path'] = $wxr_simple;
TestResults::$debug['wxr_simple_exists'] = file_exists($wxr_simple);

if (file_exists($wxr_simple)) {
    TestResults::$debug['running_simple_test'] = true;

    try {
        run_import($wxr_simple, ['stream_entities' => true]);
        TestResults::$debug['import_completed'] = true;
    } catch (Throwable $e) {
        TestResults::$debug['import_error'] = $e->getMessage();
        TestResults::$debug['import_error_trace'] = $e->getTraceAsString();
    }

    $posts = get_posts_by(['post_type' => 'post']);
    TestResults::$debug['posts_count'] = count($posts);
    assert_greater_than('simple_streaming: has posts', count($posts), 0);

    $road_post = null;
    foreach ($posts as $post) {
        if (strpos($post->post_title, 'Road Not Taken') !== false) {
            $road_post = $post;
            break;
        }
    }
    assert_test('simple_streaming: found "Road Not Taken" post', $road_post !== null);
    if ($road_post) {
        assert_equals('simple_streaming: post status is publish', 'publish', $road_post->post_status);
    }
} else {
    TestResults::$tests[] = ['name' => 'simple_streaming', 'status' => 'skipped', 'message' => 'File not found: ' . $wxr_simple];
}

cleanup_test_data();

// ============================================================================
// TEST: Post-processing with comment parent backfilling (streaming)
// ============================================================================
$wxr_post_processing = WP_PLUGIN_DIR . '/wordpress-importer/e2e/fixtures/wxr-post-processing.xml';
if (file_exists($wxr_post_processing)) {
    run_import($wxr_post_processing, ['stream_entities' => true]);

    // Check child-parent page relationship
    $child_pages = get_posts_by(['post_type' => 'page', 'name' => 'child-before-parent']);
    assert_equals('post_processing_streaming: found child page', 1, count($child_pages));

    if (count($child_pages) === 1) {
        $child = $child_pages[0];
        assert_greater_than('post_processing_streaming: child has parent', $child->post_parent, 0);

        $parent = get_post($child->post_parent);
        assert_test('post_processing_streaming: parent exists', $parent !== null);
        if ($parent) {
            assert_equals('post_processing_streaming: parent slug', 'parent-landing-page', $parent->post_name);
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

        assert_test('post_processing_streaming: found reply comment', $reply !== null);
        assert_test('post_processing_streaming: found parent comment', $parent_comment !== null);

        if ($reply && $parent_comment) {
            assert_equals(
                'post_processing_streaming: reply parent is correct',
                (int)$parent_comment->comment_ID,
                (int)$reply->comment_parent
            );
        }
    }
} else {
    TestResults::$tests[] = ['name' => 'post_processing_streaming', 'status' => 'skipped', 'message' => 'File not found'];
}

cleanup_test_data();

// ============================================================================
// TEST: Post-processing with comment parent backfilling (non-streaming/regular)
// ============================================================================
if (file_exists($wxr_post_processing)) {
    run_import($wxr_post_processing, ['stream_entities' => false]);

    // Check child-parent page relationship
    $child_pages = get_posts_by(['post_type' => 'page', 'name' => 'child-before-parent']);
    assert_equals('post_processing_regular: found child page', 1, count($child_pages));

    if (count($child_pages) === 1) {
        $child = $child_pages[0];
        assert_greater_than('post_processing_regular: child has parent', $child->post_parent, 0);

        $parent = get_post($child->post_parent);
        assert_test('post_processing_regular: parent exists', $parent !== null);
        if ($parent) {
            assert_equals('post_processing_regular: parent slug', 'parent-landing-page', $parent->post_name);
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

        assert_test('post_processing_regular: found reply comment', $reply !== null);
        assert_test('post_processing_regular: found parent comment', $parent_comment !== null);

        if ($reply && $parent_comment) {
            assert_equals(
                'post_processing_regular: reply parent is correct',
                (int)$parent_comment->comment_ID,
                (int)$reply->comment_parent
            );
        }
    }
}

cleanup_test_data();

// ============================================================================
// TEST: Topological tricky (streaming)
// ============================================================================
$wxr_topological = WP_PLUGIN_DIR . '/wordpress-importer/e2e/fixtures/wxr-topological-tricky.xml';
if (file_exists($wxr_topological)) {
    run_import($wxr_topological, ['stream_entities' => true]);

    // Check child-parent page relationship
    $child_pages = get_posts_by(['post_type' => 'page', 'name' => 'child-before-parent-topological']);
    assert_equals('topological_streaming: found child page', 1, count($child_pages));

    if (count($child_pages) === 1) {
        $child = $child_pages[0];
        assert_greater_than('topological_streaming: child has parent', $child->post_parent, 0);

        $parent = get_post($child->post_parent);
        assert_test('topological_streaming: parent exists', $parent !== null);
        if ($parent) {
            assert_equals('topological_streaming: parent slug', 'parent-landing-page-topological', $parent->post_name);
        }
    }

    // Check comment parent relationship (on the post that has threaded comments)
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

        assert_test('topological_streaming: found reply comment', $reply !== null);
        assert_test('topological_streaming: found parent comment', $parent_comment !== null);

        if ($reply && $parent_comment) {
            assert_equals(
                'topological_streaming: reply parent is correct',
                (int)$parent_comment->comment_ID,
                (int)$reply->comment_parent
            );
        }
    }
} else {
    TestResults::$tests[] = ['name' => 'topological_streaming', 'status' => 'skipped', 'message' => 'File not found'];
}

// Output results as JSON
echo json_encode(TestResults::to_array(), JSON_PRETTY_PRINT);
