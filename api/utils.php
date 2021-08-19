<?php
function send_error($title, $detail, $status, $type = 'general', $extra_params = []) {
  header('Content-Type: application/json; charset=utf-8');

  switch ($status) {
    case 400: header('HTTP/1.0 400 Bad Request'); break;
    case 401: header('HTTP/1.0 401 Unauthorized'); break;
    case 403: header('HTTP/1.0 403 Forbidden'); break;
    case 404: header('HTTP/1.0 404 Not Found'); break;
    case 501: header('HTTP/1.0 501 Not Implemented'); break;
    case 503: header('HTTP/1.0 503 Service Unavailable'); break;
    default: header('HTTP/1.0 500 Internal Server Error'); break;
  }

  $out = [
    'error' => [
      'type' => $type,
      'title' => $title,
      'detail' => $detail,
      'status' => $status,
      'instance' => $_SERVER['REQUEST_URI']
    ]
  ];

  if (count($extra_params)) {
    $out['error'] = array_merge($out['error'], $extra_params);
  }

  echo json_encode($out, \JSON_UNESCAPED_UNICODE);

  exit;
}

function send_ok( $message, $extradata = [] ) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'response' => [
      'status' => 200,
      'message' => $message,
      'extra' => $extradata,
    ]
  ], \JSON_UNESCAPED_UNICODE);

  exit;
}

function filter_filename($name) {
  // remove illegal file system characters https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
  $name = str_replace(array_merge(
    array_map('chr', range(0, 31)),
    array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
  ), '', $name);
  // maximise filename length to 255 bytes http://serverfault.com/a/9548/44086
  $ext = pathinfo($name, PATHINFO_EXTENSION);
  $name= mb_strcut(pathinfo($name, PATHINFO_FILENAME), 0, 255 - ($ext ? strlen($ext) + 1 : 0), mb_detect_encoding($name)) . ($ext ? '.' . $ext : '');
  return $name;
}

function generate_random_id() {
  //return base_convert(microtime(false), 10, 36);
  return base_convert(rand(100000, 10000000000) + round(microtime(true)) + rand(100000, 10000000000), 10, 36);
}

function mb_ucfirst(string $str, string $encoding = null): string
{
  if ($encoding === null) {
    $encoding = mb_internal_encoding();
  }
  return mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding) . mb_substr($str, 1, null, $encoding);
}

function rgb2hex($rgb) {
  list($r, $g, $b) = $rgb;
  return sprintf("#%02x%02x%02x", $r, $g, $b);
}

/**
 * Increases or decreases the brightness of a color by a percentage of the current brightness.
 *
 * @param   string  $hexCode        Supported formats: `#FFF`, `#FFFFFF`, `FFF`, `FFFFFF`
 * @param   float   $adjustPercent  A number between -1 and 1. E.g. 0.3 = 30% lighter; -0.4 = 40% darker.
 *
 * @return  string
 */
function adjustBrightness($hexCode, $adjustPercent) {
  $hexCode = ltrim($hexCode, '#');

  if (strlen($hexCode) == 3) {
    $hexCode = $hexCode[0] . $hexCode[0] . $hexCode[1] . $hexCode[1] . $hexCode[2] . $hexCode[2];
  }

  $hexCode = array_map('hexdec', str_split($hexCode, 2));

  foreach ($hexCode as & $color) {
    $adjustableLimit = $adjustPercent < 0 ? $color : 255 - $color;
    $adjustAmount = ceil($adjustableLimit * $adjustPercent);

    $color = str_pad(dechex($color + $adjustAmount), 2, '0', STR_PAD_LEFT);
  }

  return '#' . implode($hexCode);
}

function entities_to_unicode($str) {
  $str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
  $str = preg_replace_callback("/(&#[0-9]+;)/", function($m) { return mb_convert_encoding($m[1], "UTF-8", "HTML-ENTITIES"); }, $str);
  return $str;
}

function truncate_text($string, $width, $on = '[break]') {
  if (strlen($string) > $width && false !== ($p = strpos(wordwrap($string, $width, $on), $on))) {
    $string = sprintf('%.'. $p . 's', $string);
  }
  return $string;
}

function untagize( $txt ) {
  // decode all HTML entities, so &gt; becomes > and we can remove tags in the next step
  $txt = html_entity_decode( $txt, ENT_QUOTES || ENT_HTML5, "UTF-8" );

  // remove all HTML tags
  $txt = strip_tags( $txt );

  //  convert all special unicode entities (&#39;) into their ASCII representation (')
  $txt = entities_to_unicode( $txt );

  return $txt;
}

