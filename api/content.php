<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['feed'])) {
    send_error($lang['Missing parameter'], $lang['Feed ID empty'], 400, 'validation', ['api' => 'content', 'field' => 'feed']);
  }

  if ($_POST['feed'] != 'all' && $_POST['feed'] != 'bookmarks') {
    try {
      $feed_object = new MongoDB\BSON\ObjectId( (string) $_POST['feed'] );
    } catch (\Exception $ex) {
      send_error($lang['System Error'], $lang['Feed ID not ObjectID'], 400, 'validation', [ 'api' => 'content', 'feed_id' => $_POST['feed'] ]);
    }
  }

  require_once "../functions/functions-content.php";
  cache_labels();
  list( $filter, $options, $trained ) = build_filters_and_options();
  //var_dump($filter, $options); exit;
  $processed_data = $trained->find( $filter, $options );
  $processed_ids  = [];
  $processed      = [];

  foreach ( $processed_data as $record ) {
    // change feed ID to string, if present
    if (isset($record->feed)) {
      $record->feed = (string) $record->feed;
    }

    $processed[ (string) $record->_id ] = $record;
    $processed_ids[] = $record->_id;
  }

  if (count($processed)) {
    // add title, description, link, img, labels... into the trained result set
    complete_trained_data( $processed, $processed_ids );
  } else if ( isset($feed_object) ) {
    // check that our feed hasn't been erroring out for extended time periods,
    // in which case we'll output the last error info
    $feed_data = $mongo->bayesian->feeds->findOne([ '_id' => $feed_object ], [ 'projection' => [ 'subsequent_errors_counter' => 1, 'last_error' => 1 ] ]);

    if ($feed_data->subsequent_errors_counter > 2 && $feed_data->last_error) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode( [ 'error' =>  $feed_data->last_error ], \JSON_UNESCAPED_UNICODE );
      exit;
    }
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( array_values($processed), \JSON_UNESCAPED_UNICODE );