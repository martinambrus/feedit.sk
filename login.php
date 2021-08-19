<?php
require_once "api/bootstrap.php";

if (empty($_GET['id']) && empty($_POST['email'])) {
  header('Content-Type: text/html; charset=utf-8');
  die($lang['No account to log into.'] . '<br><br><a href="index.php">&lt;&lt; ' . $lang['Back'] . '</a>');
}

require_once('functions/functions-user.php');

// check the hash or email
if (!empty($_GET['id'])) {
  $existing = $mongo->bayesian->accounts->findOne( [ 'hash' => (string) $_GET['id'] ] );
} else {
  $existing = get_existing_user( mb_strtolower( (string) $_POST['email'] ) );
}

if (!$existing) {
  if (!empty($_GET['id'])) {
    header( 'Content-Type: text/html; charset=utf-8' );
    die( $lang['Account not found.'] . '<br><br><a href="vlog.php?lang=' . LANGUAGE . '&amp;id=' . $_GET['id'] . '">&lt;&lt; ' . $lang['Back'] . '</a>' );
  } else {
    send_error('', $lang['Account not found.'], 404, 'login');
  }
}

// if the account is not activated yet, don't allow a login
if (!$existing->active) {
  if (!empty($_GET['id'])) {
    header( 'Content-Type: text/html; charset=utf-8' );
    die( $lang['Account not activated. Please check your mailbox for verification e-mail.'] . '<br><br><a href="vlog.php?lang=' . LANGUAGE . '&amp;id=' . $_GET['id'] . '">&lt;&lt; ' . $lang['Back'] . '</a>' );
  } else {
    send_error('', $lang['Account not activated. Please check your mailbox for verification e-mail.'], 400, 'login');
  }
}

// clean up all expired sessions
$mongo->bayesian->sessions->deleteMany([ 'expires' => [ '$lt' => time() ] ]);

// check if we are logging-in
if (!empty($_GET['hash'])) {
  $session = $mongo->bayesian->sessions->findOne(
    [
      'hash' => $existing->hash,
      'auth_hash' => (string) $_GET['hash']
    ]
  );

  // check session existence
  if ($session) {
    // store last login time, update session cookie
    $mongo->bayesian->accounts->updateOne([ '_id' => $existing->_id ],
      [
        '$set' => [
          'last_login' => time(),
          'last_activity' => time(),
        ]
      ]
    );

    // set authentication cookie
    setcookie('feedit', $session->auth_hash, $session->expires);

    // redirect
    header('Location: app.php?lang=' . LANGUAGE);
    exit;
  } else {
    // invalid session - either the token expired or is invalid
    header('Content-Type: text/html; charset=utf-8');
    die($lang['Invalid verification token.'] . '<br><br><a href="vlog.php?lang=' . LANGUAGE . '&amp;id=' . $_GET['id'] . '">&lt;&lt; ' . $lang['Back'] . '</a>');
  }
} else {
  // create new authentication hash, if not created within the last 5 minutes
  if (
    !$mongo->bayesian->sessions->findOne(
      [
        'hash' => $existing->hash,
        'expires' => [
          '$gt' =>
            time()          // now
            + (60*60*24*30) // +30 days
            - (60*5)        // -5 minutes
        ],
        'first_hash' => [
          '$exists' => false
        ],
      ]
    )
  ) {
    $auth_hash = ( $existing->_id . time() );
    $auth_time = ( time() + ( 60 * 60 * 24 * 30 ) );

    // create new session
    $mongo->bayesian->sessions->insertOne(
      [
        'hash' => $existing->hash,
        'auth_hash' => $auth_hash,
        'expires'   => $auth_time, // now + 30 days
      ]
    );

    // send login e-mail
    $headers = get_mail_header();

    // TODO: change authentication address in e-mail body
    header( 'Content-Type: text/html; charset=utf-8' );
    if ( send_mail( decode_user_email( $existing ), $lang['FeedIt login confirmation'], $lang['Please follow this link to log into your account: '] . 'http://feedit.sk/login.php?id=' . $existing->hash . '&hash=' . $auth_hash . '&lang=' . LANGUAGE . "\n\n" . $lang['Your verification code'] . ': ' . $auth_hash, implode( "\n", $headers ) ) ) {
      if ( ! empty( $_GET['id'] ) ) {
        echo $lang['A login confirmation e-mail was sent to the e-mail address recorded for your account. Please check your mailbox.'];
      } else {
        send_ok( $lang['A login confirmation e-mail was sent to the e-mail address recorded for your account. Please check your mailbox.'], [ 'id' => $existing->hash, 'lang' => LANGUAGE ] );
      }
    } else {
      if ( ! empty( $_GET['id'] ) ) {
        echo $lang['Our systems could not send you a login confirmation e-mail. Please try again.'];
      } else {
        send_error( $lang['System Error'], $lang['Our systems could not send you a login confirmation e-mail. Please try again.'], 500, 'system' );
      }
    }
    exit;
  }
}

// we've already sent out this e-mail in the past 5 minutes, don't do it again, just let the user know
if (!empty($_GET['id'])) {
  echo $lang['A login confirmation e-mail was sent to the e-mail address recorded for your account. Please check your mailbox.'];
} else {
  send_ok($lang['A login confirmation e-mail was sent to the e-mail address recorded for your account. Please check your mailbox.']);
}