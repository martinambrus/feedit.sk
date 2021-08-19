<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";
  require_once "../functions/functions-score-global.php";
  require_once "../functions/functions-training.php";

  if (empty($_POST['links']) || !is_array($_POST['links'])) {
    send_error($lang['Missing parameter'], $lang['Trained Article Data Incomplete'], 400, 'validation', ['api' => 'labels-give', 'field' => 'links']);
  }

  // convert all link IDs into ObjectIDs
  array_walk($_POST['links'], function(&$value) {
    global $lang;

    // convert each item in the array into a MongoDB ObjectID
    try {
      $value = new MongoDB\BSON\ObjectId( (string) $value );
    } catch (\Exception $ex) {
      send_error($lang['System Error'], $lang['Article ID not ObjectID'], 400, 'validation', [ 'api' => 'labels-give', 'id' => $value ]);
    }
  });

  if (empty($_POST['labels']) || (!is_array($_POST['labels']) && $_POST['labels'] !== 'empty')) {
    send_error($lang['Missing parameter'], $lang['Labels to Assign Empty'], 400, 'validation', ['api' => 'labels-give', 'field' => 'labels']);
  }

  if ($_POST['labels'] !== 'empty') {
    // convert all label IDs into ObjectIDs
    array_walk( $_POST['labels'], function ( &$value ) {
      global $lang;

      // convert each item in the array into a MongoDB ObjectID
      try {
        $value = new MongoDB\BSON\ObjectId( (string) $value );
      } catch ( \Exception $ex ) {
        send_error( $lang['System Error'], $lang['Label ID not ObjectID'], 400, 'validation', [ 'id' => $value ] );
      }
    } );
  }

  // remove label predictions and labels from all these links, as we'll be assigning a set of new labels to them
  $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany([ '_id' => [ '$in' => $_POST['links'] ] ], [ '$unset' => [ 'label_predictions' => 1, 'labels' => 1 ] ]);

  // if we're not just removing everything, now add the labels we've set to add
  if ($_POST['labels'] !== 'empty') {
    // add all of the labels to these links
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ '_id' => [ '$in' => $_POST['links'] ] ], [ '$set' => [ 'labels' => $_POST['labels'] ] ] );
  }

  // train label predictions for each of these links that's trained positively (up)
  // ... first, cache all words IDs used in these links
  $words = [];
  foreach ($mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->find(
    [
      '_id' => [
        '$in' => $_POST['links'],
      ],
      'labels' => [
        '$exists' => 1
      ],
      'rated' => 1,
    ],
    [
      'projection' => [
        'words' => 1,
      ]
    ]) as $record) {
    $words = array_merge( $words, (array) $record->words );
  }

  // get and prepare data for all the cached words
  if (count($words)) {
    $words_data = [];
    foreach ($mongo->{MONGO_DB_NAME}->{'words-' . $user->short_id}->find([ '_id' => [ '$in' => $words ] ]) as $record) {
      $words_data[ $record->word ] = $record;
    }

    if (count($words_data)) {
      // train words on all of the labels
      train_link_labels( $_POST['labels'], $words_data );
    }
  }

  // no output is sent out if everything went smoothly