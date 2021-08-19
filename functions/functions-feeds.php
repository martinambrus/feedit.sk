<?php
  function get_all_feeds_data( $bookmarks_only = false ) {
    global $mongo, $user;

    $feeds        = [];
    $feed_objects = [];

    if (!$bookmarks_only) {
      foreach ( $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->find( [], [ 'projection' => [ '_id' => 1, 'lang' => 1, 'title' => 1, 'allow_duplicates' => 1, 'tiers_training_check' => 1 ] ] ) as $user_feed ) {
        $feeds[ (string) $user_feed->_id ] = $user_feed;
        $feed_objects[] = $user_feed->_id;
      }

      // add feed details from the main feeds collection
      foreach (
        $mongo->{MONGO_DB_NAME}->feeds->find( [ '_id' => [ '$in' => $feed_objects ] ],
          [
            'sort'       => [ 'title' => 1, 'url' => 1 ],
            'projection' => [
              'url'   => 1,
              'icon'  => 1,
            ]
          ] ) as $general_feed
      ) {
        $feeds[ (string) $general_feed->_id ]->url = $general_feed->url;
        $feeds[ (string) $general_feed->_id ]->icon = $general_feed->icon;
      }

      // load unread/untrained/all messages count for each feed
      $all_count = 0;
      foreach ( $feed_objects as $feed_object ) {
        $_POST['feed'] = (string) $feed_object;
        $filter        = build_filters_and_options()[0];

        // use a real count with the filter set above
        $feeds[ (string) $feed_object ]->count = $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->countDocuments( $filter );
        $all_count += $feeds[ (string) $feed_object ]->count;

        // update the ID parameter, so it's directly readable
        $feeds[ (string) $feed_object ]->id = (string) $feeds[ (string) $feed_object ]->_id;
        unset( $feeds[ (string) $feed_object ]->_id );
      }
    }

    // add number of bookmarks
    $feeds['bookmarks_count'] = $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->countDocuments( [ 'bookmarked' => 1 ] );

    // add all feeds count
    if (isset($all_count)) {
      $feeds['all_count'] = $all_count;
    }

    return $feeds;
  }