<?php
require_once "api/bootstrap.php";

if (empty($_COOKIE['feedit'])) {
  header('location: index.php' . ( !empty($_COOKIE['feedit-in-app']) ? '?app=1' : ''));
  exit;
}

// delete active session
$mongo->{MONGO_DB_NAME}->sessions->deleteOne( [ 'auth_hash' => (string) $_COOKIE['feedit'] ] );

// set login cookie in the past, so it will be removed
setcookie('feedit', '', time() - 100000);

header('location: index.php' . ( !empty($_COOKIE['feedit-in-app']) ? '?app=1' : ''));
exit;