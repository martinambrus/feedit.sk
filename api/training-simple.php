<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";
  require_once "../functions/functions-score-global.php";
  require_once "../functions/functions-words.php";
  require_once "../functions/functions-training.php";

  if ((empty($_POST['items']) || !is_array($_POST['items'])) && empty($_POST['feed'])) {
    send_error($lang['Missing parameter'], $lang['Trained Article Data Incomplete'], 400, 'validation', ['api' => 'training-simple', 'field' => 'items']);
  }

  // if we have feed, make it a MongoDB ObjectID, otherwise convert all items into MongoDB ObjectIDs
  if (empty($_POST['feed'])) {
    // convert all link IDs into ObjectIDs
    array_walk( $_POST['items'], function ( &$value ) {
      global $lang;

      // convert each item in the array into a MongoDB ObjectID
      try {
        $value = new MongoDB\BSON\ObjectId( (string) $value );
      } catch ( \Exception $ex ) {
        send_error( $lang['System Error'], $lang['Article ID not ObjectID'], 400, 'validation', [
          'api' => 'training-simple',
          'id'  => $value,
        ] );
      }
    } );
  } else {
    try {
      $feed_object = new MongoDB\BSON\ObjectId( (string) $_POST['feed'] );
    } catch ( \Exception $ex ) {
      send_error( $lang['System Error'], $lang['Feed ID not ObjectID'], 400, 'validation', [
        'api' => 'training-simple',
        'id'  => $_POST['feed'],
      ] );
    }
  }

  if (!isset($_POST['rating'])) {
    send_error($lang['Missing parameter'], $lang['Rating Missing From Data'], 400, 'validation', ['api' => 'training-simple', 'field' => 'rating']);
  }

  if ((int) $_POST['rating'] === 1) {
    // rating positively
    $_POST['rating'] = 1;
  } else if ((int) $_POST['rating'] === -1 && empty($_POST['feed'])) { // no un-training for full-feed training
    // un-training links
    $_POST['rating'] = -1;
  } else {
    // rating negatively
    $_POST['rating'] = 0;
  }

  // load data for all items being trained and train them
  $cached_feed_data = [];

  // caches used in calculate_score() method
  $scored_words = [];
  $scored_ngrams = [];
  $cached_authors = [];
  $cached_categories = [];

  // the following variable will serve to empty our caches from previous feed
  // when we switch to the next one, so we don't store all caches at once
  $last_feed_used = '';

  // data from the global processed table
  $records = [];

  // load either full feed or items
  if (empty($_POST['feed'])) {
    $filter = ['_id' => [ '$in' => $_POST['items'] ] ];
  } else {
    $filter = ['feed' => $feed_object, 'trained' => 0, 'score' => [ '$gte' => 0 ] ];
  }

  // TODO: fire up Lambdas to handle training in batches with IDs spread to 200 Lambdas (max)
  foreach ($mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->find( $filter ) as $record) {
    $records[ (string) $record->_id ] = $mongo->{MONGO_DB_NAME}->processed->findOne( [ '_id' => $record->_id ] );
    rate_link( $record, (int) $_POST['rating'] );
  }

  // no output is sent out if everything went smoothly