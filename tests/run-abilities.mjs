import { runCLI } from '@wp-playground/cli';
import { fileURLToPath } from 'node:url';

// The mounted plugin is real; WordPress data is temporary and isolated.
const wp = process.env.BI_TEST_WP || '6.9';
const supportsAbilities = wp === 'latest' || parseFloat(wp) >= 6.9;
const steps = [{ step: 'activatePlugin', pluginPath: 'boardino/boardino.php' }];
if (supportsAbilities) {
    steps.push({
        step: 'installPlugin',
        pluginData: { resource: 'url', url: 'https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip' },
        options: { activate: true },
    });
}
const server = await runCLI({
    command: 'server', php: process.env.BI_TEST_PHP || '8.0', wp, port: Number(process.env.BI_TEST_PORT || 9473), workers: 1,
    mount: [{ hostPath: fileURLToPath(new URL('..', import.meta.url)), vfsPath: '/wordpress/wp-content/plugins/boardino' }],
    blueprint: { steps },
});
try {
    const lint = await server.playground.run({ code: `<?php
        foreach (array_merge(glob('/wordpress/wp-content/plugins/boardino/*.php'), glob('/wordpress/wp-content/plugins/boardino/includes/*.php'), glob('/wordpress/wp-content/plugins/boardino/tests/*.php')) as $file) {
            token_get_all(file_get_contents($file), TOKEN_PARSE);
        }
        echo 'PASS: PHP syntax';
    ` });
    console.log(lint.text);
    const columns = await server.playground.run({ code: "<?php require '/wordpress/wp-content/plugins/boardino/tests/columns.test.php';" });
    if (!columns.text.includes('PASS:')) throw new Error('Column regression tests failed');
    console.log(columns.text);
    const code = supportsAbilities
        ? "<?php define('BOARDINO_TEST_SITE', true); require '/wordpress/wp-content/plugins/boardino/tests/abilities.integration.php';"
        : `<?php require '/wordpress/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php';
            if (function_exists('wp_register_ability')) throw new RuntimeException('Expected a WordPress version without Abilities API');
            do_action('wp_abilities_api_categories_init'); do_action('wp_abilities_api_init');
            wp_set_current_user(1);
            $board = Boardino::board();
            if (count($board['settings']['columns']) !== 3 || !is_plugin_active('boardino/boardino.php')) throw new RuntimeException('Board must still work');
            echo 'PASS: older WordPress compatibility without Abilities API';`;
    const result = await server.playground.run({ code });
    const updates = await server.playground.run({ code: "<?php define('BOARDINO_TEST_SITE', true); require '/wordpress/wp-content/plugins/boardino/tests/updates.integration.php';" });
    console.log(updates.text);
    if (!updates.text.includes('PASS:')) throw new Error('Updater tests failed');
    console.log(result.text);
    if (result.errors) console.error(result.errors);
    if (result.exitCode !== 0 || !result.text.includes('PASS:')) throw new Error('Integration tests failed');
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
} finally {
    await server[Symbol.asyncDispose]();
}
