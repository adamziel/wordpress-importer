#!/usr/bin/env node
/**
 * Fast import tests using WP Playground CLI with SQLite
 *
 * This script runs PHP-based import tests in a WP Playground instance,
 * providing much faster feedback than browser-based e2e tests.
 *
 * Tests all parser/mode combinations:
 * - Parsers: simplexml, xml, regex, xmlprocessor
 * - Modes: regular (all parsers), streaming (xmlprocessor only)
 *
 * Usage:
 *   node tests/run-tests.mjs           # Run all parsers
 *   node tests/run-tests.mjs simplexml # Run single parser
 */

import { runCLI } from '@wp-playground/cli';
import * as path from 'path';
import { fileURLToPath } from 'url';
import * as http from 'http';
import * as net from 'net';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const pluginDir = path.resolve(__dirname, '..');

const ALL_PARSERS = ['simplexml', 'xml', 'regex', 'xmlprocessor'];

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

async function runTestsForParser(parser) {
    const port = await getAvailablePort();
    const siteUrl = `http://127.0.0.1:${port}`;

    const pluginSrc = path.join(pluginDir, 'src');
    const fixturesDir = path.join(pluginDir, 'e2e', 'fixtures');
    const testsDir = __dirname;

    const blueprint = {
        steps: [
            {
                step: 'activatePlugin',
                pluginPath: '/wordpress/wp-content/plugins/wordpress-importer',
            },
        ],
    };

    const { server } = await runCLI({
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

    try {
        await waitUntilAlive(`${siteUrl}/wp-admin/`);

        // Run tests for this parser
        const testUrl = `${siteUrl}/wp-admin/admin-ajax.php?action=run_import_tests&parser=${parser}`;
        const output = await fetchUrl(testUrl);

        // Parse results
        const jsonMatch = output.match(/\{[\s\S]*\}/);
        if (!jsonMatch) {
            throw new Error(`No JSON found in output for ${parser}: ${output}`);
        }

        return JSON.parse(jsonMatch[0]);
    } finally {
        await server[Symbol.asyncDispose]();
    }
}

async function runTests() {
    // Determine which parsers to test
    const requestedParser = process.argv[2];
    const parsers = requestedParser ? [requestedParser] : ALL_PARSERS;

    // Validate parser names
    for (const p of parsers) {
        if (!ALL_PARSERS.includes(p)) {
            console.error(`Invalid parser: ${p}`);
            console.error(`Valid parsers: ${ALL_PARSERS.join(', ')}`);
            process.exit(1);
        }
    }

    console.log(`Testing parsers: ${parsers.join(', ')}\n`);

    // Aggregate results
    let totalPassed = 0;
    let totalFailed = 0;
    let totalSkipped = 0;
    const allTests = [];

    for (const parser of parsers) {
        process.stdout.write(`Running ${parser} parser tests... `);
        const startTime = Date.now();

        try {
            const results = await runTestsForParser(parser);
            const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);

            totalPassed += results.passed || 0;
            totalFailed += results.failed || 0;
            totalSkipped += results.skipped || 0;

            if (results.tests) {
                allTests.push(...results.tests);
            }

            const status = results.failed > 0 ? '✗' : '✓';
            console.log(`${status} (${results.passed} passed, ${results.failed} failed) [${elapsed}s]`);

            // Show failures immediately
            if (results.tests) {
                for (const test of results.tests) {
                    if (test.status === 'failed') {
                        console.log(`    ✗ ${test.name}`);
                        if (test.message) {
                            console.log(`      ${test.message}`);
                        }
                    }
                }
            }
        } catch (error) {
            console.log(`✗ Error: ${error.message}`);
            totalFailed++;
        }
    }

    // Final summary
    console.log('\n================================');
    console.log('Test Results Summary:');
    console.log('================================');
    console.log(`Passed:  ${totalPassed}`);
    console.log(`Failed:  ${totalFailed}`);
    console.log(`Skipped: ${totalSkipped}`);
    console.log('');

    // Show all results
    for (const test of allTests) {
        if (test.status === 'passed') {
            console.log(`  ✓ ${test.name}`);
        } else if (test.status === 'skipped') {
            console.log(`  ○ ${test.name} (skipped)`);
        } else {
            console.log(`  ✗ ${test.name}`);
            if (test.message) {
                console.log(`    ${test.message}`);
            }
        }
    }

    console.log('');
    process.exit(totalFailed > 0 ? 1 : 0);
}

runTests().catch((error) => {
    console.error('Test runner failed:', error);
    process.exit(1);
});
