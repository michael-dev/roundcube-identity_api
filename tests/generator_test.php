<?php

// Standalone tests: php plugin/tests/generator_test.php
require_once __DIR__ . '/../lib/identity_api_generator.php';

$failed = 0;
function check($name, $cond)
{
    global $failed;
    echo ($cond ? "ok   " : "FAIL ") . $name . "\n";
    $failed += $cond ? 0 : 1;
}

$g = new identity_api_generator();

check('sanitize simple', $g->sanitize_shop('Bookshop') === 'bookshop');
check('sanitize umlauts', $g->sanitize_shop('Gärtnerei Grün') === 'gaertnerei-gruen');
check('sanitize ß', $g->sanitize_shop('Großhandel') === 'grosshandel');
check('sanitize punctuation', $g->sanitize_shop('  --Gardenshop.example!! ') === 'gardenshop-example');
check('sanitize accents', $g->sanitize_shop('Café Crème') === 'cafe-creme');
check('sanitize ampersand', $g->sanitize_shop('Haus&Garten') === 'haus-und-garten');
check('sanitize empty', $g->sanitize_shop('!!!') === '');
check('sanitize maxlength', strlen($g->sanitize_shop(str_repeat('abc-', 20))) <= 30);
check('sanitize no trailing dash after cut', substr($g->sanitize_shop(str_repeat('abcd-', 20)), -1) !== '-');

$email = $g->build('bookshop', 'Example.ORG', 2026, 'm');
check('build format', (bool) preg_match('/^m-bookshop-2026-[a-z0-9]{8}@example\.org$/', $email));
check('build default year', (bool) preg_match('/^m-bookshop-' . date('Y') . '-/', $g->build('bookshop', 'example.org', null, 'm')));
check('build other prefix', (bool) preg_match('/^anna-gardenshop-2026-[a-z0-9]{8}@x\.de$/', $g->build('gardenshop', 'x.de', 2026, 'anna')));

check('prefix from username', $g->derive_prefix('michael@example.org') === 'm');
check('prefix from plain username', $g->derive_prefix('Sabine') === 's');
check('prefix umlaut', $g->derive_prefix('ärger@x.de') === 'a');
check('prefix fallback', $g->derive_prefix('!!!@x.de') === 'x');
check('prefix digits', $g->derive_prefix('42er@x.de') === '4');
check('sanitize prefix', $g->sanitize_prefix(' Mi-Ke! ') === 'mike');
check('sanitize prefix length', strlen($g->sanitize_prefix(str_repeat('a', 40))) === 16);
check('build random differs', $g->build('a', 'x.de') !== $g->build('a', 'x.de'));

$re = $g->shop_regex('bookshop');
check('regex matches own', (bool) preg_match($re, $email));
check('regex matches other year', (bool) preg_match($re, 'm-bookshop-2019-abcdefgh@other.de'));
check('regex captures year', preg_match($re, 'm-bookshop-2019-abcdefgh@other.de', $m) && $m['year'] === '2019');
check('regex rejects other shop', !preg_match($re, 'm-bookshop-de-2019-abcdefgh@other.de'));
check('regex rejects prefix shop', !preg_match($g->shop_regex('amazo'), $email));
check('regex rejects plain', !preg_match($re, 'michael@example.org'));
check('regex matches other prefix', (bool) preg_match($re, 'sabine-bookshop-2026-abcdefgh@example.org'));
check('regex rejects prefix with dash', !preg_match($re, 'm-x-bookshop-2026-abcdefgh@example.org'));

$g2 = new identity_api_generator(['template' => 'shop.{shop}.{random}', 'random_length' => 6, 'charset' => 'abc']);
$e2 = $g2->build('gardenshop', 'example.org');
check('custom template', (bool) preg_match('/^shop\.gardenshop\.[abc]{6}@example\.org$/', $e2));
check('custom regex', (bool) preg_match($g2->shop_regex('gardenshop'), $e2));

try {
    new identity_api_generator(['template' => 'm-{shop}']);
    check('template validation', false);
} catch (InvalidArgumentException $e) {
    check('template validation', true);
}

check('template valid default', identity_api_generator::template_error(identity_api_generator::DEFAULT_TEMPLATE) === null);
check('template valid dots', identity_api_generator::template_error('{prefix}.{shop}.{random}') === null);
check('template needs random', identity_api_generator::template_error('{prefix}-{shop}') !== null);
check('template needs shop once', identity_api_generator::template_error('{shop}-{shop}-{random}') !== null);
check('template rejects chars', identity_api_generator::template_error('{shop}+{random}') !== null);
check('template rejects @', identity_api_generator::template_error('{shop}-{random}@x') !== null);
check('template rejects unknown placeholder', identity_api_generator::template_error('{shop}-{foo}-{random}') !== null);
check('template rejects adjacent placeholders', identity_api_generator::template_error('{shop}{random}') !== null);
check('template rejects leading dot', identity_api_generator::template_error('.{shop}-{random}') !== null);
$p = $g->parse('m-garden-shop-2026-abcdefgh@example.org');
check('parse generated', $p === ['prefix' => 'm', 'shop' => 'garden-shop', 'year' => 2026], json_encode($p));
check('parse foreign', $g->parse('michael@example.org') === null);
$g3 = new identity_api_generator(['template' => 's.{shop}.{random}']);
check('parse custom template', ($g3->parse('s.bookshop.' . str_repeat('a', 8) . '@x.de')['shop'] ?? '') === 'bookshop');

check('shop from url', $g->shop_from_url('https://checkout.gardenshop.example/kasse') === 'gardenshop');
check('shop from url co.uk', $g->shop_from_url('https://www.example-tea.co.uk/basket') === 'example-tea');
check('shop from url umlaut', $g->shop_from_url('https://www.bücherstube.example/') === 'buecherstube');
check('shop from url punycode', $g->shop_from_url('https://www.xn--grtnerei-grn-gcb06a.example/') === 'gaertnerei-gruen');
check('shop from url ip', $g->shop_from_url('http://192.168.1.10/shop') === '');
check('shop from url garbage', $g->shop_from_url('not a url') === '');

check('domain valid', (bool) identity_api_generator::valid_domain('example.org'));
check('domain sub valid', (bool) identity_api_generator::valid_domain('mail.example.co.uk'));
check('domain invalid', !identity_api_generator::valid_domain('localhost'));
check('domain IDN TLD', (bool) identity_api_generator::valid_domain('xn--e1afmkfd.xn--p1ai'));
$long = $g->build(str_repeat('abcdefghij', 6), 'x.de', 2026, 'prefix', 64);
check('local part limited', strlen(strstr($long, '@', true)) <= 64 && (bool) preg_match('/^prefix-abcdef[a-z-]*-2026-[a-z0-9]{8}@x\.de$/', $long));
check('domain injection', !identity_api_generator::valid_domain('example.org>evil'));

exit($failed ? 1 : 0);
