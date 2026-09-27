<?php

// Tests the settings section (preferences hooks) with Roundcube's own classes.
// Usage: php plugin/tests/settings_test.php <roundcube-dir> (user 1 must exist), see tests/integration.sh
$rc_dir = realpath($argv[1]);
chdir($rc_dir);
define('INSTALL_PATH', $rc_dir . '/');
require_once 'program/include/iniset.php';

$rc = rcmail::get_instance();
$rc->set_user(new rcube_user(1));
$rc->session = new class {
    function remove($key) { unset($_SESSION[$key]); }
};
$rc->task = 'settings';
$rc->load_language('en_US');
$rc->plugins->init($rc, 'settings');
$plugin = $rc->plugins->get_plugin('identity_api');
$plugin->init();

$failed = 0;
function check($name, $cond, $extra = '')
{
    global $failed;
    echo ($cond ? 'ok   ' : 'FAIL ') . 'settings: ' . $name . ($cond ? '' : " $extra") . "\n";
    $failed += $cond ? 0 : 1;
}

function save(array $post)
{
    global $plugin, $rc;
    $_POST = $post;
    $args = $plugin->prefs_save(['section' => 'identityapi', 'prefs' => []]);
    if (empty($args['abort'])) {
        $rc->user->save_prefs($args['prefs']);
        $rc->config->set_user_prefs($rc->user->get_prefs());
    }
    return $args;
}

function render()
{
    global $plugin;
    $args = $plugin->prefs_list(['section' => 'identityapi', 'blocks' => [], 'current' => 'identityapi']);
    $html = '';
    foreach ($args['blocks'] as $block) {
        foreach ($block['options'] as $option) {
            $html .= ($option['title'] ?? '') . ($option['content'] ?? '') . "\n";
        }
    }
    return $html;
}

$sections = $plugin->prefs_sections(['list' => [], 'cols' => []]);
check('section listed', isset($sections['list']['identityapi']));

// create a token with markup in its label
$args = save(['_identity_api_new_label' => '<b>Laptop</b>']);
check('token created', empty($args['abort']) && !empty($_SESSION['identity_api_new_token']));
$token = $_SESSION['identity_api_new_token'] ?? '';
$html  = render();
check('new token shown once', strpos($html, $token) !== false && strpos(render(), $token) === false);
check('connect button with API path', strpos($html, 'id="identityapi-connect"') !== false
    && strpos($html, 'data-token="' . $token . '"') !== false && strpos($html, 'data-api="api/identity/"') !== false
    && strpos($html, 'onclick=') !== false);
check('label escaped', strpos($html, '<b>Laptop') === false);
check('rotation policy shown', strpos($html, 'renewed automatically every 30 days') !== false);

// token from before rotation support gets "issued" on display
$prefs = $rc->user->get_prefs();
$id    = explode('.', $token)[1];
unset($prefs['identity_api_tokens'][$id]['issued']);
$rc->user->save_prefs(['identity_api_tokens' => $prefs['identity_api_tokens']]);
$rc->config->set_user_prefs($rc->user->get_prefs());
render();
$fresh = new rcube_user(1);
check('legacy token migrated on display', isset($fresh->get_prefs()['identity_api_tokens'][$id]['issued']));

// prefix and domain validation, messages escaped
$args = save(['_identity_api_prefix' => '!!!']);
check('invalid prefix rejected', !empty($args['abort']));
$args = save(['_identity_api_prefix' => ' Mi-Ke ']);
check('prefix normalized', empty($args['abort']) && $rc->config->get('identity_api_prefix') === 'mike');
$args = save(['_identity_api_domains' => "example.org\nfoo&bar"]);
check('invalid domain rejected, escaped', !empty($args['abort']) && strpos($args['message'], 'foo&amp;bar') !== false,
    $args['message'] ?? '');

// pattern
$args = save(['_identity_api_template' => '{shop}+{random}']);
check('invalid pattern rejected', !empty($args['abort']));
$args = save(['_identity_api_template' => 'Web.{shop}.{random}']);
check('pattern saved (lower case)', empty($args['abort']) && $rc->config->get('identity_api_user_template') === 'web.{shop}.{random}');
check('example uses pattern', (bool) preg_match('/Example: web\.bookshop\.[a-z0-9]{8}@/', render()));
save(['_identity_api_template' => '']);
check('empty pattern = admin default', $rc->config->get('identity_api_user_template') === null);

// unique identities across users (also deleted ones), not for the own ones
$db = $rc->get_dbh();
$db->query("INSERT INTO users (username, mail_host, created, language, preferences) VALUES ('other@example.org', 'localhost', " . $db->now() . ", 'en_US', 'a:0:{}')");
$other = $db->insert_id('users');
$db->query("INSERT INTO identities (user_id, changed, del, standard, name, email) VALUES (?, " . $db->now() . ", 1, 0, 'x', 'Taken@Example.org')", $other);
$hook = $rc->plugins->exec_hook('identity_create', ['record' => ['email' => 'taken@example.org', 'name' => 'x']]);
check('identity of other user refused', !empty($hook['abort']) && strpos($hook['message'], 'another user') !== false);
$hook = $rc->plugins->exec_hook('identity_update', ['id' => 1, 'record' => ['email' => 'user@example.org', 'name' => 'x']]);
check('own identity allowed', empty($hook['abort']));
$rc->config->set('identity_api_unique_identities', false);
$hook = $rc->plugins->exec_hook('identity_create', ['record' => ['email' => 'taken@example.org', 'name' => 'x']]);
check('uniqueness can be disabled', empty($hook['abort']));
$rc->config->set('identity_api_unique_identities', true);

// revoke, also with manipulated input
try {
    save(['_identity_api_revoke' => [['nested'], $id]]);
    check('revoke', empty((new rcube_user(1))->get_prefs()['identity_api_tokens'][$id]));
}
catch (Throwable $e) {
    check('revoke', false, 'nested input: ' . get_class($e) . ': ' . $e->getMessage());
}

exit($failed ? 1 : 0);
