# Tests

PHP scripts run inside the local xswitch WordPress where the plugin is active:

    cd /Users/ibra/Documents/Projects/xswitch
    WP_CLI_PHP_ARGS='-d error_reporting=24575' wp eval-file /Users/ibra/Documents/Projects/woocommerce-shop-agent/tests/test-capabilities.php 2>&1 | grep -v Deprecated

Each script prints `PASS <file>` or exits 1 at the first failed assertion. They create
their own posts and delete them at the end.

The widget harness serves `assets/` and a stub chat endpoint, then drives Chrome:

    cd /Users/ibra/Documents/Projects/woocommerce-shop-agent
    node tests/harness/run.mjs "$(ls -d ~/.npm/_npx/*/node_modules/playwright-core | head -1)" assets
