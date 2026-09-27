<?php

/**
 * Identity API
 *
 * Token-authenticated REST API to create per-shop identities
 * (e.g. m-bookshop-2026-k3x9q2ab@example.org) on the fly, used by the
 * "Shop-Adressen" browser extension.
 *
 * Endpoint: <roundcube-url>/api/identity/v1/... (rewrite rule, see README and docs/openapi.yaml)
 * Auth:     header "Authorization: Bearer <token>" (or "X-Identity-Api-Token: <token>")
 *
 * Tokens are managed by the user in Settings > Preferences > Shop address API.
 *
 * @license GNU GPLv3+
 */
require_once __DIR__ . '/lib/identity_api_generator.php';

class identity_api extends rcube_plugin
{
    public const TASK       = 'identity_api';
    public const SECTION    = 'identityapi';
    public const PREF_KEY   = 'identity_api_tokens';
    public const PREF_DOMAINS = 'identity_api_user_domains';
    public const PREF_PREFIX  = 'identity_api_prefix';
    public const PREF_TEMPLATE = 'identity_api_user_template';
    public const PENDING_MAX  = 3;
    public const PREF_RECENT  = 'identity_api_recent';
    public const SESS_TOKEN = 'identity_api_new_token';

    public const STATUS_TEXT = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 415 => 'Unsupported Media Type', 429 => 'Too Many Requests',
        500 => 'Internal Server Error'];

    /** REST API v1 routes: path regex => [method => handler] */
    private const ROUTES = [
        '#^/v1/me$#'                => ['GET' => 'rest_me'],
        '#^/v1/identities$#'        => ['GET' => 'rest_list', 'POST' => 'rest_create'],
        '#^/v1/identities/(\d+)$#'  => ['GET' => 'rest_get', 'DELETE' => 'rest_delete'],
        '#^/v1/token$#'             => ['GET' => 'rest_token'],
        '#^/v1/token/rotate$#'      => ['POST' => 'rest_rotate'],
    ];

    private $rc;

    /** @var array|null Status of the token used for the current API request */
    private $token_status;

    /** @var bool Request path ends with "/" (for relative Location headers) */
    private $trailing_slash = false;

    public function init()
    {
        $this->rc = rcmail::get_instance();
        $this->load_config();

        $this->add_hook('startup', [$this, 'startup']);

        if ($this->rc->task == 'settings') {
            $this->add_texts('localization/', ['copy', 'copied', 'noexisting', 'apiunreachable']);
            $this->add_hook('preferences_sections_list', [$this, 'prefs_sections']);
            $this->add_hook('preferences_list', [$this, 'prefs_list']);
            $this->add_hook('preferences_save', [$this, 'prefs_save']);
            // identities created or changed in the Roundcube settings
            $this->add_hook('identity_create', [$this, 'identity_unique']);
            $this->add_hook('identity_update', [$this, 'identity_unique']);
            // address generator in the settings (any browser, e.g. Firefox on iOS)
            $this->register_action('plugin.identity_api-create', [$this, 'ui_create']);
            $this->register_action('plugin.identity_api-list', [$this, 'ui_list']);
            $this->include_script('identity_api.js');
        }
    }

    /**
     * Mail is delivered per identity, so an address must not be used by two
     * users: refuse addresses another user has (or had, deleted identities
     * included, e.g. an address shut down because of spam). Configurable with
     * identity_api_unique_identities (default true).
     */
    public function identity_unique($args)
    {
        $email = trim((string) ($args['record']['email'] ?? ''));

        if (!$this->rc->config->get('identity_api_unique_identities', true) || $email === '' || !empty($args['abort'])
            || !empty($args['login'])
        ) {
            return $args;
        }

        if ($this->email_exists($email, $this->rc->user->ID)) {
            $this->add_texts('localization/');
            $args['abort']   = true;
            $args['result']  = false;
            $args['message'] = $this->gettext('addressinuse');
        }

        return $args;
    }

    /**
     * Intercept API requests before Roundcube's session authentication kicks in.
     * Unauthenticated requests have their task rewritten to "login", therefore
     * the raw request parameter is checked here.
     */
    public function startup($args)
    {
        if (rcube_utils::get_input_string('_task', rcube_utils::INPUT_GET) !== self::TASK) {
            return $args;
        }

        try {
            $this->handle_rest((string) rcube_utils::get_input_string('_path', rcube_utils::INPUT_GET));
        }
        catch (identity_api_exception $e) {
            $this->send_problem($e->getCode() ?: 400, $e->error_code, $e->getMessage(), $e->headers);
        }
        catch (Throwable $e) {
            rcube::raise_error($e, true, false);
            $this->send_problem(500, 'internal_error', 'internal error');
        }

        exit;
    }

    /**
     * The user's identities created by this plugin (matching the user's or the
     * admin's pattern), optionally for one (sanitized) shop, newest first.
     * Each row gets "parsed" => [prefix, shop, year].
     */
    private function find_identities(rcube_user $user, $shop = null)
    {
        $generators = $this->list_generators($user);
        $result     = [];

        foreach ($user->list_identities() as $identity) {
            foreach ($generators as $generator) {
                $parsed = $generator->parse($identity['email']);
                if ($parsed && ($shop === null || $parsed['shop'] === $shop)) {
                    $identity['parsed'] = $parsed;
                    $result[] = $identity;
                    break;
                }
            }
        }

        usort($result, function ($a, $b) { return $b['identity_id'] <=> $a['identity_id']; });

        return $result;
    }

    /** Create a new identity from the request parameters (shop, domain, name). */
    private function create_identity(rcube_user $user)
    {
        // level 1: core forces the login address for new identities, level 2+: single identity
        $level = (int) $this->rc->config->get('identities_level', 0);
        if ($level >= 1 && !$this->rc->config->get('identity_api_ignore_identities_level')) {
            throw new identity_api_exception('creating identities is disabled (identities_level)', 403, 'identities_disabled');
        }

        $generator = $this->generator($user);
        $shop      = trim((string) $this->request_param('shop'));

        // a website address instead of a shop name, e.g. from the iOS share sheet
        if (preg_match('~^https?://~i', $shop)) {
            $shop = $generator->shop_from_url($shop);
        }
        else {
            $shop = $generator->sanitize_shop($shop);
        }
        if ($shop === '' && ($url = $this->request_param('url'))) {
            $shop = $generator->shop_from_url($url);
        }

        if ($shop === '') {
            throw new identity_api_exception('shop missing', 400);
        }

        $domains = $this->allowed_domains($user);
        $domain  = strtolower(trim((string) $this->request_param('domain')));

        if ($domain === '') {
            $domain = $domains[0];
        }
        else if (!in_array($domain, $domains, true)) {
            throw new identity_api_exception('domain not allowed', 403);
        }

        $max = (int) $this->rc->config->get('identity_api_max_identities', 1000);
        if ($max > 0 && count($user->list_identities()) >= $max) {
            throw new identity_api_exception('identity limit reached', 403);
        }

        $recent = $this->recent_creations($user);
        $limit  = (int) $this->rc->config->get('identity_api_rate_limit', 30);
        if ($limit > 0 && count($recent) >= $limit) {
            throw new identity_api_exception('rate limit exceeded', 429, null, ['Retry-After' => max(1, (int) min($recent) + 3600 - time())]);
        }

        $email = null;
        for ($i = 0; $i < 10 && !$email; $i++) {
            $candidate = $generator->build($shop, $domain, null, $this->user_prefix($user), 64);
            if (!$this->email_exists($candidate)) {
                $email = $candidate;
            }
        }

        if (!$email) {
            throw new identity_api_exception('could not generate a unique address', 500);
        }

        // RFC 5321 local part limit, Roundcube's identities.email is varchar(128)
        if (strlen(strstr($email, '@', true)) > 64 || strlen($email) > 128) {
            throw new identity_api_exception('address too long, use a shorter shop name or domain', 400, 'address_too_long');
        }

        $name = trim((string) $this->request_param('name'));
        $name = $name !== '' ? mb_substr($name, 0, 128) : $this->default_name($user);

        $record = [
            'name'     => $name,
            'email'    => rcube_utils::idn_to_ascii($email),
            'standard' => 0,
        ];

        $plugin = $this->rc->plugins->exec_hook('identity_create', ['record' => $record, 'login' => false]);
        $record = $plugin['record'];

        if (!empty($plugin['abort'])) {
            $insert_id = $plugin['result'] ?? false;
        }
        else {
            $insert_id = $user->insert_identity($record);
        }

        if (!$insert_id) {
            throw new identity_api_exception($plugin['message'] ?? 'saving identity failed', 500, 'saving_failed');
        }

        $this->rc->plugins->exec_hook('identity_create_after', ['id' => $insert_id, 'record' => $record]);

        $recent[] = time();
        $fresh = new rcube_user($user->ID);
        $fresh->save_prefs([self::PREF_RECENT => array_values($recent)], true);

        rcube::write_log('identity_api', sprintf('user %s created identity %s (shop %s, from %s)',
            $user->get_username(), $record['email'], $shop, rcube_utils::remote_addr()));

        $record['identity_id'] = $insert_id;
        $record['changed']     = date('Y-m-d H:i:s');
        $record['shop']        = $shop;

        return $record;
    }

    // ------------------------------------------------------------------
    // REST API v1 (see docs/openapi.yaml)

    private function handle_rest($path)
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // the rewrite rule maps <api-url>/v1/... to ?_task=identity_api&_path=/v1/...
        $this->trailing_slash = substr($path, -1) === '/';
        $path = '/' . trim($path, '/');

        foreach (self::ROUTES as $regex => $handlers) {
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            $allow = implode(', ', array_keys($handlers));
            if ($method == 'OPTIONS') {
                $this->send(204, null, ['Allow' => $allow . ', OPTIONS']);
            }
            if (empty($handlers[$method])) {
                throw new identity_api_exception("method $method not allowed", 405, 'method_not_allowed', ['Allow' => $allow]);
            }

            $user = $this->authenticate();
            $this->{$handlers[$method]}($user, $m[1] ?? null);
        }

        throw new identity_api_exception('no such resource', 404, 'not_found');
    }

    private function rest_me(rcube_user $user)
    {
        $domains = $this->allowed_domains($user);

        $this->send(200, [
            'user'           => $user->get_username(),
            'name'           => $this->default_name($user),
            'prefix'         => $this->user_prefix($user),
            'pattern'        => $this->user_template($user),
            'domains'        => $domains,
            'default_domain' => $domains[0],
            'limits'         => [
                'max_identities'     => (int) $this->rc->config->get('identity_api_max_identities', 1000) ?: null,
                'identities_per_hour' => (int) $this->rc->config->get('identity_api_rate_limit', 30) ?: null,
            ],
        ]);
    }

    private function rest_list(rcube_user $user)
    {
        $shop = $this->request_param('shop');
        if ($shop !== null && $shop !== '') {
            $shop = $this->generator()->sanitize_shop($shop);
            if ($shop === '') {
                throw new identity_api_exception('invalid shop name', 400, 'invalid_shop');
            }
        }
        else {
            $shop = null;
        }

        $items = array_map([$this, 'rest_identity'], $this->find_identities($user, $shop));

        $this->send(200, ['items' => $items]);
    }

    private function rest_create(rcube_user $user)
    {
        $identity = $this->create_identity($user);
        $identity = $this->rest_identity($identity + ['parsed' => $this->generator($user)->parse($identity['email'])]);

        // relative to the request URL (<api-url>/v1/identities), which only the client knows for sure
        $location = ($this->trailing_slash ? '' : 'identities/') . $identity['id'];
        $this->send(201, $identity, ['Location' => $location]);
    }

    private function rest_get(rcube_user $user, $id)
    {
        $this->send(200, $this->rest_identity($this->rest_find($user, $id)));
    }

    /** Delete (Roundcube marks it deleted) an identity created by this plugin. */
    private function rest_delete(rcube_user $user, $id)
    {
        $identity = $this->rest_find($user, $id);

        $plugin = $this->rc->plugins->exec_hook('identity_delete', ['id' => $identity['identity_id']]);
        $deleted = empty($plugin['abort']) ? $user->delete_identity($identity['identity_id']) : ($plugin['result'] ?? false);
        if (!$deleted) {
            throw new identity_api_exception('identity could not be deleted', 409, 'delete_failed');
        }

        rcube::write_log('identity_api', sprintf('user %s deleted identity %s (from %s)',
            $user->get_username(), $identity['email'], rcube_utils::remote_addr()));

        $this->send(204, null);
    }

    private function rest_token(rcube_user $user)
    {
        $prefs = $user->get_prefs();
        $token = (array) ($prefs[self::PREF_KEY][$this->token_status['id']] ?? []);
        $time  = function ($ts) { return $ts ? gmdate('Y-m-d\TH:i:s\Z', (int) $ts) : null; };

        $this->send(200, [
            'id'          => $this->token_status['id'],
            'label'       => $token['label'] ?? '',
            'created'     => $time($token['created'] ?? null),
            'issued'      => $time($token['issued'] ?? null),
            'last_used'   => $time($token['last_used'] ?? null),
            'expires'     => $time($this->token_status['expires']),
            'rotate'      => $this->token_status['rotate'],
            'static'      => $this->token_status['static'],
        ]);
    }

    /**
     * Issue a new secret for the token used in this request. The client has to
     * use it once to make it the current one (see verify_token()).
     */
    private function rest_rotate(rcube_user $user)
    {
        if (!empty($this->token_status['static'])) {
            throw new identity_api_exception('tokens without rotation cannot be rotated', 409, 'static_token');
        }

        $id     = $this->token_status['id'];
        $secret = self::random_secret();
        $found  = false;

        $this->update_tokens($user, function ($tokens) use ($id, $secret, &$found) {
            if (!empty($tokens[$id])) {
                $pending   = (array) ($tokens[$id]['pending'] ?? []);
                $pending[] = ['hash' => hash('sha256', $secret), 'issued' => time()];
                $tokens[$id]['pending'] = array_slice($pending, -self::PENDING_MAX);
                $found = true;
            }
            return $tokens;
        });

        if (!$found) {
            throw new identity_api_exception('unauthorized', 401);
        }

        $lifetime = $this->token_lifetime();

        $this->send(200, [
            'token'   => $user->ID . '.' . $id . '.' . $secret,
            'expires' => $lifetime ? gmdate('Y-m-d\TH:i:s\Z', time() + $lifetime * 86400) : null,
        ]);
    }

    private function rest_find(rcube_user $user, $id)
    {
        foreach ($this->find_identities($user) as $identity) {
            if ((int) $identity['identity_id'] === (int) $id) {
                return $identity;
            }
        }

        throw new identity_api_exception('no such identity', 404, 'not_found');
    }

    private function rest_identity(array $identity)
    {
        $parsed  = $identity['parsed'] ?? [];
        $changed = !empty($identity['changed']) ? strtotime($identity['changed']) : false;

        return [
            'id'      => (int) $identity['identity_id'],
            'email'   => rcube_utils::idn_to_utf8($identity['email']),
            'name'    => $identity['name'],
            'shop'    => $parsed['shop'] ?? null,
            'prefix'  => $parsed['prefix'] ?? null,
            'year'    => $parsed['year'] ?? null,
            'changed' => $changed ? gmdate('Y-m-d\TH:i:s\Z', $changed) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Authentication

    private function authenticate()
    {
        $token = rcube_utils::request_header('X-Identity-Api-Token');

        if (!$token) {
            $auth = rcube_utils::request_header('Authorization')
                ?: ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (preg_match('/^Bearer\s+(\S+)$/i', (string) $auth, $m)) {
                $token = $m[1];
            }
        }

        $user = $token ? $this->verify_token($token) : null;

        if (!$user) {
            throw new identity_api_exception('unauthorized', 401, null, ['WWW-Authenticate' => 'Bearer realm="identity_api"']);
        }

        return $user;
    }

    /**
     * Token format: <user_id>.<token_id>.<secret>
     * Only sha256(secret) is stored in the user's preferences.
     *
     * Rotation: a new secret requested via the "rotate" action is kept as
     * "pending" next to the current one. The current secret stays valid until
     * a pending one is used for the first time, which then replaces it. So a
     * client that never receives the rotate response keeps working.
     */
    private function verify_token($token)
    {
        if (!preg_match('/^(\d+)\.([a-f0-9]{8})\.([A-Za-z0-9_-]{20,})$/', trim($token), $m)) {
            return null;
        }

        $user = new rcube_user((int) $m[1]);
        if (!$user->ID) {
            return null;
        }

        // other plugins' hooks (preferences_update etc.) should see this user
        $this->rc->user = $user;

        $prefs  = $user->get_prefs();
        $tokens = (array) ($prefs[self::PREF_KEY] ?? []);
        $id     = $m[2];

        if (empty($tokens[$id]) || !is_array($tokens[$id])) {
            return null;
        }

        $entry   = $tokens[$id];
        $hash    = hash('sha256', $m[3]);
        $promote = null;

        if (!hash_equals((string) ($entry['hash'] ?? ''), $hash)) {
            foreach ((array) ($entry['pending'] ?? []) as $pending) {
                if (hash_equals((string) ($pending['hash'] ?? ''), $hash)) {
                    $promote = $pending;
                }
            }
            if (!$promote) {
                return null;
            }
        }

        // account state: locked by login_rate_limit, no webmail login for too long, other plugins
        if ($user->is_locked()) {
            return null;
        }
        $max_age = (int) $this->rc->config->get('identity_api_max_login_age', 0);
        if ($max_age && strtotime((string) ($user->data['last_login'] ?? '')) < time() - $max_age * 86400) {
            return null;
        }
        $hook = $this->rc->plugins->exec_hook('identity_api_authenticate', ['user' => $user, 'token_id' => $id, 'valid' => true]);
        if (empty($hook['valid'])) {
            return null;
        }

        // tokens from before rotation support have no "issued": start counting now
        $issued = $promote ? (int) $promote['issued'] : (isset($entry['issued']) ? (int) $entry['issued'] : time());

        // tokens without rotation (for clients that can't rotate), if the admin allows them
        $static = !empty($entry['static']) && $this->static_tokens_allowed();

        // not rotated for too long: expired
        $lifetime = $static ? 0 : $this->token_lifetime();
        if ($lifetime && $issued < time() - $lifetime * 86400) {
            $this->update_tokens($user, function ($tokens) use ($id) {
                unset($tokens[$id]);
                return $tokens;
            });
            return null;
        }

        $touch = ($entry['last_used'] ?? 0) < time() - 600; // at most every 10 minutes
        if ($promote || $touch || !isset($entry['issued'])) {
            $this->update_tokens($user, function ($tokens) use ($id, $promote, $issued) {
                if (empty($tokens[$id])) {
                    return $tokens; // revoked meanwhile
                }
                if ($promote) {
                    // first use of a rotated secret: it replaces the old one
                    $tokens[$id]['hash']    = $promote['hash'];
                    $tokens[$id]['pending'] = [];
                }
                $tokens[$id]['issued']    = $issued;
                $tokens[$id]['last_used'] = time();
                return $tokens;
            });
        }

        $rotation = $static ? 0 : $this->token_rotation();
        $this->token_status = [
            'id'      => $id,
            'static'  => $static,
            'rotate'  => $rotation > 0 && $issued <= time() - $rotation * 86400,
            'expires' => $lifetime ? $issued + $lifetime * 86400 : null,
        ];

        return $user;
    }

    /**
     * Re-read the user's tokens right before writing and apply $fn, so that
     * concurrent changes (settings page, parallel requests) are not lost.
     */
    private function update_tokens(rcube_user $user, callable $fn)
    {
        $fresh  = new rcube_user($user->ID);
        $prefs  = $fresh->get_prefs();
        $tokens = $fn((array) ($prefs[self::PREF_KEY] ?? []));
        $fresh->save_prefs([self::PREF_KEY => $tokens], true);

        return $tokens;
    }

    /** Timestamps of identities created through the API in the last hour. */
    private function recent_creations(rcube_user $user)
    {
        $fresh = new rcube_user($user->ID);
        $prefs = $fresh->get_prefs();
        $since = time() - 3600;

        return array_values(array_filter((array) ($prefs[self::PREF_RECENT] ?? []), function ($ts) use ($since) {
            return (int) $ts > $since;
        }));
    }

    private static function random_secret()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Time of the last rotation. Tokens from before rotation support count from now on. */
    private static function token_issued(array $token)
    {
        return isset($token['issued']) ? (int) $token['issued'] : time();
    }

    private function static_tokens_allowed()
    {
        return (bool) $this->rc->config->get('identity_api_static_tokens', false);
    }

    /** Rotation interval in days, 0 = no rotation. */
    private function token_rotation()
    {
        return max(0, (int) $this->rc->config->get('identity_api_token_rotation', 30));
    }

    /** Days after which a token that was not rotated expires, 0 = never. */
    private function token_lifetime()
    {
        $lifetime = max(0, (int) $this->rc->config->get('identity_api_token_lifetime', 90));
        $rotation = $this->token_rotation();

        // a lifetime shorter than the rotation interval would expire every token
        if ($lifetime && $rotation && $lifetime <= $rotation) {
            $lifetime = 2 * $rotation;
        }

        return $lifetime;
    }

    public static function create_token($user_id, array &$tokens, $label, $static = false)
    {
        do {
            $id = bin2hex(random_bytes(4));
        }
        while (isset($tokens[$id]));

        $secret = self::random_secret();

        $tokens[$id] = [
            'label'     => $label,
            'hash'      => hash('sha256', $secret),
            'created'   => time(),
            'issued'    => time(),
            'last_used' => 0,
            'static'    => (bool) $static,
        ];

        return $user_id . '.' . $id . '.' . $secret;
    }

    // ------------------------------------------------------------------
    // Settings UI (Settings > Preferences > Shop address API)

    public function prefs_sections($args)
    {
        $args['list'][self::SECTION] = ['id' => self::SECTION, 'section' => $this->gettext('sectiontitle')];

        return $args;
    }

    public function prefs_list($args)
    {
        if ($args['section'] != self::SECTION) {
            return $args;
        }

        $tokens   = (array) $this->rc->config->get(self::PREF_KEY, []);
        $lifetime = $this->token_lifetime();
        $valid    = $this->purge_expired($tokens, $lifetime);
        foreach ($valid as $id => $token) {
            if (is_array($token) && !isset($token['issued'])) {
                $valid[$id]['issued'] = time(); // token from before rotation support
            }
        }
        if ($valid !== $tokens) {
            $this->rc->user->save_prefs([self::PREF_KEY => $valid]);
            $tokens = $valid;
        }
        $blocks = [];

        // address generator
        $domains = $this->safe_allowed_domains($this->rc->user);
        $shop    = new html_inputfield(['id' => 'identityapi-shop', 'size' => 30, 'autocomplete' => 'off',
            'spellcheck' => 'false', 'placeholder' => $this->gettext('shopplaceholder')]);
        $domain  = '';
        if (count($domains) > 1) {
            $select = new html_select(['id' => 'identityapi-domain']);
            foreach ($domains as $d) {
                $select->add('@' . $d, $d);
            }
            $domain = ' ' . $select->show($domains[0]);
        }
        $blocks['generate'] = [
            'name'    => $this->gettext('generate'),
            'options' => [
                'shop' => [
                    'title'   => html::label('identityapi-shop', rcube::Q($this->gettext('shop'))),
                    'content' => $shop->show('') . $domain
                        . html::div('formbuttons',
                            html::tag('button', ['type' => 'button', 'id' => 'identityapi-create', 'class' => 'button btn btn-primary'],
                                rcube::Q($this->gettext('createaddress')))
                            . ' ' . html::tag('button', ['type' => 'button', 'id' => 'identityapi-showlist', 'class' => 'button btn btn-secondary'],
                                rcube::Q($this->gettext('showexisting'))))
                        . html::div(['id' => 'identityapi-result', 'class' => 'identityapi-result'], '')
                        . html::div('hint', rcube::Q($this->gettext('generatehint'))),
                ],
            ],
        ];

        // connection details: URL of the REST API (identity_api.js resolves a relative
        // setting against the browser's address and checks that the API answers there)
        $api   = $this->api_url();
        $input = new html_inputfield(['id' => 'identityapi-url', 'size' => 50, 'readonly' => 'readonly', 'data-api' => $api]);

        $blocks['connection'] = [
            'name'    => $this->gettext('connection'),
            'options' => [
                'url' => [
                    'title'   => html::label('identityapi-url', rcube::Q($this->gettext('apiurl'))),
                    'content' => $input->show($api)
                        . html::div(['id' => 'identityapi-urlcheck', 'class' => 'hint'], ''),
                ],
            ],
        ];

        // freshly created token, shown exactly once
        if ($args['current'] == self::SECTION && !empty($_SESSION[self::SESS_TOKEN])) {
            $new = $_SESSION[self::SESS_TOKEN];
            $this->rc->session->remove(self::SESS_TOKEN);
            $input = new html_inputfield(['id' => 'identityapi-newtoken', 'size' => 50, 'readonly' => 'readonly']);

            // "connect" button: the browser extension's content script finds this
            // element, enables the button and takes over URL and token on click
            // "connect" button: the browser extension intercepts the click (without
            // touching the page before, so pages can't detect it) and takes over the
            // token; without extension, the click just shows a hint
            $missing = json_encode($this->gettext('connectmissing'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            $button  = html::tag('button', ['type' => 'button', 'class' => 'button btn btn-secondary',
                'onclick' => "this.parentNode.querySelector('.hint').textContent = $missing; return false"],
                rcube::Q($this->gettext('connect')));
            $connect = html::div(['id' => 'identityapi-connect', 'data-token' => $new, 'data-api' => $api],
                $button . html::div('hint', rcube::Q($this->gettext('connecthint'))));

            $blocks['connection']['options']['newtoken'] = [
                'title'   => html::label('identityapi-newtoken', rcube::Q($this->gettext('newtoken'))),
                'content' => $input->show($new)
                    . html::div('hint', rcube::Q($this->gettext('newtokenhint')))
                    . $connect,
            ];
        }

        // address format: prefix and domains
        $blocks['domains'] = ['name' => $this->gettext('addresses'), 'options' => []];

        $user    = $this->rc->user;
        $example = $this->generator($user)->build('bookshop', $this->safe_allowed_domains($user)[0] ?? 'example.org',
            null, $this->user_prefix($user), 64);
        $example = $this->gettext(['name' => 'example', 'vars' => ['email' => $example]]);

        // pattern
        if ($this->template_locked()) {
            $content = html::span('', rcube::Q($this->user_template($user))) . html::div('hint', rcube::Q($example));
        }
        else {
            $input = new html_inputfield(['name' => '_identity_api_template', 'id' => 'identityapi-template',
                'size' => 40, 'maxlength' => 64, 'placeholder' => $this->admin_template(), 'autocomplete' => 'off',
                'spellcheck' => 'false']);
            $content = $input->show((string) $this->rc->config->get(self::PREF_TEMPLATE, ''))
                . html::div('hint', rcube::Q($this->gettext(['name' => 'templatehint',
                    'vars' => ['default' => $this->admin_template()]]) . ' ' . $example));
        }
        $blocks['domains']['options']['template'] = [
            'title'   => html::label('identityapi-template', rcube::Q($this->gettext('template'))),
            'content' => $content,
        ];

        if ($this->uses_prefix($user)) {
            $prefix = $this->user_prefix($user);

            if ($this->prefix_locked()) {
                $content = html::span('', rcube::Q($prefix));
            }
            else {
                $input = new html_inputfield(['name' => '_identity_api_prefix', 'id' => 'identityapi-prefix',
                    'size' => 16, 'maxlength' => identity_api_generator::PREFIX_MAXLENGTH,
                    'placeholder' => $this->default_prefix($this->rc->user), 'autocomplete' => 'off']);
                $content = $input->show((string) $this->rc->config->get(self::PREF_PREFIX, ''))
                    . html::div('hint', rcube::Q($this->gettext(['name' => 'prefixhint',
                        'vars' => ['default' => $this->default_prefix($this->rc->user)]])));
            }

            $blocks['domains']['options']['prefix'] = [
                'title'   => html::label('identityapi-prefix', rcube::Q($this->gettext('prefix'))),
                'content' => $content,
            ];
        }
        $locked  = $this->domains_locked();
        $current = (array) $this->rc->config->get(self::PREF_DOMAINS, []);

        if ($locked) {
            $content = html::span('', rcube::Q(implode(', ', $this->safe_allowed_domains($this->rc->user))))
                . html::div('hint', rcube::Q($this->gettext('domainslocked')));
        }
        else {
            $textarea = new html_textarea(['name' => '_identity_api_domains', 'id' => 'identityapi-domains',
                'rows' => 3, 'cols' => 40, 'placeholder' => 'example.org']);
            $hint = $this->gettext('domainshint');
            if ($patterns = $this->domain_patterns()) {
                $hint .= ' ' . $this->gettext(['name' => 'domainsallowed', 'vars' => ['domains' => implode(', ', $patterns)]]);
            }
            if (empty($current) && ($effective = $this->safe_allowed_domains($this->rc->user))) {
                $hint .= ' ' . $this->gettext(['name' => 'domainsdefault', 'vars' => ['domains' => implode(', ', $effective)]]);
            }
            $content = $textarea->show(implode("\n", $current)) . html::div('hint', rcube::Q($hint));
        }

        $blocks['domains']['options']['domains'] = [
            'title'   => html::label('identityapi-domains', rcube::Q($this->gettext('domainlist'))),
            'content' => $content,
        ];

        // existing tokens: name and dates on the left, only the revoke switch
        // on the right (Elastic renders checkbox rows side by side, also on phones)
        $blocks['tokens'] = ['name' => $this->gettext('tokens'), 'options' => []];

        if (!empty($tokens)) {
            $blocks['tokens']['options']['hint'] = [
                'content' => html::div('hint', rcube::Q($this->gettext('revokehint'))),
            ];
        }

        foreach ($tokens as $id => $token) {
            $field_id = 'identityapi-revoke-' . $id;
            $name     = $token['label'] ?: $id;
            $checkbox = new html_checkbox(['name' => '_identity_api_revoke[]', 'id' => $field_id, 'value' => $id,
                'title' => $this->gettext('revoke'), 'aria-label' => $this->gettext('revoke') . ': ' . $name]);
            $info     = $this->gettext('created') . ': ' . $this->rc->format_date($token['created'])
                . ' · ' . $this->gettext('lastused') . ': '
                . (!empty($token['last_used']) ? $this->rc->format_date($token['last_used']) : $this->gettext('never'));
            if (self::token_issued($token) > (int) ($token['created'] ?? 0)) {
                $info .= ' · ' . $this->gettext('renewed') . ': ' . $this->rc->format_date(self::token_issued($token));
            }
            if (!empty($token['static']) && $this->static_tokens_allowed()) {
                $info .= ' · ' . $this->gettext('statictoken');
            }
            else if ($lifetime) {
                $info .= ' · ' . $this->gettext('expires') . ': '
                    . $this->rc->format_date(self::token_issued($token) + $lifetime * 86400);
            }

            $blocks['tokens']['options']['token_' . $id] = [
                'title'   => html::label($field_id, rcube::Q($name)) . html::div('hint', rcube::Q($info)),
                'content' => $checkbox->show(),
            ];
        }

        if (empty($tokens)) {
            $blocks['tokens']['options']['none'] = [
                'title'   => '',
                'content' => html::span('hint', rcube::Q($this->gettext('notokens'))),
            ];
        }

        // rotation/expiry policy (server configuration)
        $rotation = $this->token_rotation();
        if ($rotation || $lifetime) {
            $policy = $rotation
                ? $this->gettext(['name' => 'rotationinfo', 'vars' => ['rotation' => $rotation, 'lifetime' => $lifetime]])
                : $this->gettext(['name' => 'lifetimeinfo', 'vars' => ['lifetime' => $lifetime]]);
            if ($rotation && !$lifetime) {
                $policy = $this->gettext(['name' => 'rotationonlyinfo', 'vars' => ['rotation' => $rotation]]);
            }
            // show it at the top of the block, before the token list
            $blocks['tokens']['options'] = ['policy' => ['content' => html::div('hint', rcube::Q($policy))]]
                + $blocks['tokens']['options'];
        }

        // new token
        $input = new html_inputfield(['name' => '_identity_api_new_label', 'id' => 'identityapi-label',
            'size' => 30, 'maxlength' => 64, 'placeholder' => $this->gettext('labelplaceholder')]);

        $blocks['new'] = [
            'name'    => $this->gettext('createtoken'),
            'options' => [
                'label' => [
                    'title'   => html::label('identityapi-label', rcube::Q($this->gettext('label'))),
                    'content' => $input->show('')
                        . html::div('hint', rcube::Q($this->gettext('createtokenhint'))),
                ],
            ],
        ];

        if ($this->static_tokens_allowed() && ($this->token_rotation() || $lifetime)) {
            $checkbox = new html_checkbox(['name' => '_identity_api_new_static', 'id' => 'identityapi-static', 'value' => 1]);
            $blocks['new']['options']['static'] = [
                'title'   => html::label('identityapi-static', rcube::Q($this->gettext('newstatic')))
                    . html::div('hint', rcube::Q($this->gettext('newstatichint'))),
                'content' => $checkbox->show(),
            ];
        }

        $args['blocks'] = $blocks;

        return $args;
    }

    public function prefs_save($args)
    {
        if ($args['section'] != self::SECTION) {
            return $args;
        }

        if (!$this->template_locked() && isset($_POST['_identity_api_template'])) {
            $template = strtolower(trim((string) rcube_utils::get_input_string('_identity_api_template', rcube_utils::INPUT_POST)));
            if ($template !== '' && ($error = identity_api_generator::template_error($template)) !== null) {
                $args['abort']   = true;
                $args['result']  = false;
                $args['message'] = $this->gettext(['name' => 'invalidtemplate', 'vars' => ['error' => rcube::Q($error)]]);
                return $args;
            }
            // empty or equal to the admin pattern: don't store, so the admin pattern applies
            $args['prefs'][self::PREF_TEMPLATE] = ($template === '' || $template === $this->admin_template()) ? null : $template;
        }

        if (!$this->prefix_locked() && isset($_POST['_identity_api_prefix'])) {
            $input  = trim((string) rcube_utils::get_input_string('_identity_api_prefix', rcube_utils::INPUT_POST));
            $prefix = $this->generator()->sanitize_prefix($input);

            if ($input !== '' && $prefix === '') {
                $args['abort']   = true;
                $args['result']  = false;
                $args['message'] = $this->gettext(['name' => 'invalidprefix', 'vars' => ['prefix' => rcube::Q($input)]]);
                return $args;
            }

            // empty or equal to the default: don't store, so the default applies
            $args['prefs'][self::PREF_PREFIX] = ($prefix === '' || $prefix === $this->default_prefix($this->rc->user))
                ? null : $prefix;
        }

        if (!$this->domains_locked() && isset($_POST['_identity_api_domains'])) {
            $domains = $this->parse_domain_input(rcube_utils::get_input_string('_identity_api_domains', rcube_utils::INPUT_POST));
            $stored  = (array) $this->rc->config->get(self::PREF_DOMAINS, []);
            // only validate changed input, so stale entries (e.g. after the admin
            // restricted the allowed domains) don't block revoking tokens
            foreach ($domains === $stored ? [] : $domains as $domain) {
                $error = !identity_api_generator::valid_domain($domain) ? 'invaliddomain'
                    : (!$this->domain_permitted($domain) ? 'domainnotallowed' : null);
                if ($error) {
                    $args['abort']   = true;
                    $args['result']  = false;
                    $args['message'] = $this->gettext(['name' => $error, 'vars' => ['domain' => rcube::Q($domain)]]);
                    return $args;
                }
            }
            $args['prefs'][self::PREF_DOMAINS] = $domains;
        }

        $tokens = (array) $this->rc->config->get(self::PREF_KEY, []);

        foreach ((array) rcube_utils::get_input_value('_identity_api_revoke', rcube_utils::INPUT_POST) as $id) {
            if (is_string($id)) {
                unset($tokens[$id]);
            }
        }

        $label = trim((string) rcube_utils::get_input_string('_identity_api_new_label', rcube_utils::INPUT_POST));

        if ($label !== '') {
            $max = (int) $this->rc->config->get('identity_api_max_tokens', 10);
            if (count($tokens) >= $max) {
                $args['abort']   = true;
                $args['result']  = false;
                $args['message'] = $this->gettext('toomanytokens');
                return $args;
            }

            $static = $this->static_tokens_allowed() && !empty($_POST['_identity_api_new_static']);
            $token  = self::create_token($this->rc->user->ID, $tokens, mb_substr($label, 0, 64), $static);
            $_SESSION[self::SESS_TOKEN] = $token;
        }

        $args['prefs'][self::PREF_KEY] = $tokens;

        return $args;
    }

    // ------------------------------------------------------------------
    // Address generator in the settings (AJAX, session authenticated)

    /** "gardenshop.example" or "https://www.gardenshop.example/..." typed as shop name: use it as website address. */
    private function ui_shop_input()
    {
        $raw = trim((string) rcube_utils::get_input_string('_shop', rcube_utils::INPUT_POST));
        if (preg_match('~^(https?://)?[^\s/]+\.[a-z]{2,}([/:?#]|$)~i', $raw)) {
            $_POST['_url']  = preg_match('~^https?://~i', $raw) ? $raw : 'https://' . $raw;
            $_POST['_shop'] = '';
        }
    }

    public function ui_create()
    {
        $this->add_texts('localization/');
        $this->ui_shop_input();
        try {
            $identity = $this->create_identity($this->rc->user);
            $this->rc->output->command('plugin.identity_api_created', [
                'email' => rcube_utils::idn_to_utf8($identity['email']),
                'shop'  => $identity['shop'],
            ]);
        }
        catch (identity_api_exception $e) {
            $this->rc->output->show_message($this->error_text($e), 'error', null, false);
        }
        $this->rc->output->send();
    }

    public function ui_list()
    {
        $this->add_texts('localization/');
        $this->ui_shop_input();
        $shop   = $this->generator()->sanitize_shop($this->request_param('shop'))
            ?: $this->generator()->shop_from_url($this->request_param('url'));
        $emails = [];
        if ($shop !== '') {
            foreach ($this->find_identities($this->rc->user, $shop) as $identity) {
                $emails[] = rcube_utils::idn_to_utf8($identity['email']);
            }
        }
        $this->rc->output->command('plugin.identity_api_list', ['shop' => $shop, 'emails' => $emails]);
        $this->rc->output->send();
    }

    private function error_text(identity_api_exception $e)
    {
        $label = 'error_' . $e->error_code;
        return $this->rc->text_exists('identity_api.' . $label) ? $this->gettext($label) : $e->getMessage();
    }

    // ------------------------------------------------------------------
    // Helpers

    /** Generator for the user's pattern (or the admin pattern / a given one). */
    /** Base URL of the REST API, relative to Roundcube's URL or absolute (identity_api_url). */
    private function api_url()
    {
        $url = trim((string) $this->rc->config->get('identity_api_url', 'api/identity/'));
        if ($url === '' || !preg_match('~^(https?://[^/?#]+)?/?[^?#]*$~i', $url)) {
            $url = 'api/identity/';
        }

        return rtrim($url, '/') . '/';
    }

    private function generator(rcube_user $user = null, $template = null)
    {
        return new identity_api_generator([
            'template'       => $template ?: ($user ? $this->user_template($user) : $this->admin_template()),
            'random_length'  => $this->rc->config->get('identity_api_random_length', 8),
            'charset'        => $this->rc->config->get('identity_api_random_chars', identity_api_generator::DEFAULT_CHARSET),
            'shop_maxlength' => $this->rc->config->get('identity_api_shop_maxlength', 30),
        ]);
    }

    /**
     * Domains the user may create addresses for. First entry is the default.
     * Order of precedence: user's own list (settings UI), admin default list
     * (identity_api_domains), domain of the user's default identity.
     */
    private function allowed_domains(rcube_user $user)
    {
        $domains = [];

        if (!$this->domains_locked()) {
            $prefs = $user->get_prefs();
            foreach ((array) ($prefs[self::PREF_DOMAINS] ?? []) as $domain) {
                if (identity_api_generator::valid_domain($domain) && $this->domain_permitted($domain)) {
                    $domains[] = $domain;
                }
            }
        }

        foreach (empty($domains) ? (array) $this->rc->config->get('identity_api_domains', []) : [] as $domain) {
            $domain = $this->resolve_domain_setting($domain);
            if (identity_api_generator::valid_domain($domain)) {
                $domains[] = $domain;
            }
        }

        if (empty($domains)) {
            $default = $user->get_identity();
            $email   = $default['email'] ?? $user->get_username();
            $domain  = strtolower(substr((string) strrchr((string) $email, '@'), 1));

            if (identity_api_generator::valid_domain($domain)) {
                $domains[] = $domain;
            }
        }

        if (empty($domains)) {
            throw new identity_api_exception('no domain configured (identity_api_domains)', 500, 'no_domain_configured');
        }

        return array_values(array_unique($domains));
    }

    /** Drop tokens that were not rotated within $lifetime days. */
    private function purge_expired(array $tokens, $lifetime)
    {
        if ($lifetime <= 0) {
            return $tokens;
        }

        $limit = time() - $lifetime * 86400;

        $static = $this->static_tokens_allowed();

        return array_filter($tokens, function ($token) use ($limit, $static) {
            return is_array($token) && (($static && !empty($token['static'])) || self::token_issued($token) >= $limit);
        });
    }

    private function uses_prefix(rcube_user $user)
    {
        return strpos($this->user_template($user), '{prefix}') !== false;
    }

    /** Pattern configured by the admin (identity_api_template). */
    private function admin_template()
    {
        $template = (string) $this->rc->config->get('identity_api_template', identity_api_generator::DEFAULT_TEMPLATE);
        if (($error = identity_api_generator::template_error($template)) !== null) {
            $this->config_warning("identity_api: invalid identity_api_template '$template': $error");
            return identity_api_generator::DEFAULT_TEMPLATE;
        }

        return $template;
    }

    /** Admin can lock the pattern via dont_override. */
    private function template_locked()
    {
        return in_array(self::PREF_TEMPLATE, (array) $this->rc->config->get('dont_override', []));
    }

    /** Pattern for new addresses: user setting, else admin pattern. */
    private function user_template(rcube_user $user)
    {
        if (!$this->template_locked()) {
            $prefs    = $user->get_prefs();
            $template = (string) ($prefs[self::PREF_TEMPLATE] ?? '');
            if ($template !== '' && identity_api_generator::template_error($template) === null) {
                return $template;
            }
        }

        return $this->admin_template();
    }

    /** Generators for all patterns a user's addresses may have (listing). */
    private function list_generators(rcube_user $user)
    {
        $templates = array_unique([$this->user_template($user), $this->admin_template()]);

        return array_map(function ($template) {
            return $this->generator(null, $template);
        }, $templates);
    }

    /** Admin can lock the prefix via dont_override. */
    private function prefix_locked()
    {
        return in_array(self::PREF_PREFIX, (array) $this->rc->config->get('dont_override', []));
    }

    /** Admin default (identity_api_default_prefix) or first letter of the username. */
    private function default_prefix(rcube_user $user)
    {
        $generator = $this->generator();
        $prefix    = $generator->sanitize_prefix((string) $this->rc->config->get('identity_api_default_prefix', ''));

        return $prefix !== '' ? $prefix : $generator->derive_prefix($user->get_username());
    }

    /** Prefix for new addresses: user setting, else default. */
    private function user_prefix(rcube_user $user)
    {
        if (!$this->prefix_locked()) {
            $prefs  = $user->get_prefs();
            $prefix = $this->generator()->sanitize_prefix((string) ($prefs[self::PREF_PREFIX] ?? ''));
            if ($prefix !== '') {
                return $prefix;
            }
        }

        return $this->default_prefix($user);
    }

    private function safe_allowed_domains(rcube_user $user)
    {
        try {
            return $this->allowed_domains($user);
        }
        catch (identity_api_exception $e) {
            return [];
        }
    }

    /** Admin can lock the user's domain list via dont_override. */
    private function domains_locked()
    {
        return in_array(self::PREF_DOMAINS, (array) $this->rc->config->get('dont_override', []));
    }

    /** Admin restriction for user-configured domains, e.g. ['example.org', '*.example.net'] */
    private function domain_patterns()
    {
        $patterns = [];
        foreach ((array) $this->rc->config->get('identity_api_allowed_domains', []) as $pattern) {
            $patterns[] = $this->resolve_domain_setting($pattern);
        }

        return array_filter($patterns);
    }

    /**
     * Resolve Roundcube placeholders in a configured domain. Host based ones
     * (%n, %t, %d) come from the request's Host header and are only trusted
     * with trusted_host_patterns; session based ones (%h, %z, %s) don't exist
     * for API requests.
     */
    private function resolve_domain_setting($value)
    {
        $value = strtolower(trim((string) $value));

        if (strpos($value, '%') === false) {
            return $value;
        }

        if (preg_match('/%[hzs]/', $value)) {
            $this->config_warning("identity_api: placeholders %h, %z and %s are not supported in domain settings ($value)");
            return '';
        }

        if (empty($this->rc->config->get('trusted_host_patterns'))) {
            $this->config_warning("identity_api: domain setting '$value' uses the Host header, set \$config['trusted_host_patterns'] to use it");
            return '';
        }

        return strtolower((string) rcube_utils::parse_host($value));
    }

    private function config_warning($message)
    {
        static $logged = [];
        if (empty($logged[$message])) {
            $logged[$message] = true;
            rcube::raise_error(['code' => 500, 'file' => __FILE__, 'line' => __LINE__, 'message' => $message], true, false);
        }
    }

    private function domain_permitted($domain)
    {
        // no restriction configured: any domain. A configured list that resolves
        // to nothing (e.g. untrusted placeholders) permits nothing.
        if (empty($this->rc->config->get('identity_api_allowed_domains'))) {
            return true;
        }

        $patterns = $this->domain_patterns();

        foreach ($patterns as $pattern) {
            if ($pattern === $domain) {
                return true;
            }
            if (strpos($pattern, '*.') === 0) {
                $suffix = substr($pattern, 1); // ".example.org"
                if (strlen($domain) > strlen($suffix) && substr($domain, -strlen($suffix)) === $suffix) {
                    return true;
                }
            }
        }

        return false;
    }

    /** "@Example.org, shop.example.net\n" -> ['example.org', 'shop.example.net'] */
    private function parse_domain_input($input)
    {
        $domains = [];
        foreach (preg_split('/[\s,;]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY) as $domain) {
            $domain = strtolower(ltrim(trim($domain), '@'));
            $ascii  = rcube_utils::idn_to_ascii($domain);
            $domains[] = $ascii ?: $domain;
        }

        return array_values(array_unique($domains));
    }

    private function default_name(rcube_user $user)
    {
        $default = $user->get_identity();

        return $default['name'] ?? '';
    }

    /**
     * Check all identities of all users (including deleted ones), the mail
     * server routes by identity so addresses must be globally unique.
     */
    /**
     * Address used by any identity (also deleted ones), optionally of other users
     * than $except_user. Case-insensitive.
     */
    private function email_exists($email, $except_user = null)
    {
        $db    = $this->rc->get_dbh();
        $sql   = "SELECT 1 FROM " . $db->table_name('identities', true) . " WHERE LOWER(`email`) = ?";
        $args  = [strtolower(rcube_utils::idn_to_ascii($email))];
        if ($except_user) {
            $sql   .= " AND `user_id` <> ?";
            $args[] = $except_user;
        }
        $result = $db->limitquery($sql, 0, 1, ...$args);

        return (bool) $db->fetch_array($result);
    }

    private function request_param($name)
    {
        static $body;

        if ($body === null) {
            $body = [];
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($type, 'application/json') !== false) {
                $body = json_decode(file_get_contents('php://input'), true) ?: [];
            }
        }

        if (isset($body[$name]) && is_scalar($body[$name])) {
            return (string) $body[$name];
        }

        return rcube_utils::get_input_string('_' . $name, rcube_utils::INPUT_GPC)
            ?: rcube_utils::get_input_string($name, rcube_utils::INPUT_GPC);
    }

    /** RFC 9457 problem details */
    private function send_problem($status, $code, $detail, array $headers = [])
    {
        $this->send($status, [
            'type'   => 'about:blank',
            'title'  => self::STATUS_TEXT[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'code'   => $code,
        ], $headers, 'application/problem+json');
    }

    private function send($status, $data, array $headers = [], $type = 'application/json')
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        // don't leave a session behind for token-only requests
        if (empty($_SESSION['user_id']) && $this->rc->session) {
            $this->rc->session->nowrite = true;
            $this->rc->session->destroy(session_id());
            header_remove('Set-Cookie');
        }

        http_response_code($status);

        // Extensions with host permissions don't need CORS, but allow
        // extension origins explicitly in case permissions are restricted.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (preg_match('#^(moz|chrome|safari-web)-extension://[a-z0-9-]+$#i', $origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Identity-Api-Token');
            header('Access-Control-Expose-Headers: Location, Retry-After, Identity-Api-Token-Rotate, Identity-Api-Token-Expires');
            header('Access-Control-Max-Age: 600');
            header('Vary: Origin');
        }

        header('Cache-Control: no-store');

        // token status as headers
        if ($this->token_status) {
            header('Identity-Api-Token-Rotate: ' . ($this->token_status['rotate'] ? 'true' : 'false'));
            if ($this->token_status['expires']) {
                header('Identity-Api-Token-Expires: ' . gmdate('Y-m-d\TH:i:s\Z', $this->token_status['expires']));
            }
        }

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($data === null) {
            header_remove('Content-Type');
        }
        else {
            header('Content-Type: ' . $type . '; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        exit;
    }
}

class identity_api_exception extends Exception
{
    /** @var string Machine readable error code, e.g. "domain_not_allowed" */
    public $error_code;

    /** @var array Extra HTTP headers */
    public $headers;

    public function __construct($message, $status = 400, $error_code = null, array $headers = [])
    {
        parent::__construct($message, $status);
        $this->error_code = $error_code ?: trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($message)), '_');
        $this->headers    = $headers;
    }
}
