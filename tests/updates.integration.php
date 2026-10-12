<?php
/** Run only in a disposable local WordPress instance. */
if (!defined('BOARDINO_TEST_SITE') || BOARDINO_TEST_SITE !== true) { throw new RuntimeException('Use a disposable local WordPress test site.'); }
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$checks = 0;
function bi_update_assert($condition, $message) {
    global $checks; ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
wp_set_current_user(1);
$existing = ['deactivate' => '<a href="#">Deactivate</a>'];
$links = apply_filters('plugin_action_links_boardino/boardino.php', $existing);
bi_update_assert(isset($links['deactivate'], $links['boardino-check-updates']), 'Preserve existing plugin actions and add the check link.');
bi_update_assert(str_contains(implode(' ', $links), 'Open board'), 'Keep the Open board link.');
preg_match('/href="([^"]+)"/', $links['boardino-check-updates'], $match);
$url = html_entity_decode($match[1]); parse_str(parse_url($url, PHP_URL_QUERY), $query);
bi_update_assert($query['action'] === 'boardino_check_updates' && $query['return_to'] === 'plugins', 'Link targets the protected check handler and plugin list.');
bi_update_assert(wp_verify_nonce($query['_wpnonce'], 'boardino_check_updates') !== false, 'Check link has a valid current-user nonce.');
$member = wp_insert_user(['user_login' => 'update-member', 'user_pass' => 'local-only', 'role' => 'subscriber']);
wp_set_current_user($member);
bi_update_assert(Boardino_Updates::links($existing) === $existing, 'Users without update permission do not see the check link.');
wp_set_current_user(1);
$destination = new ReflectionMethod(Boardino_Updates::class, 'check_destination');
$destination->setAccessible(true);
foreach (['plugins' => admin_url('plugins.php?boardino_update_check=1'), 'network-plugins' => network_admin_url('plugins.php?boardino_update_check=1'), 'https://evil.example' => admin_url('admin.php?page=boardino-settings#bi-updates')] as $input => $expected) {
    $_GET['return_to'] = $input;
    bi_update_assert($destination->invoke(null) === $expected, 'Only known internal return destinations are accepted.');
}
unset($_GET['return_to']);
bi_update_assert($destination->invoke(null) === admin_url('admin.php?page=boardino-settings#bi-updates'), 'Existing Settings form still returns to Settings.');
set_current_screen('plugins-network');
$network_links = apply_filters('network_admin_plugin_action_links_boardino/boardino.php', $existing);
bi_update_assert(str_contains($network_links['boardino-check-updates'], 'return_to=network-plugins'), 'Network plugin row returns to the network plugin list.');
set_current_screen('plugins'); $_GET['boardino_update_check'] = '1';
$release = ['version' => '9.9.9'];
set_site_transient('boardino_github_release', ['release' => $release], 300);
ob_start(); Boardino_Updates::notice(); $notice = ob_get_clean();
bi_update_assert(str_contains($notice, 'Version 9.9.9 is available') && str_contains($notice, 'Update now'), 'Plugin-list notice explains how to install an available update.');
set_site_transient('boardino_github_release', ['release' => ['version' => BOARDINO_VERSION]], 300);
ob_start(); Boardino_Updates::notice(); $notice = ob_get_clean();
bi_update_assert(str_contains($notice, 'latest published version'), 'Up-to-date feedback.');
set_site_transient('boardino_github_release', ['release' => null, 'error' => '<script>unavailable</script>'], 300);
ob_start(); Boardino_Updates::notice(); $notice = ob_get_clean();
bi_update_assert(str_contains($notice, 'notice-warning') && !str_contains($notice, '<script>'), 'Failure feedback is escaped.');
wp_set_current_user($member);
ob_start(); Boardino_Updates::notice(); $notice = ob_get_clean();
bi_update_assert($notice === '', 'No update notice for unauthorized users.');
wp_set_current_user(1); set_current_screen('dashboard');
ob_start(); Boardino_Updates::notice(); $notice = ob_get_clean();
bi_update_assert($notice === '', 'No update notices on unrelated screens.');
// Validate complete and incomplete source packages with real WordPress filesystem APIs.
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
set_site_transient('update_plugins', (object)['response' => ['boardino/boardino.php' => (object)['new_version' => BOARDINO_VERSION]]], 300);
$extra = ['plugin' => 'boardino/boardino.php', 'action' => 'update', 'type' => 'plugin'];
$source = WP_PLUGIN_DIR . '/boardino/';
bi_update_assert(Boardino_Updates::source($source, '', null, $extra) === $source, 'A complete plugin package passes source validation.');
$fake = new class($GLOBALS['wp_filesystem']) {
    private $original;
    function __construct($original) { $this->original = $original; }
    function get_contents($path) { return $this->original->get_contents($path); }
    function is_file($path) { return str_ends_with($path, 'class-boardino-abilities.php') ? false : $this->original->is_file($path); }
};
$original = $GLOBALS['wp_filesystem']; $GLOBALS['wp_filesystem'] = $fake;
$error = Boardino_Updates::source($source, '', null, $extra);
$GLOBALS['wp_filesystem'] = $original;
bi_update_assert(is_wp_error($error) && $error->get_error_code() === 'boardino_package_incomplete', 'Do not install a package missing the required abilities file.');
echo 'PASS: ' . $checks . " local updater checks\n";
