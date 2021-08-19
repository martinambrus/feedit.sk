<?php
  require_once "bootstrap.php";

  $login_ok = true;

  if (!empty($_COOKIE['feedit'])) {
    $session = $mongo->bayesian->sessions->findOne( [ 'auth_hash' => (string) $_COOKIE['feedit'], 'expires' => [ '$gt' => time() ] ] );
    if ( !$session ) {
      $login_ok = false;
    }

    $user = $mongo->bayesian->accounts->findOneAndUpdate( [ 'hash' => $session->hash ], [ '$set' => [ 'last_activity' => time() ] ], [ 'projection' => [ 'short_id' => 1, 'lang' => 1, 'timezone' => 1 ] ] );
    if (!$user) {
      $login_ok = false;
    } else {
      // set timezone to this user's timezone
      date_default_timezone_set( $user->timezone );
    }
  } else {
    $login_ok = false;
  }

  if (!$login_ok) {
    send_error('Unauthorized', '', 404);
  }