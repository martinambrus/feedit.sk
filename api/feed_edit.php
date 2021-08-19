<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['feed'])) {
    send_error($lang['Missing parameter'], $lang['Feed ID empty'], 400, 'validation', ['api' => 'feed_edit', 'field' => 'feed']);
  }

  try {
    $feed_object = new MongoDB\BSON\ObjectId( (string) $_POST['feed'] );
  } catch (\Exception $ex) {
    send_error($lang['System Error'], $lang['Feed ID not ObjectID'], 400, 'validation', [ 'api' => 'feed_edit', 'feed_id' => $_POST['feed'] ]);
  }

  if (empty($_POST['feed_lang'])) {
    send_error($lang['Missing parameter'], $lang['Lang Field Empty'], 400, 'validation', ['api' => 'feed_edit', 'field' => 'feed_lang']);
  }

  if (empty($_POST['feed_title'])) {
    send_error($lang['Missing parameter'], $lang['Title Field Empty'], 400, 'validation', ['api' => 'feed_edit', 'field' => 'feed_title']);
  }

  if (empty($_POST['allow_duplicates'])) {
    send_error($lang['Missing parameter'], $lang['Allow Duplicates Setting Empty'], 400, 'validation', ['api' => 'feed_edit', 'field' => 'allow_duplicates']);
  }

  // update feed data
  $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->updateOne( ['_id' => $feed_object], [
    '$set' => [
      'title' => (string) $_POST['feed_title'],
      'lang'  => (string) $_POST['feed_lang'],
      'allow_duplicates' => (isset($_POST['allow_duplicates']) && $_POST['allow_duplicates'] == 'true' ? 1 : 0),
    ],
  ]);

  // no output is sent out if everything was updated OK