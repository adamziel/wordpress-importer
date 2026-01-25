#!/bin/bash
# Fast import tests using WP Playground CLI with SQLite
#
# Usage: ./tests/run-tests.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

# Ensure node is available
export PATH="$HOME/.nvm/versions/node/v22.12.0/bin:$PATH"

# Create a temporary file for test output
OUTPUT_FILE=$(mktemp)
trap "rm -f $OUTPUT_FILE" EXIT

echo "Running import tests..."
echo ""

# Run the blueprint with the plugin mounted
npx wp-playground-cli run-blueprint \
    --blueprint="$SCRIPT_DIR/test-blueprint.json" \
    --mount="$PLUGIN_DIR/src:/wordpress/wp-content/plugins/wordpress-importer" \
    --mount="$SCRIPT_DIR:/wordpress/wp-content/plugins/wordpress-importer/tests" \
    --mount="$PLUGIN_DIR/e2e/fixtures:/wordpress/wp-content/plugins/wordpress-importer/e2e/fixtures" \
    --verbosity=quiet \
    2>&1 | tee "$OUTPUT_FILE"

# Extract JSON results from output (last line that looks like JSON)
RESULTS=$(grep -E '^\{' "$OUTPUT_FILE" | tail -1 || echo '{"error":"No JSON output found"}')

echo ""
echo "================================"
echo "Test Results:"
echo "================================"
echo "$RESULTS" | jq -r '
    if .error then
        "ERROR: \(.error)"
    else
        "Passed: \(.passed)\nFailed: \(.failed)\n",
        (.tests[] |
            if .status == "passed" then
                "  ✓ \(.name)"
            else
                "  ✗ \(.name)\n    \(.message // "No message")"
            end
        )
    end
' 2>/dev/null || echo "$RESULTS"

# Exit with appropriate code
FAILED=$(echo "$RESULTS" | jq -r '.failed // 1' 2>/dev/null || echo "1")
if [ "$FAILED" != "0" ]; then
    exit 1
fi
