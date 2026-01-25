<?php
/**
 * Plugin Name: Import Test Runner
 * Description: MU-plugin that provides an endpoint to run import tests
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

add_action('wp_ajax_nopriv_run_import_tests', 'run_import_tests_endpoint');
add_action('wp_ajax_run_import_tests', 'run_import_tests_endpoint');

function run_import_tests_endpoint() {
    // Disable output buffering to see errors
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    // Get parser from query string
    $parser = isset($_GET['parser']) ? sanitize_text_field($_GET['parser']) : 'simplexml';

    // Define the parser constant before loading tests
    if (!defined('PREFERRED_WXR_PARSER')) {
        define('PREFERRED_WXR_PARSER', $parser);
    }

    try {
        // Include the test file
        $test_file = WP_PLUGIN_DIR . '/wordpress-importer/tests/run-import-tests.php';

        if (!file_exists($test_file)) {
            echo json_encode(['error' => 'Test file not found at: ' . $test_file, 'plugin_dir' => WP_PLUGIN_DIR]);
            exit;
        }

        include $test_file;
    } catch (Throwable $e) {
        echo json_encode([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
    exit;
}
