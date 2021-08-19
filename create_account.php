<?php
//const DONT_CONNECT = true;
require_once "bootstrap.php";

if (empty($_POST['email'])) {
  send_error($lang['Missing parameter'], $lang['E-mail must not be empty.'], 400, 'validation', ['field' => 'email']);
}

if (empty($_POST['tz'])) {
  send_error($lang['Missing parameter'], $lang['Timezone must not be empty.'], 400, 'validation', ['field' => 'timezone']);
}

if (empty($_POST['lang'])) {
  send_error($lang['Missing parameter'], $lang['Language must not be empty.'], 400, 'validation', ['field' => 'lang']);
}

if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
  send_error($lang['Invalid E-Mail Address'], $lang['E-Mail must contain a valid e-mail address.'], 400, 'validation', ['field' => 'email']);
}

if (!in_array($_POST['tz'], DateTimeZone::listIdentifiers(DateTimeZone::ALL)) ) {
  send_error($lang['Invalid Timezone'], $lang['Timezone must contain a valid e-mail zone.'], 400, 'validation', ['field' => 'timezone']);
}

require_once('functions/functions-user.php');

$_POST['email'] = mb_strtolower( (string) $_POST['email'] );

// check that this e-mail is not in use already
$existing = get_existing_user( $_POST['email'] );

if ($existing !== false) {
  send_error($lang['E-Mail Already Registered'], $lang['The given e-mail address is already registered. Please choose another.'], 403, 'validation', ['field' => 'email']);
}

// generate random account ID
$acc_id = generate_random_id();

// encrypt e-mail
$salts = ( array_slice(permute($acc_id), 0, 366) );
$now_time = time();
$day_created = date('z', $now_time);
$email_hash = password_hash($_POST['email'], PASSWORD_BCRYPT, [ 'cost' => 9 ]);
$email_encrypted = openssl_encrypt( $_POST['email'], 'AES-256-CBC', $salts[ $day_created ], false, substr($email_hash, 0, 16) );

// create this account with the e-mail provided
$mongo->{MONGO_DB_NAME}->accounts->insertOne(
[
    'short_id' => $acc_id,
    'hash' => md5($acc_id),
    'email' => $email_encrypted,
    'ehash' => $email_hash,
    'confirmed' => 0, // e-mail confirmation
    'created' => $now_time,
    'last_login' => 0,
    'last_activity' => 0,
    'active' => 0,
    'timezone' => (string) $_POST['tz'],
    'lang' => (string) $_POST['lang'],
  ]
);

$headers = get_mail_header();

// TODO: change verification address in e-mail body
if (send_mail($_POST['email'], $lang['FeedIt e-mail confirmation'], $lang['Please follow this link to verify your e-mail and create an account: '] . 'http://feedit.sk/v.php?id=' . $acc_id . '&lang=' . LANGUAGE . "\n\n" . $lang['Your verification code'] . ': ' . $acc_id, implode("\n", $headers))) {
  send_ok($lang['Verification e-mail was sent to the provided e-mail address. Please check your inbox (and also your spam folder if you cannot find this e-mail) in order to finish creating your account.'], [ 'lang' => LANGUAGE ]);
} else {
  send_error($lang['Verification E-Mail Sending Failed'], $lang['Our systems could not send you a verification e-mail. Please try again.'], 503, 'system');
}