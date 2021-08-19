<?php
if (empty($_POST['token'])) {
  exit;
}

require_once "bootstrap.php";

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
//curl_setopt($ch, CURLOPT_VERBOSE, 1);
curl_setopt($ch, CURLOPT_HEADER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$headers = array();
$headers[] = 'Pragma: no-cache';
$headers[] = 'Accept-Language: en';
$headers[] = 'User-Agent: feedit.sk (https://feedit.sk)';
$headers[] = 'Content-Type: multipart/form-data';
$headers[] = 'Accept: */*';
$headers[] = 'Cache-Control: no-cache';
$headers[] = 'Expect:';
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, [
  'secret' => '6Le5VLoZAAAAACBRsIf8MVBehMMzCpRp-MEktPrL',
  'response' => $_POST['token'],
  'remoteip' => $_SERVER['REMOTE_ADDR'], // optional
]);
curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');

$result = curl_exec($ch);

// check for cURL errors
if (curl_errno($ch)) {
  send_error($lang['Re-Captcha Error'], curl_error($ch), 501, 'external');
}

list( $header, $body ) = explode( "\r\n\r\n", $result, 2 );
$body = json_decode($body);

if ($body->success) {
  if (isset($_POST['action'])) {
    if ($_POST['action'] == 'reg') {
      require_once("../create_account.php");
    } else if ($_POST['action'] == 'log') {
      require_once("../login.php");
    }
  }
} else {
  send_error($lang['Re-Captcha Error'], implode(', ', $body->{'error-codes'}), 501, 'external');
}