function get_mail_header() {
  return array
  (
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset="UTF-8";',
    'Content-Transfer-Encoding: 7bit',
    'Date: ' . date('r', $_SERVER['REQUEST_TIME']),
    'Message-ID: <' . $_SERVER['REQUEST_TIME'] . md5($_SERVER['REQUEST_TIME']) . '@' . $_SERVER['SERVER_NAME'] . '>',
    'From: ' . SUPPORT_EMAIL,
    'Reply-To: ' . SUPPORT_EMAIL,
    'Return-Path: ' . SUPPORT_EMAIL,
    'X-Mailer: PHP v' . phpversion(),
    'X-Originating-IP: ' . $_SERVER['SERVER_ADDR'],
  );
}

function clean_feed( $body ) {
  // remove content tags, as they usually cause invalid unparsable XML
  $body = preg_replace('/(?s)<[\s\/]*content[^>]*>.*?<\/content[^>]*>/mi', '', $body);

  return $body;
}

function fetch_url( $url, $timeout = 10 ) {
  $fp = curl_init();
  curl_setopt($fp, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($fp, CURLOPT_FAILONERROR, 1);
  curl_setopt($fp, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($fp, CURLOPT_CONNECTTIMEOUT, $timeout);
  //curl_setopt($fp, CURLOPT_VERBOSE, 1);
  curl_setopt($fp, CURLOPT_HEADER, 1);
  curl_setopt($fp, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($fp, CURLOPT_MAXREDIRS, 10);
  curl_setopt($fp,  CURLOPT_CUSTOMREQUEST, 'GET');
  curl_setopt($fp, CURLOPT_ENCODING, '');
  curl_setopt($fp, CURLOPT_URL, $url);
  curl_setopt($fp, CURLOPT_REFERER, $url);
  curl_setopt($fp, CURLOPT_USERAGENT, 'FeedCatcher 1.0');
  curl_setopt($fp, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($fp, CURLOPT_SSL_VERIFYPEER, false);

  $headers = array();
  $headers[] = 'Pragma: ';
  $headers[] = 'Accept-Language: en-us,en;q=0.5';
  $headers[] = 'Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7';
  $headers[] = 'Keep-Alive: 300';
  $headers[] = 'Connection: keep-alive';
  $headers[] = 'Accept: */*';
  $headers[] = 'Cache-Control: max-age=0';
  $headers[] = 'Expect:';
  curl_setopt($fp,  CURLOPT_HTTPHEADER, $headers);

  $result = curl_exec($fp);
  $curl_info = curl_getinfo($fp);
  if (curl_errno($fp) === 23 || curl_errno($fp) === 61) {
    curl_setopt($fp, CURLOPT_ENCODING, 'none');
    $result = curl_exec($fp);
  }

  if (curl_errno($fp)) {
    return [ curl_error($fp) ];
  } else {
    curl_close($fp);

    // return header-less response
    $header_size = $curl_info["header_size"];
    // $header = substr($result, 0, $header_size);
    $body = substr($result, $header_size);

    return clean_feed( $body );
  }
}

function get_feed( $url, $raw_data = false ) {
  require_once "../SimplePie.compiled.php";

  // if we have clear data passed, try to clean it up
  if ($raw_data !== false) {
    $raw_data = clean_feed( $raw_data );
  }

  // try a discovery of feeds using SimplePie
  $feed = new SimplePie();
  $feed->set_useragent('FeedCatcher 1.0'); // resolves FeedBurner's issues
  $feed->set_curl_options([
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_SSL_VERIFYPEER => false,
  ]);
  $feed->enable_cache( false );
  $feed->enable_order_by_date( true );

  if ($raw_data === false) {
    $feed->set_feed_url( $url );
  } else {
    $feed->set_raw_data( $raw_data );
  }

  //$success = $feed->init();
  $feed->init();
  $feed->handle_content_type();

  return $feed;
}

function natksort(&$array) {
  $keys = array_keys($array);
  natcasesort($keys);

  foreach ($keys as $k) {
    $new_array[$k] = $array[$k];
  }

  $array = $new_array;
  return true;
}

function permute($arg) {
  $array = is_string($arg) ? mb_str_split($arg) : $arg;
  if(1 === count($array))
    return $array;
  $result = array();
  foreach($array as $key => $item)
    foreach(permute(array_diff_key($array, array($key => $item))) as $p)
      $result[] = $item . $p;
  return array_unique( $result );
}

function send_mail($to, $subject, $body) {
  // Create the Transport
  $transport = (new Swift_SmtpTransport(SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION))
    ->setUsername(SMTP_USERNAME)
    ->setPassword(SMTP_PASSWORD);

  // Create the Mailer using your created Transport
  $mailer = new Swift_Mailer($transport);

  // Create a message
  $message = (new Swift_Message( $subject ))
    ->setFrom([SUPPORT_EMAIL => 'FeedIt RSS Reader'])
    ->setTo($to)
    ->setBody( $body );

  // Send the message
  return $mailer->send($message);
}