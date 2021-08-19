<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";
  require_once "../functions/functions-content.php";
  require_once "../functions/functions-feeds.php";

  $data = get_all_feeds_data();
  $all_count = 0;

  // sort feeds
  if (!isset($_POST['feeds_sort']) || $_POST['feeds_sort'] == 'name') {
    // sort by name
    $resort = [];

    // first prepare the data for sorting
    foreach ($data as $key => $value) {
      if ($key != 'bookmarks_count' && $key != 'all_count') {
        $resort[ $value->title ] = $value;
        $all_count += $value->count;
      } else {
        $resort[ $key ] = $value;
      }
    }

    // sort resulting data
    natksort( $resort );
    $data = $resort;
  } else if (isset($_POST['feeds_sort']) && $_POST['feeds_sort'] == 'unread') {
    // sort by unread counts
    $resort = [];

    // first prepare the data for sorting
    foreach ($data as $key => $value) {
      if ($key != 'bookmarks_count' && $key != 'all_count') {
        if (!isset($resort[$value->count])) {
          $resort[$value->count] = [];
        }

        $resort[$value->count][ $value->title ] = $value;
        $all_count += $value->count;
      } else {
        $resort[ $key ] = $value;
      }
    }

    // sort the resulting data
    $data = [];
    krsort($resort);

    foreach ($resort as $scored_items) {
      // sort only if this is not a bookmarks_count item
      if (is_array($scored_items)) {
        // sort scored items by alphabet
        ksort( $scored_items );

        foreach ($scored_items as $item) {
          $data[] = $item;
        }
      } else {
        $data['bookmarks_count'] = $scored_items;
      }
    }
  }

  // add "all" count to data
  $data['all_count'] = $all_count;

  // check if we should be watching for feeds with 100+ items trained but <5% negatively
  if (!isset($_COOKIE['training_check_warning_displayed'])) {
    $warn = false;
    foreach ($data as $feed_data) {
      if (is_object($feed_data)) {
        $all_docs = $mongo->bayesian->{'training-' . $user->short_id}->countDocuments( [ 'feed'    => new MongoDB\BSON\ObjectId( $feed_data->id ) ] );

        $neg_docs = $mongo->bayesian->{'training-' . $user->short_id}->countDocuments( [
          'feed'    => new MongoDB\BSON\ObjectId( $feed_data->id ),
          'trained' => 1,
          'rated'   => 0,
        ] );

        if ( $all_docs > 100 && ( ( $neg_docs / $all_docs ) * 100 ) < 5 ) {
          $warn = true;
          // we only need one feed to match this query for the warning to appear on front-end
          break;
        }
      }
    }

    if ($warn) {
      $data['low_negatives_warning'] = 1;
    }
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( $data, \JSON_UNESCAPED_UNICODE );