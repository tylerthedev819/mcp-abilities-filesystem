<?php
/** Exercise registered callbacks against disposable local files. */
$fixture = sys_get_temp_dir() . '/mcp-filesystem-' . bin2hex(random_bytes(6));
mkdir($fixture . '/wp/wp-content', 0777, true);
define('ABSPATH', $fixture . '/wp/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('FS_CHMOD_FILE', 0644);
function add_action(...$args) {}
function apply_filters($hook, $value) { return $value; }
function untrailingslashit($s) { return rtrim($s, "/\\"); }
function wp_is_writable($p) { return is_writable($p); }
function wp_register_ability($name, $args) { $GLOBALS['abilities'][$name] = $args; }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function sanitize_file_name($name) { return $name; }
function get_allowed_mime_types() { return ['txt' => 'text/plain']; }
function wp_check_filetype($name, $mimes) { return ['type' => $mimes[pathinfo($name, PATHINFO_EXTENSION)] ?? false]; }
function current_user_can($cap) { return $cap === 'manage_options'; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_get_current_user() { return (object) ['ID' => 1, 'user_email' => 'admin@example.com']; }
function wp_rand($min, $max) { return $max; }
function wp_delete_file($path) { unlink($path); }
function WP_Filesystem() { return true; }
$wp_filesystem = new class {
    public $failBackupFor = null;
    function delete($path, $recursive = false, $type = null) { return is_dir($path) ? rmdir($path) : unlink($path); }
    function exists($path) { return file_exists($path); }
    function get_contents($path) { return file_get_contents($path); }
    function put_contents($path, $content, $mode) { return file_put_contents($path, $content) !== false; }
    function copy($from, $to, $overwrite = false, $mode = null) {
        if ($this->failBackupFor === $from && str_contains($to, '/mcp-filesystem/backups/')) return false;
        return ($overwrite || !file_exists($to)) && copy($from, $to);
    }
    function move($from, $to, $overwrite = false) { return ($overwrite || !file_exists($to)) && rename($from, $to); }
};
require dirname(__DIR__) . '/mcp-abilities-filesystem.php';
mcp_register_filesystem_abilities();
$failed = 0;
function check($condition, $label) { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$condition) $failed++; }
function run_ability($name, $input) { return $GLOBALS['abilities']['filesystem/' . $name]['execute_callback']($input); }
try {
    $result = run_ability('write-file', ['path' => 'robots.txt', 'content' => 'User-agent: *']);
    check($result['success'], 'write a permitted file directly in WordPress root');
    file_put_contents($fixture . '/outside.txt', 'unchanged');
    symlink($fixture . '/outside.txt', WP_CONTENT_DIR . '/linked.txt');
    $result = run_ability('write-file', ['path' => 'wp-content/linked.txt', 'content' => 'changed', 'backup' => false]);
    check(!$result['success'] && file_get_contents($fixture . '/outside.txt') === 'unchanged', 'write rejects destination symlink outside root');
    file_put_contents(ABSPATH . 'wp-config.php', '<?php secret();');
    $result = run_ability('copy-file', ['source' => 'wp-config.php', 'dest' => 'wp-content/public.txt']);
    check(!$result['success'] && !file_exists(WP_CONTENT_DIR . '/public.txt'), 'copy preserves sensitive read restriction');
    file_put_contents(WP_CONTENT_DIR . '/source.txt', 'replacement');
    file_put_contents(WP_CONTENT_DIR . '/target.txt', 'original');
    $wp_filesystem->failBackupFor = WP_CONTENT_DIR . '/target.txt';
    foreach (['copy-file', 'move-file'] as $name) {
        $result = run_ability($name, ['source' => 'wp-content/source.txt', 'dest' => 'wp-content/target.txt', 'overwrite' => true]);
        check(!$result['success'] && file_get_contents(WP_CONTENT_DIR . '/target.txt') === 'original' && file_exists(WP_CONTENT_DIR . '/source.txt'), $name . ' aborts when destination backup fails');
    }
    $wp_filesystem->failBackupFor = null;
    file_put_contents(WP_CONTENT_DIR . '/fragment.txt', '<');
    $result = run_ability('append-file', ['path' => 'wp-content/fragment.txt', 'content' => '?php echo 1;', 'backup' => false]);
    check(!$result['success'] && file_get_contents(WP_CONTENT_DIR . '/fragment.txt') === '<', 'append checks combined content for PHP');
    file_put_contents(WP_CONTENT_DIR . '/large.txt', str_repeat('a', 10 * 1024 * 1024));
    $result = run_ability('append-file', ['path' => 'wp-content/large.txt', 'content' => 'b', 'backup' => false]);
    check(!$result['success'] && filesize(WP_CONTENT_DIR . '/large.txt') === 10 * 1024 * 1024, 'append enforces total file size');
    foreach (['copy-file', 'move-file'] as $name) {
        $result = run_ability($name, ['source' => 'wp-content/source.txt', 'dest' => $name . '.txt']);
        check($result['success'] && file_get_contents(ABSPATH . $name . '.txt') === 'replacement', $name . ' accepts permitted root destination');
    }
    mkdir(ABSPATH . 'wp-admin');
    $result = run_ability('copy-file', ['source' => 'copy-file.txt', 'dest' => 'wp-admin/injected.txt']);
    check(!$result['success'] && !file_exists(ABSPATH . 'wp-admin/injected.txt'), 'copy rejects WordPress core destination');
    if (file_exists(ABSPATH . 'wp-admin/injected.txt')) unlink(ABSPATH . 'wp-admin/injected.txt');
    $result = run_ability('delete-directory', ['path' => 'wp-admin']);
    check(!$result['success'] && is_dir(ABSPATH . 'wp-admin'), 'delete protects exact core directory');
    $result = run_ability('create-directory', ['path' => 'new-root-directory']);
    check($result['success'] && is_dir(ABSPATH . 'new-root-directory'), 'create directory accepts WordPress root parent');
    mkdir($fixture . '/outside-directory');
    symlink($fixture . '/outside-directory', WP_CONTENT_DIR . '/directory-link');
    $result = run_ability('create-directory', ['path' => 'wp-content/directory-link/nested/leaf']);
    check(!$result['success'] && !is_dir($fixture . '/outside-directory/nested'), 'recursive mkdir rejects symlink ancestor outside root');
    $result = run_ability('list-directory', []);
    check($result['success'], 'default listing accepts WordPress root');
    file_put_contents($fixture . '/outside-directory/outside-marker.txt', 'private');
    $result = run_ability('list-directory', ['path' => 'wp-content', 'recursive' => true]);
    check($result['success'] && !in_array('outside-marker.txt', array_column($result['items'], 'name'), true), 'recursive listing stays inside canonical root');
    // Fork: real .php files may contain PHP; other types are still scanned.
    $result = run_ability('write-file', ['path' => 'wp-content/fork-plugin.php', 'content' => "<?php\n\$x = array_map('trim', [' a ']);\n", 'backup' => false]);
    check($result['success'] && file_exists(WP_CONTENT_DIR . '/fork-plugin.php'), 'fork: write a .php file containing PHP');
    $result = run_ability('write-file', ['path' => 'wp-content/fork-hidden.txt', 'content' => '<?php echo 1;', 'backup' => false]);
    check(!$result['success'] && !file_exists(WP_CONTENT_DIR . '/fork-hidden.txt'), 'fork: PHP inside a non-PHP file is still blocked');
    define('DISALLOW_FILE_MODS', true);
    foreach (['delete-file' => 'copy-file.txt', 'delete-directory' => 'new-root-directory', 'create-directory' => 'disabled-directory'] as $name => $path) {
        $result = run_ability($name, ['path' => $path]);
        check(!$result['success'] && (str_contains($name, 'delete') ? file_exists(ABSPATH . $path) : !file_exists(ABSPATH . $path)), $name . ' respects DISALLOW_FILE_MODS');
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($fixture);
}
exit($failed ? 1 : 0);
