<?php

/**
 * Address generation for the identity_api plugin.
 *
 * Pure helper without Roundcube dependencies, so it can be tested standalone.
 *
 * Template placeholders:
 *   {prefix} personal prefix of the user (e.g. "m"), see derive_prefix()
 *   {shop}   sanitized shop name (e.g. "bookshop", "gardenshop-example")
 *   {year}   four digit year
 *   {random} random string (length/charset configurable)
 */
class identity_api_generator
{
    public const DEFAULT_TEMPLATE = '{prefix}-{shop}-{year}-{random}';
    public const PREFIX_MAXLENGTH = 16;
    public const DEFAULT_CHARSET  = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private $template;
    private $random_length;
    private $charset;
    private $shop_maxlength;

    public function __construct(array $options = [])
    {
        $this->template       = $options['template'] ?? self::DEFAULT_TEMPLATE;
        $this->random_length  = max(4, (int) ($options['random_length'] ?? 8));
        $this->charset        = (string) ($options['charset'] ?? self::DEFAULT_CHARSET);
        $this->shop_maxlength = max(3, (int) ($options['shop_maxlength'] ?? 30));

        if (($error = self::template_error($this->template)) !== null) {
            throw new InvalidArgumentException('identity_api template: ' . $error);
        }
        if ($this->charset === '' || preg_match('/[^a-z0-9]/', $this->charset)) {
            throw new InvalidArgumentException('identity_api charset must only contain [a-z0-9]');
        }
    }

    public const PLACEHOLDERS = ['{prefix}', '{shop}', '{year}', '{random}'];

    /**
     * Check a local part template. Returns null if valid, else an error message.
     * Text around the placeholders may use a-z, 0-9, ".", "_" and "-".
     */
    public static function template_error($template)
    {
        $template = (string) $template;

        if (strlen($template) > 64) {
            return 'too long';
        }
        foreach (['{shop}', '{random}'] as $required) {
            if (substr_count($template, $required) != 1) {
                return "must contain $required exactly once";
            }
        }
        foreach (['{prefix}', '{year}'] as $optional) {
            if (substr_count($template, $optional) > 1) {
                return "may contain $optional only once";
            }
        }
        $literal = str_replace(self::PLACEHOLDERS, '', $template);
        if (!preg_match('/^[a-z0-9._-]*$/', $literal)) {
            return 'only a-z, 0-9, ".", "_", "-" and the placeholders {prefix}, {shop}, {year}, {random} are allowed';
        }
        if (preg_match('/^\.|\.$|\.\./', $template)) {
            return 'must not start or end with "." or contain ".."';
        }
        // adjacent placeholders can't be told apart when listing addresses
        if (preg_match('/\}\{/', $template)) {
            return 'placeholders must be separated, e.g. by "-"';
        }

        return null;
    }

    public function template()
    {
        return $this->template;
    }

    /**
     * Normalize a shop name or host into a local-part friendly slug.
     * "Gärtnerei Grün" -> "gaertnerei-gruen", "www.Gardenshop.example" -> "www-gardenshop-example"
     */
    public function sanitize_shop($shop)
    {
        $shop = mb_strtolower(trim((string) $shop), 'UTF-8');
        $shop = strtr($shop, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '&' => '-und-', '+' => '-plus-']);

