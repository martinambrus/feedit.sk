<?php
session_write_close();

const DEBUG = false;
const LAST_UPDATE = 1605090146;
require_once "utils.php";

if (!ini_get('date.timezone')) {
  date_default_timezone_set('Europe/Prague');
}

// for the sake of simplicity, use language from GET array and set it into the POST array,
// as we work with post all the way down
if (!empty($_GET['lang']) && empty($_POST['lang'])) {
  $_POST['lang'] = $_GET['lang'];
}

// try to determine language from browser, if not provided by the client
if (empty($_POST['lang']) && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
  $_POST['lang'] = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
}

// fallback to English, if we cannot determine language
if ( empty($_POST['lang']) ) {
  $_POST['lang'] = 'en';
}

// determine correct prefix for language file inclusion
$prefix = '../';
if (substr(getcwd(), -3) != 'api' && substr(getcwd(), -4) != 'cron') {
  $prefix = '';
}

define('INCLUDES_PREFIX', $prefix);
$lang_file = INCLUDES_PREFIX . 'lang/' . filter_filename((string) $_POST['lang'] . '.php');

// no such language, fall back to English
if (!file_exists( $lang_file )) {
  $_POST['lang'] = 'en';
  $lang_file = INCLUDES_PREFIX . 'lang/' . filter_filename($_POST['lang'] . '.php');
}

// include language file
require_once $lang_file;

define('LANGUAGE', $_POST['lang']);
setcookie('lang', LANGUAGE, strtotime('+30 days'));

// don't connect unless we need to
if (!defined('DONT_CONNECT') || DONT_CONNECT !== true) {
  require_once INCLUDES_PREFIX . 'vendor/autoload.php';
  $mongo = ( new MongoDB\Client );
}