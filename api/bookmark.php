<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  // validate record
  if (empty($_POST['id'])) {
    send_error($lang['Missing parameter'], $lang['Article ID Empty'], 400, 'validation', ['api' => 'bookmark', 'field' => 'id']);
  }

  try {
    $link_object = new MongoDB\BSON\ObjectId( (string) $_POST['id'] );
  } catch (\Exception $ex) {
    send_error($lang['System Error'], $lang['Article ID not ObjectID'], 400, 'validation', [ 'api' => 'bookmark', 'id' => $_POST['id'] ]);
  }

  // validate action
  if (!isset($_POST['action'])) {
    send_error($lang['Missing parameter'], $lang['Action is missing'], 400, 'validation', ['api' => 'bookmark', 'field' => 'action']);
  } else {
    if ($_POST['action'] == 1) {
      $_POST['action'] = 1;
    } else {
      $_POST['action'] = 0;
    }
  }

  // update bookmark
  $mongo->bayesian->{'training-' . $user->short_id}->updateOne( [ '_id' => $link_object ], [ '$set' => [ 'bookmarked' => $_POST['action'] ] ] );

  // also update the processed collection
  $mongo->bayesian->processed->updateOne( [ '_id' => $link_object ], [ '$inc' => [ 'bookmarked_times' => ($_POST['action'] ? 1 : -1) ] ] );

  // send out nothing if all is OK