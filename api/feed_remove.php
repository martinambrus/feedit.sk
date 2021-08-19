<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['id'])) {
    send_error($lang['Missing parameter'], $lang['Feed ID empty'], 400, 'validation', ['api' => 'feed_remove', 'field' => 'id']);
  }

  // add feeds to the global user object
  $user_feeds = $mongo->{MONGO_DB_NAME}->accounts->findOne( [ '_id' => $user->_id ], [ 'projection' => [ 'feeds' => 1 ] ] );
  $user->feeds = $user_feeds->feeds;

  // update this user's account to exclude the given feed
  try {
    $feed_object = new MongoDB\BSON\ObjectId( (string) $_POST['id'] );
  } catch (\Exception $ex) {
    send_error($lang['System Error'], $lang['Feed ID not ObjectID'], 400, 'validation', [ 'api' => 'feed_remove', 'feed_id' => $_POST['id'] ]);
  }

  $index = 0;
  $removed = false;
  foreach ($user->feeds as $user_feed) {
    if ((string) $user_feed == (string) $feed_object) {
      unset($user->feeds[ $index ]);
      $removed = true;
      break;
    }

    $index++;
  }

  // no such feed to remove for this user
  if (!$removed) {
    send_error($lang['Invalid Feed ID'], $lang['Feed Not Found'], 400, 'validation', ['api' => 'feed_remove', 'field' => 'feed', 'value' => (string) $_POST['id'] ]);
  }

  // update the accounts collections
  $mongo->{MONGO_DB_NAME}->accounts->updateOne([ '_id' => $user->_id ], [ '$set' => [ 'feeds' => $user->feeds ] ]);

  // update feeds and decrease subscribers count
  // TODO: decrease premium subscribers when ready (and if the user is premium)
  $mongo->{MONGO_DB_NAME}->feeds->updateOne([ '_id' => $feed_object ], [ '$inc' => [ 'normal_subscribers' => -1 ] ]);

  // remove data related to this feed from all relevant collections
  $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->deleteMany([ 'feed' => $feed_object ]);
  $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->deleteMany([ 'feed' => $feed_object ]);
  $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->deleteMany([ '_id' => $feed_object ]);
  $mongo->{MONGO_DB_NAME}->{'labels-' . $user->short_id}->deleteMany([ 'feed' => $feed_object ]);
  $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user->short_id}->deleteMany([ 'feed' => $feed_object ]);
  $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->deleteMany([ 'feed' => $feed_object ]);
  $mongo->{MONGO_DB_NAME}->{'words-' . $user->short_id}->deleteMany([ 'feed' => $feed_object ]);

  // no response is sent out if a deletion is successful to save bandwidth