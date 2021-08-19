<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['feed'])) {
    send_error($lang['Missing parameter'], $lang['Feed ID empty'], 400, 'validation', ['api' => 'labels_get', 'field' => 'feed']);
  }

  // if we're loading labels for bookmarks or "all feeds" item, we'll need to load them all
  if ($_POST['feed'] != 'bookmarks' && $_POST['feed'] != 'all') {
    try {
      $feed_object = new MongoDB\BSON\ObjectId( (string) $_POST['feed'] );
    } catch ( \Exception $ex ) {
      send_error( $lang['System Error'], $lang['Feed ID not ObjectID'], 400, 'validation', [ 'api'     => 'labels_get',
                                                                                             'feed_id' => $_POST['feed']
      ] );
    }
  }

  $labels = [];
  $filter = [];

  if ($_POST['feed'] != 'bookmarks' && $_POST['feed'] != 'all') {
    $filter['feed'] = $feed_object;
  }

  $cached_feeds = [];
  $records = $mongo->{MONGO_DB_NAME}->{'labels-' . $user->short_id}->find($filter, [ 'sort' => [ 'label' => 1 ], 'projection' => [ 'feed' => 1, 'label' => 1, ] ]);
  foreach ($records as $label) {
    // if we're getting labels for a single feed, we don't need its name to complement them
    if (isset($filter['feed'])) {
      $labels[] = [
        'id' => (string) $label->_id,
        'name' => $label->label,
      ];
    } else {
      // we're getting labels for all feeds, let's add feed name to the label
      if (!isset($cached_feeds[ (string) $label->feed ])) {
        $cached_feeds[ (string) $label->feed ] = $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->findOne([ '_id' => $label->feed ], [ 'projection' => [ 'title' => 1, ] ])->title;
      }

      $labels[] = [
        'id' => (string) $label->_id,
        'name' => $label->label . ' (' . $cached_feeds[ (string) $label->feed ] . ')',
      ];
    }
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( $labels, \JSON_UNESCAPED_UNICODE );