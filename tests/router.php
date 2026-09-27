<?php

// Router for PHP's built-in web server (php -S ... router.php), does what the
// rewrite rule of the README does in nginx or Apache:
// <roundcube>/api/identity/<path> -> index.php?_task=identity_api&_path=<path>

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!preg_match('~^/api/identity(/.*)$~', $path, $m)) {
    return false; // everything else as usual
}

$_GET = ['_task' => 'identity_api', '_path' => $m[1]] + $_GET;
$_REQUEST = $_GET + $_REQUEST;
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';

chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
