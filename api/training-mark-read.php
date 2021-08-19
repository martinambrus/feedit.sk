<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['items']) || (!is_array($_POST['items']) && $_POST['items'] != 'all')) {
    send_error($lang['Missing parameter'], $lang['Trained Article Data Incomplete'], 400, 'validation', ['api' => 'training-mark-read', 'field' => 'items']);
  }

  // check if we want to make the whole feed read
  if ($_POST['items'] == 'all') {
    if (empty($_POST['feed'])) {
      send_error($lang['Missing parameter'], $lang['Feed ID empty'], 400, 'validation', ['api' => 'training-mark-read', 'field' => 'feed']);
    }

    // check if we have a valid feed ID
    try {
      $feed = new MongoDB\BSON\ObjectId( (string) $_POST['feed'] );
    } catch ( \Exception $ex ) {
      send_error( $lang['System Error'], $lang['Feed ID not ObjectID'], 400, 'validation', [
        'api' => 'training-mark-read',
        'id'  => $_POST['feed'],
      ] );
    }

    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany([ 'feed' => $feed ], [ '$set' => [ 'read' => 1 ] ]);
    exit;
  }

  // iterate over all items and store which items to mark read and which un-read
  $read = [];
  $unread = [];

  foreach ($_POST['items'] as $item) {
    if ($item['read']) {
      try {
        $read[] = new MongoDB\BSON\ObjectId( $item['id'] );
      } catch (\Exception $ex) {
        send_error($lang['System Error'], $lang['Article ID not ObjectID'], 400, 'validation', [ 'id' => $item['id'] ]);
      }
    } else {
      try {
        $unread[] = new MongoDB\BSON\ObjectId( $item['id'] );
      } catch (\Exception $ex) {
        send_error($lang['System Error'], $lang['Article ID not ObjectID'], 400, 'validation', [ 'id' => $item['id'] ]);
      }
    }
  }

  // mark articles read
  if (count($read)) {
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany([ '_id' => [ '$in' => $read ] ], [ '$set' => [ 'read' => 1 ] ]);
  }

  // mark articles unread
  if (count($unread)) {
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany([ '_id' => [ '$in' => $unread ] ], [ '$set' => [ 'read' => 0 ] ]);
  }

  // no output is sent out if everything went smoothly