        if (preg_match('/[^\x20-\x7e]/', $shop)) {
            if (class_exists('Transliterator')
                && ($tr = Transliterator::create('Any-Latin; Latin-ASCII'))
                && ($t = $tr->transliterate($shop)) !== false
            ) {
                $shop = $t;
            }
            else if (($t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $shop)) !== false) {
                $shop = $t;
            }
            $shop = strtolower($shop);
        }

        $shop = preg_replace('/[^a-z0-9]+/', '-', $shop);
        $shop = substr(trim($shop, '-'), 0, $this->shop_maxlength);

        return trim($shop, '-');
    }

    /** Public suffixes with two labels that are common for shops (same list as the extension). */
    public const MULTI_PART_SUFFIXES = ['co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'co.at', 'or.at',
        'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'co.kr', 'co.za', 'co.in', 'co.il',
        'com.br', 'com.cn', 'com.hk', 'com.mx', 'com.pl', 'com.tr', 'com.tw', 'com.ar', 'com.sg',
        // hosting platforms: every shop has its own subdomain
        'myshopify.com', 'wixsite.com', 'square.site', 'company.site', 'jimdosite.com', 'webflow.io', 'github.io', 'netlify.app', 'vercel.app', 'pages.dev', 'blogspot.com', 'wordpress.com'];

    /**
     * Shop name from a website address: "https://checkout.gardenshop.example/kasse" -> "gardenshop",
     * "https://www.bücherstube.example" -> "buecherstube". Returns '' if there is none (IP, localhost).
     */
    public function shop_from_url($url)
    {
        $host = parse_url(trim((string) $url), PHP_URL_HOST);
        if (!is_string($host) || $host === '' || preg_match('/^[\d.]+$|:/', $host) || strpos($host, '.') === false) {
            return '';
        }

        $labels = explode('.', strtolower(rtrim($host, '.')));
        $suffix = count($labels) > 2 && in_array(implode('.', array_slice($labels, -2)), self::MULTI_PART_SUFFIXES) ? 2 : 1;
        $name   = $labels[count($labels) - $suffix - 1] ?? '';

        if (strpos($name, 'xn--') === 0 && function_exists('idn_to_utf8')) {
            $name = idn_to_utf8($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        }

        return $this->sanitize_shop($name);
    }

    public function random_string()
    {
        $max = strlen($this->charset) - 1;
        $out = '';

        for ($i = 0; $i < $this->random_length; $i++) {
            $out .= $this->charset[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Build a new address. $shop must already be sanitized.
     */
    public function build($shop, $domain, $year = null, $prefix = 'x', $max_local = 0)
    {
        $parts = [
            '{prefix}' => $prefix,
            '{shop}'   => $shop,
            '{year}'   => $year ?: date('Y'),
            '{random}' => $this->random_string(),
        ];
        $local = strtr($this->template, $parts);

        // too long: shorten the shop name
        if ($max_local && strlen($local) > $max_local) {
            $keep = max(1, strlen($shop) - (strlen($local) - $max_local));
            $parts['{shop}'] = rtrim(substr($shop, 0, $keep), '-');
            $local = strtr($this->template, $parts);
        }

        return $local . '@' . strtolower($domain);
    }

    /**
     * Normalize a user supplied prefix: lowercase letters and digits only.
     * Returns '' if nothing usable is left.
     */
    public function sanitize_prefix($prefix)
    {
        $prefix = str_replace('-', '', $this->sanitize_shop($prefix));

        return substr($prefix, 0, self::PREFIX_MAXLENGTH);
    }

    /**
     * Default prefix: first letter of the username's local part,
     * "michael@example.org" -> "m", "Ärger" -> "a".
     */
    public function derive_prefix($username)
    {
        $local  = explode('@', (string) $username)[0];
        $prefix = $this->sanitize_prefix($local);

        return $prefix !== '' ? $prefix[0] : 'x';
    }

    /**
     * Parse an address generated with this template:
     * ['prefix' => ..., 'shop' => ..., 'year' => ...] or null.
     */
    public function parse($email)
    {
        if (!preg_match($this->shop_regex(null), (string) $email, $m)) {
            return null;
        }

        return [
            'prefix' => $m['prefix'] ?? null,
            'shop'   => $m['shop'],
            'year'   => isset($m['year']) ? (int) $m['year'] : null,
        ];
    }

    /**
     * Regular expression matching addresses generated for $shop (any prefix/year/random/domain),
     * or for any shop if $shop is null. $shop must already be sanitized.
     */
    public function shop_regex($shop)
    {
        $parts = preg_split('/(\{prefix\}|\{shop\}|\{year\}|\{random\})/', $this->template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        $chars = preg_quote($this->charset, '/');

        foreach ($parts as $part) {
            switch ($part) {
            case '{prefix}':
                // any prefix, so addresses stay visible after the prefix was changed
                $regex .= '(?<prefix>[a-z0-9]{1,' . self::PREFIX_MAXLENGTH . '})';
                break;
            case '{shop}':
                $regex .= $shop === null ? '(?<shop>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)' : '(?<shop>' . preg_quote($shop, '/') . ')';
                break;
            case '{year}':
                $regex .= '(?<year>\d{4})';
                break;
            case '{random}':
                $regex .= '[' . $chars . ']{' . $this->random_length . '}';
                break;
            default:
                $regex .= preg_quote($part, '/');
            }
        }

        return '/^' . $regex . '@[^@]+$/i';
    }

    public static function valid_domain($domain)
    {
        return is_string($domain)
            && strlen($domain) <= 253
            && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/i', $domain);
    }
}
