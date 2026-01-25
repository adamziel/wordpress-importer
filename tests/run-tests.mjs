#!/usr/bin/env node
/**
 * Fast import tests using WP Playground CLI with SQLite
 *
 * This script runs PHP-based import tests in a WP Playground instance,
 * providing much faster feedback than browser-based e2e tests.
 *
 * Usage: node tests/run-tests.mjs
 */

import { runCLI } from '@wp-playground/cli';
import * as path from 'path';
import { fileURLToPath } from 'url';
import * as fs from 'fs';
import * as http from 'http';
import * as net from 'net';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const pluginDir = path.resolve(__dirname, '..');

async function getAvailablePort() {
    return new Promise((resolve, reject) => {
        const srv = net.createServer();
        srv.unref();
        srv.on('error', reject);
        srv.listen(0, '127.0.0.1', () => {
            const { port } = srv.address();
            srv.close(() => resolve(port));
        });
    });
}

async function waitUntilAlive(url, timeoutMs = 30000) {
    const end = Date.now() + timeoutMs;
    while (Date.now() < end) {
        try {
            await new Promise((resolve, reject) => {
                const req = http.request(url, { method: 'HEAD' }, (res) => {
                    res.destroy();
                    if (res.statusCode && res.statusCode < 500) resolve(true);
                    else reject(new Error('Bad status'));
                });
                req.on('error', reject);
                req.end();
            });
            return true;
        } catch (_) {}
        await new Promise((r) => setTimeout(r, 300));
    }
    throw new Error('Server did not become ready in time');
}

async function fetchUrl(url) {
    return new Promise((resolve, reject) => {
        http.get(url, (res) => {
            let data = '';
            res.on('data', (chunk) => (data += chunk));
            res.on('end', () => resolve(data));
        }).on('error', reject);
    });
}

async function runTests() {
    console.log('Starting WP Playground...');

    const port = await getAvailablePort();
    const siteUrl = `http://127.0.0.1:${port}`;

    const pluginSrc = path.join(pluginDir, 'src');
    const fixturesDir = path.join(pluginDir, 'e2e', 'fixtures');
    const testsDir = __dirname;

    // Blueprint that activates the already-mounted plugin
    const blueprint = {
        steps: [
            {
                step: 'activatePlugin',
                pluginPath: '/wordpress/wp-content/plugins/wordpress-importer',
            },
        ],
    };

    const { server, playground } = await runCLI({
        command: 'server',
        blueprint,
        blueprintMayReadAdjacentFiles: true,
        mount: [
            {
                hostPath: pluginSrc,
                vfsPath: '/wordpress/wp-content/plugins/wordpress-importer',
            },
            {
                hostPath: fixturesDir,
                vfsPath: '/wordpress/wp-content/plugins/wordpress-importer/e2e/fixtures',
            },
            {
                hostPath: testsDir,
                vfsPath: '/wordpress/wp-content/plugins/wordpress-importer/tests',
            },
            {
                hostPath: path.join(testsDir, 'mu-plugin-test-runner.php'),
                vfsPath: '/wordpress/wp-content/mu-plugins/test-runner.php',
            },
        ],
        port,
        siteUrl,
        quiet: true,
    });

    console.log(`WP Playground running at ${siteUrl}`);

    try {
        await waitUntilAlive(`${siteUrl}/wp-admin/`);
        console.log('Server is ready. Running tests...\n');

        // Run tests via WordPress AJAX endpoint
        const testUrl = `${siteUrl}/wp-admin/admin-ajax.php?action=run_import_tests`;
        const output = await fetchUrl(testUrl);

        // Parse and display results
        let results;
        try {
            // Find JSON in output
            const jsonMatch = output.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                results = JSON.parse(jsonMatch[0]);
            } else {
                console.error('No JSON found in output:');
                console.error(output);
                process.exit(1);
            }
        } catch (e) {
            console.error('Failed to parse test output:');
            console.error('Raw output:', output);
            console.error('Error:', e.message);
            process.exit(1);
        }

        // Debug: log the parsed results
        if (!results.tests || !Array.isArray(results.tests)) {
            console.error('Invalid results structure:');
            console.error(JSON.stringify(results, null, 2));
            process.exit(1);
        }

        // Display results
        console.log('================================');
        console.log('Test Results:');
        console.log('================================\n');
        console.log(`Passed: ${results.passed}`);
        console.log(`Failed: ${results.failed}`);

        if (results.debug) {
            console.log('\nDebug info:', JSON.stringify(results.debug, null, 2));
        }
        console.log('');

        for (const test of results.tests) {
            if (test.status === 'passed') {
                console.log(`  ✓ ${test.name}`);
            } else {
                console.log(`  ✗ ${test.name}`);
                if (test.message) {
                    console.log(`    ${test.message}`);
                }
            }
        }

        console.log('');
        process.exit(results.failed > 0 ? 1 : 0);
    } finally {
        await server[Symbol.asyncDispose]();
    }
}

runTests().catch((error) => {
    console.error('Test runner failed:', error);
    process.exit(1);
});
