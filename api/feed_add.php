<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['feed_url'])) {
    send_error($lang['Missing parameter'], $lang['Feeds Field Empty'], 400, 'validation', ['api' => 'feed_add', 'field' => 'feeds']);
  }

  if (empty($_POST['feed_lang'])) {
    send_error($lang['Missing parameter'], $lang['Lang Field Empty'], 400, 'validation', ['api' => 'feed_add', 'field' => 'feed_lang']);
  }

  if (!isset($_POST['manual_priorities'])) {
    send_error($lang['Missing parameter'], $lang['Manual Priorities Field Empty'], 400, 'validation', ['api' => 'feed_add', 'field' => 'manual_priorities']);
  }

  if (empty($_POST['allow_duplicates'])) {
    send_error($lang['Missing parameter'], $lang['Allow Duplicates Setting Empty'], 400, 'validation', ['api' => 'feed_edit', 'field' => 'allow_duplicates']);
  }

  // map names priorities to their real values
  if (isset($_POST['priorities']) && is_array($_POST['priorities']) && count($_POST['priorities'])) {
    $priorities = [];

    foreach ($_POST['priorities'] as $priority) {
      switch ($priority) {
        case $lang['words'] : $priorities[] = 'words'; break;
        case $lang['numbers'] : $priorities[] = 'number'; break;
        case $lang['measurement units'] : $priorities[] = 'measurement_unit'; break;
        default : throw new \Exception('Unrecognized manual priority: ' . $priority);
      }
    }
  }

  // add feeds to the global user object
  $user_feeds = $mongo->{MONGO_DB_NAME}->accounts->findOne( [ '_id' => $user->_id ], [ 'projection' => [ 'feeds' => 1 ] ] );
  $user->feeds = (isset($user_feeds->feeds) ? $user_feeds->feeds : []);

  $feeds_added = [];
  $added_feeds_ids = [];

  // if we've not been given any feeds (i.e. the user clicked Confirm button before we could load them
  // or they did not tick any of them), just use the user's value
  if (empty($_POST['feeds']) || !count($_POST['feeds'])) {
    $_POST['feeds'] = [ [ 'url' => $_POST['feed_url'], 'title' => preg_replace('/https?:\/\/(www\.)?/mi', '', $_POST['feed_url']) ] ];
  }

  // add all of the feeds into the global feeds DB
  foreach ($_POST['feeds'] as $feed) {
    // check if we can find this feed home URL's favicon
    // ... do this here, as if we'd done that after the initial insert, our CRON job could pick up an incomplete
    //     DB record and fail while we wait for the get_headers() response
    preg_match('/https?:\/\/[^\/]+/m', (string) $feed['url'], $matches);

    if ($matches) {
      $file_headers = @get_headers( $matches[0] . '/favicon.ico' );
      if ( ! $file_headers || strpos($file_headers[0], '404 Not Found') !== false ) {
        // no favicon for this website, use ours
        $icon = 'img/logo114.png';
      } else {
        $icon = $matches[0] . '/favicon.ico';
      }
    } else {
      // use our own icon
      $icon = 'img/logo114.png';
    }

    // check if we don't have this feed in the DB already, in which case increase its subscribers count
    $feed_data = $mongo->{MONGO_DB_NAME}->feeds->findOneAndUpdate( ['url' => (string) $feed['url']], [
      '$set' => [
        'title' => (string) $feed['title'],
        'url'   => (string) $feed['url'],
      ],

    ],
    [
      'projection' => [
        'title' => 1,
        'url' => 1,
        'allow_duplicates' => 1,
      ],
      'upsert'         => true,
    ]);

    // the feed did not exist in the database yet, include its base structure as well
    if ( $feed_data === null ) {
      $feed_data = $mongo->{MONGO_DB_NAME}->feeds->findOneAndUpdate( ['url' => (string) $feed['url']], [
        '$set' => [
          'icon' => $icon,
          'last_fetch_ts' => 0,
          'fetch_interval_minutes' => 5,
          'stories_per_month' =>  new \stdClass(),
          'stories_per_day' => new \stdClass(),
          'stories_per_hour' => new \stdClass(),
          'last_error' => '',
          'total_errors' => 0,
          'total_fetches' => 0,
          'last_error_ts' => 0,
          'subsequent_errors_counter' => 0,
          'next_fetch_ts' => 0,
          'subsequent_stable_fetch_intervals' => 0,
          'empty_fetches' => 0,
          'last_non_empty_fetch' => 0,
          'premium_subscribers' => 0,
          'normal_subscribers' => 0,
        ],
      ],
      [
        'projection' => [
          'title' => 1,
          'url' => 1,
        ],
        'upsert'         => true,
        'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
      ]);
    }

    // add this feed into this user's feeds collection
    $local_data = [
      '_id' => $feed_data->_id,
      'title' => (string) $feed['title'],
      'lang' => (string) $_POST['feed_lang'],
      'allow_duplicates' => (isset($_POST['allow_duplicates']) && $_POST['allow_duplicates'] == 'true' ? 1 : 0),
    ];

    if (isset($priorities)) {
      $local_data['scoring_priority'] = $priorities;
    }

    $existed = $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->findOneAndUpdate( [ '_id' => $feed_data->_id ], [
      '$set' => $local_data
    ],
    [
      'projection' => [
        '_id' => 1,
      ],
      'upsert' => true,
    ]);

    if (!$existed) {
      // increase subscribed users for this feed
      $mongo->{MONGO_DB_NAME}->feeds->updateOne( ['_id' => $feed_data->_id], [
        '$inc' => [
          // TODO: when premium is ready, update this
          'premium_subscribers' => 0,
          'normal_subscribers' => 1,
        ]
      ]);

      // add this feed to user's own feeds in the account collection
      $user->feeds[] = $feed_data->_id;

      $feeds_added[] = [
        'id' => (string) $feed_data->_id,
        'title' => $feed_data->title,
        'url' => $feed_data->url,
        'lang' => $_POST['feed_lang'],
        'icon' => $icon,
        'count' => 0,
        'allow_duplicates' => $local_data['allow_duplicates'],
      ];

      $added_feeds_ids[] = $feed_data->_id;
    }
  }

  // update this user's data with new feeds and also unmark first 100 processed articles in the unprocessed collection
  // as such, so we can immediately fetch that feed's articles for this user
  if (count($added_feeds_ids)) {
    $mongo->{MONGO_DB_NAME}->accounts->updateOne( [ '_id' => $user->_id ], [ '$set' => [ 'feeds' => $user->feeds ] ] );
    foreach ($added_feeds_ids as $added_feeds_id) {
      $last_id = $mongo->{MONGO_DB_NAME}->unprocessed->findOne([ 'feed' =>  $added_feeds_id ], [ 'sort' => [ '_id' => -1 ], 'skip' => 99, 'limit' => 1, 'projection' => [ '_id' => 1 ] ]);
      if ($last_id) {
        $last_id = $last_id->_id;
        $mongo->{MONGO_DB_NAME}->unprocessed->updateMany( [ 'feed' => $added_feeds_id, '_id' => [ '$gte' => $last_id ] ], [ '$set' => [ 'processed' => 0 ] ] );
      }
    }
    // TODO: fire up instant fetch event once we have that in place in production
  }

  // return the inserted feeds values for adding them onto the page
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( $feeds_added, \JSON_UNESCAPED_UNICODE );