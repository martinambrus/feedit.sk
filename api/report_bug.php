<?php
require_once "authentication.php";
require_once "../lang/locales.php";
require_once "../functions/functions-user.php";

if (empty($_POST['type'])) {
  send_error($lang['Missing parameter'], $lang['Problem type is empty'], 400, 'validation', ['api' => 'bug_report', 'field' => 'type']);
}

if (empty($_POST['msg'])) {
  send_error($lang['Missing parameter'], $lang['Problem description is empty'], 400, 'validation', ['api' => 'bug_report', 'field' => 'msg']);
}

// get user's e-mail
$user_email = decode_user_email( $mongo->bayesian->accounts->findOne( [ '_id' => $user->_id ] ) );

$headers = get_mail_header();

// TODO: change verification address in e-mail body
if ( !send_mail('infofeedit@gmail.com', ($_POST['type'] == 'bug' ? 'FeedIt Bug Report' : 'FeedIt Account Question'), 'User ' . $user_email . " send the following report:\n\n\n" . $_POST['msg'], implode("\n", $headers)) ) {
  send_error($lang['Error Sending Report'], $lang['There was a problem while trying to send your report. Please try again or contact us directly on infofeedit@gmail.com'], 503, 'mail', ['api' => 'report_bug']);
}

// no output when all is OK