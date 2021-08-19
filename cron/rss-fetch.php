<?php
use JDecool\JsonFeed\Reader\ReaderBuilder;

set_time_limit( 500 );
session_write_close();

$time_start = microtime(true);

require_once "../api/bootstrap.php";

// don't start a new job if the last one is still running and hasn't timed-out yet
if ($mongo->{MONGO_DB_NAME}->jobs->findOne([
  'type' => 'rss-fetch',
  'lambdas' => [
    '$gt' => 0,
  ],
  'expires' => [
    '$gte' => time()
  ],
]) !== null) {
  exit;
}

// add current job into the collection of active jobs
$job = $mongo->{MONGO_DB_NAME}->jobs->insertOne([
  'type' => 'rss-fetch',
  'lambdas' => 1,
  'start' => time(),
  'expires' => time() + (60 * 5), // 5 minutes job expiration
]);

require_once "../SimplePie.compiled.php";

// update all feeds with 10+ subsequent failures
// where last fetch was more than 2 days ago
$mongo->{MONGO_DB_NAME}->feeds->updateMany(
  [
    'subsequent_errors_counter' => [
      '$gte' => 10
    ],
    'last_error_ts' => [
      '$lte' => time() - (60 * 60 * 24 * 2) // now minus 2 days
    ],
  ],
  [
    '$set' => [
      'subsequent_errors_counter' => 0,
      'last_error_ts' => 0,
      'fetch_interval_minutes' => 5, // reset this, so we can start sniffing out this feed's update frequency again
      'subsequent_stable_fetch_intervals' => 0,
    ]
  ]
);

// assemble all feeds with the following parameters:
// -> at least 1 normal/premium user subscribed
// -> next_fetch_ts >= current time
// -> subsequent errors counter less than 10
$find_array = [
  '$and' => [
    [
      '$or' => [
        [
          'normal_subscribers' => [
            '$gt' => 0
          ],
        ],
        [
          'premium_subscribers' => [
            '$gt' => 0
          ]
        ]
      ],
    ],
    [
      '$or' => [
        [
          'next_fetch_ts' => 0,
        ],
        [
          'next_fetch_ts' => [
            '$lte' => time(),
          ]
        ]
      ],
      'subsequent_errors_counter' => [
        '$lt' => 10
      ],
    ],
  ],
];

// count how many IDs do we send out per single SNS event
$records_count = $mongo->{MONGO_DB_NAME}->feeds->countDocuments($find_array);
$per_lambda_count = ceil($records_count / 500);

if ($records_count == 0) {
  $lambdas = 0;
} else {
  // if we don't have enough records for even a single lambda,
  // set the number of lambdas to 1
  if ( $per_lambda_count == 1 ) {
    $lambdas = $records_count;
  } else {
    // let's count how many lambdas do we get
    $lambdas                = 1;
    $current_lambda_records = 0;
    $records_done           = 0;
    while ( $records_done < $records_count ) {
      if ( $current_lambda_records < $per_lambda_count ) {
        $current_lambda_records ++;
      } else {
        $current_lambda_records = 0;
        $lambdas ++;
      }

      $records_done ++;
    }
  }
}

echo 'Would fire up ' . $lambdas . ' SNS event(s), each lambda would process ' . $per_lambda_count . " ID(s)<br><br>\n";

// TODO: make this fire the required number of SNS events
$counter = 0;
$errors_counter = 0;
$exceptions_counter = 0;
foreach ($mongo->{MONGO_DB_NAME}->feeds->find($find_array, [
  'projection' => [
    'title' => 1,
    'url' => 1,
    'fetch_interval_minutes' => 1,
    'last_fetch_ts' => 1,
    'next_fetch_ts' => 1,
    'subsequent_errors_counter' => 1,
    'subsequent_stable_fetch_intervals' => 1,
    'total_fetches' => 1,
    'empty_fetches' => 1,
    'last_non_empty_fetch' => 1,
  ]
]) as $record) {

  // if this feed doesn't have an HTTP or HTTPS prefix, try looking for a working link with one of those prefixes
  // and update it if found
  if ( mb_strtolower( mb_substr($record->url, 0, 7)) != 'http://' && mb_strtolower( mb_substr($record->url, 0, 8)) != 'https://' ) {
    // try HTTPS first
    $file_headers = @get_headers( 'https://' . $record->url );
    if ( $file_headers && strpos($file_headers[0], '404 Not Found') === false ) {
      echo 'changing invalid feed url' . $record->url . ' to ' . 'https://' . $record->url . "<br>\n";
      $mongo->{MONGO_DB_NAME}->feeds->updateOne( [ '_id' => $record->_id ], ['$set' => [ 'url' => 'https://' . $record->url ] ] );
      $record->url = 'https://' . $record->url;
    } else {
      // try HTTP
      $file_headers = @get_headers( 'http://' . $record->url );
      if ( $file_headers && strpos($file_headers[0], '404 Not Found') === false ) {
        echo 'changing invalid feed url' . $record->url . ' to ' . 'http://' . $record->url . "<br>\n";
        $mongo->{MONGO_DB_NAME}->feeds->updateOne( [ '_id' => $record->_id ], ['$set' => [ 'url' => 'http://' . $record->url ] ] );
        $record->url = 'http://' . $record->url;
      }
    }
  }

  $counter++;

  // prepare statistical variables
  $items_inserted = 0;
  $stories_per_month = [];
  $stories_per_day = [];
  $stories_per_hour = [];

  try {
    // fetch the URL via cURL
    $data = fetch_url( $record->url );

    if (is_array($data)) {
      // throwing here would move us to the catch block with error handling
      throw new \Exception( $data[0] );
    }

    // check that this is not a JSON feed
    $json = json_decode( $data );
    if ($json !== null) {
      // we have a JSON feed - it can still be set to false
      if ($json !== false) {
        $builder = new ReaderBuilder();
        $reader = $builder->build();
        $feed = $reader->createFromJson( $data );

        foreach ($feed->getItems() as $item) {
          // try to extract link image
          $link_img = '';

          if ($item->getImage()) {
            $link_img = getImage;
          } else if ($item->getBannerImage()) {
            $link_img = $item->getBannerImage();
          } else if ($item->getContentHtml()) {
            // check if we can find image in the description
            preg_match_all( '/<img src=["\']([^"\']+)["\'][^>]*>/mi', $item->getContentHtml(), $matches, PREG_SET_ORDER, 0 );
            if ( count( $matches ) ) {
              // use the first available image
              $link_img = $matches[0][1];
            }
          }

          $categories = [];
          if ($item->getTags()) {
            foreach ($item->getTags() as $tag) {
              $categories[] = $tag;
            }
          }

          $author = '';
          if ($item->getAuthor()) {
            if (is_string( $item->getAuthor() )) {
              $author = $item->getAuthor();
            } else if (is_array( $item->getAuthor() )) {
              $author = $item->getAuthor()[0];
            }
          }

          // use current timestamp if we won't be able to find a date
          $date = time();
          if ($item->getDateModified()) {
            $date = $item->getDateModified()->getTimestamp();
          } else if ($item->getDatePublished()) {
            $date = $item->getDatePublished()->getTimestamp();
          }

          $description = $item->getSummary();
          $url = ($item->getUrl() ? $item->getUrl() : '#' . $item->getId());

          $insert_value = [
            'title'       => ($item->getTitle() ? $item->getTitle() : $url),
            'description' => $description,
            // URL is optional for a JSON feed, so just use ID as a hash
            'link'        => $url,
            'img'         => $link_img,
            'date'        => (is_int($date) ? $date : strtotime( $date )),
            'fetched'     => time(),
            'feed'        => $record->_id,
            'processed'   => 0, // will be changed to 1 upon being processed and trained for all users,
                                // so this link can be removed from the unprocessed collection
          ];

          if ( $author ) {
            $insert_value['author'] = $author;
          }

          if ( count( $categories ) ) {
            $insert_value['categories'] = $categories;
          }

          try {
            $mongo->{MONGO_DB_NAME}->unprocessed->insertOne( $insert_value );
            $items_inserted++;

            // save statistical information
            if (!isset($stories_per_month[ date('n.Y', $insert_value['date']) ])) {
              $stories_per_month[ date('n.Y', $insert_value['date']) ] = 0;
            }
            $stories_per_month[ date('n.Y', $insert_value['date']) ]++;

            if (!isset($stories_per_day[ date('N.W.Y', $insert_value['date']) ])) {
              $stories_per_day[ date('N.W.Y', $insert_value['date']) ] = 0;
            }
            $stories_per_day[ date('N.W.Y', $insert_value['date']) ]++;

            if (!isset($stories_per_hour[ date('H.z.Y', $insert_value['date']) ])) {
              $stories_per_hour[ date('H.z.Y', $insert_value['date']) ] = 0;
            }
            $stories_per_hour[ date('H.z.Y', $insert_value['date']) ]++;
          } catch ( MongoDB\Driver\Exception\BulkWriteException $ex ) {
            // ignore duplicate link errors, since if we only show untrained records, we don't select the existing trained ones
            // and thus we might be trying to re-insert a trained record into the DB - which the below condition will prevent and ignore
            if ( $ex->getCode() != 11000 ) {
              // TODO: something went wrong while trying to insert the data, log this properly
              //var_dump($ex->getTraceAsString());
              //throw new \Exception( $ex );
              file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-rss-fetch', $ex->getTraceAsString() . "\n", FILE_APPEND );
              $exceptions_counter++;
            }
          }
        }
      }
    } else {
      $feed = get_feed(null, $data);

      if ($feed->error()) {
        // throwing here would move us to the catch block with error handling
        throw new \Exception( $feed->error() );
      }

      // save data into the unprocessed collection
      $items = array_reverse( $feed->get_items() );
      $first_item_stamp = null;
      foreach ($items as $item) {
        // try to extract link image
        $link_img = '';

        // thumbnail found
        if ( $item->get_thumbnail() ) {
          $link_img = $item->get_thumbnail()['url'];
        } else if ( $item->get_enclosures()[0]->thumbnails && count( $item->get_enclosures()[0]->thumbnails ) ) {
          // thumbnail found but not recognized by SimplePie (YouTube)
          $link_img = $item->get_enclosures()[0]->thumbnails[0];
        } else if ( $item->get_enclosures()[0]->link ) {
          // feeds usually provide thumbnails in the link enclosure
          // let's do a super-simple educated guess here
          $image_extensions = array(
            '.jpg',
            '.jpeg',
            '.gif',
            '.png',
            '.bmp',
            '.tif',
            '.tiff'
          );

          if ( in_array( strtolower( strrchr( $item->get_enclosures()[0]->link, '.' ) ), $image_extensions ) ) {
            $link_img = $item->get_enclosures()[0]->link;
          }
        } else {
          // check if we can find image in the description
          preg_match_all( '/<img src=["\']([^"\']+)["\'][^>]*>/mi', $item->get_description(), $matches, PREG_SET_ORDER, 0 );
          if ( count( $matches ) ) {
            // use the first available image
            $link_img = $matches[0][1];
          }
        }

        $categories = [];
        if ( $item->get_categories() ) {
          foreach ( $item->get_categories() as $category ) {
            // check where our title resides
            $title = ( $category->get_label() ? $category->get_label() : $category->get_term() );

            // add category only if a title was actually found
            if ( $title ) {
              $categories[] = $title;
            }
          }
        }

        // only use first author
        $author = '';
        if ( $item->get_authors() ) {
          foreach ( $item->get_authors() as $author ) {
            $name = $author->get_name();
            // if name is empty, the name could contain an e-mail, in which case, author's name goes into e-mail
            if ( ! $name ) {
              $name = $author->get_email();
            }

            // remove entity tags from author names (http://export.arxiv.org/rss/astro-ph has them stored that way)
            $decoded      = html_entity_decode( $name, ENT_QUOTES || ENT_HTML5, "UTF-8" );
            $tagless      = strip_tags( $decoded );
            $author_final = entities_to_unicode( $tagless );

            if ( $author_final ) {
              $author = $author_final;
            }
          }
        }

        $insert_value = [
          'title'       => ($item->get_title() ? untagize( $item->get_title() ) : $item->get_link()),
          'description' => $item->get_description(),
          'link'        => $item->get_link(),
          'img'         => $link_img,
          'date'        => strtotime( $item->get_date() ),
          'fetched'     => time(),
          'feed'        => $record->_id,
          'processed'   => 0, // will be changed to 1 upon being processed and trained for all users,
                              // so this link can be removed from the unprocessed collection
        ];

        $first_item_stamp = $insert_value['date'];

        if ( $author ) {
          $insert_value['author'] = $author;
        }

        if ( count( $categories ) ) {
          $insert_value['categories'] = $categories;
        }

        try {
          $mongo->{MONGO_DB_NAME}->unprocessed->insertOne( $insert_value );
          $items_inserted++;

          // save statistical information
          if (!isset($stories_per_month[ date('n.Y', $insert_value['date']) ])) {
            $stories_per_month[ date('n.Y', $insert_value['date']) ] = 0;
          }
          $stories_per_month[ date('n.Y', $insert_value['date']) ]++;

          if (!isset($stories_per_day[ date('N.W.Y', $insert_value['date']) ])) {
            $stories_per_day[ date('N.W.Y', $insert_value['date']) ] = 0;
          }
          $stories_per_day[ date('N.W.Y', $insert_value['date']) ]++;

          if (!isset($stories_per_hour[ date('H.z.Y', $insert_value['date']) ])) {
            $stories_per_hour[ date('H.z.Y', $insert_value['date']) ] = 0;
          }
          $stories_per_hour[ date('H.z.Y', $insert_value['date']) ]++;
        } catch ( MongoDB\Driver\Exception\BulkWriteException $ex ) {
          // ignore duplicate link errors, since if we only show untrained records, we don't select the existing trained ones
          // and thus we might be trying to re-insert a trained record into the DB - which the below condition will prevent and ignore
          if ( $ex->getCode() != 11000 ) {
            // TODO: something went wrong while trying to insert the data, log this properly
            //var_dump($ex->getTraceAsString());
            //throw new \Exception( $ex );
            file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-rss-fetch', $ex->getTraceAsString() . "\n", FILE_APPEND );
            $exceptions_counter++;
          }
        }
      }
    }

    // reset fetch time interval if we've had previous subsequent errors in this feed
    if ($record->subsequent_errors_counter > 0) {
      echo 'recovery from previous error state - resetting fetch interval for ' . (!empty($record->title) ? $record->title : $record->url) . ' from ' . $record->fetch_interval_minutes . ' to ' . ($record->fetch_interval_minutes - ( $record->subsequent_errors_counter * 5))."<br>\n";
      $mongo->{MONGO_DB_NAME}->feeds->updateOne( [ '_id' => $record->_id ], [
        '$set' => [
          'fetch_interval_minutes' => ($record->fetch_interval_minutes - ( $record->subsequent_errors_counter * 5)),
          'subsequent_errors_counter' => 0,
        ]
      ] );

      // also update this record's value, otherwise we'd rewrite this recovered value by one
      // from the fetch time shortening section below
      $record->fetch_interval_minutes = ($record->fetch_interval_minutes - ( $record->subsequent_errors_counter * 5));
    }

    // if we did not receive any items, reset the subsequent_stable_fetch_intervals field
    // and make the next fetch timestamp and the fetch interval itself a little longer
    if (!$items_inserted) {
      $set_array = [
        'last_fetch_ts' => time(),
      ];

      $inc_array = [];
      $interval_increase = 0;
      $non_increase_cause = '';

      // leave a grace period of 20 hours for feeds that already have a stable fetch interval,
      // as these could potentially be daily feeds (such as local auctions or a trading RSS channel)
      // which lay dormant during the night
      // ... for this reason, we won't increment total fetches, neither empty fetches here
      if ($record->subsequent_stable_fetch_intervals > 10 && ( (time() < ($record->last_non_empty_fetch + (60 * 60 * 20)) ) ) ) {
        $set_array['next_fetch_ts'] = (time() + ( $record->fetch_interval_minutes * 60));
        $non_increase_cause = ' due to a healthy feed in sleep period';
      }
      // don't modify the fetch interval if this feed was known to work
      // for some time, as this could be a temporary flaw in their systems
      // ... if such a feed is found to be with 10+ errors, it will automatically be
      //     excluded from fetching for 2 days and then the interval will reset
      // ... we can still increase this interval if empty fetches ratio is too high (let's start with higher than 24%)
      else if ($record->subsequent_stable_fetch_intervals > 10 && ( (($record->empty_fetches / $record->total_fetches) * 100) < 24) ) {
        $inc_array['total_fetches'] = 1;
        $inc_array['empty_fetches'] = 1;
        $set_array['next_fetch_ts'] = (time() + ( $record->fetch_interval_minutes * 60));
        $non_increase_cause = ' due to a healthy feed with a possible temporary glitch';
      } else {
        // this feed doesn't have a stable fetch interval yet - update timings
        // but don't go above 10 days in the interval
        if ( ($record->fetch_interval_minutes + 5) < (60 * 24 * 10) ) {
          // don't go above 1 hour for feeds where we didn't receive any articles yet,
          // so we don't create a 10 days gap for a feed which may start updating every hour during
          // the day but may lay dormant during the night
          if (
            ($record->empty_fetches != $record->total_fetches) // extend if we have at least some articles
            ||
            ($record->empty_fetches > 32)                      // or if we've not seen anything at all for the past 32 fetches
                                                               // which would make for 36 hours of empty initial results
            ||
            ($record->fetch_interval_minutes < 60)             // or if none exist but the interval is not yet at 1 hour
          ) {
            $set_array['fetch_interval_minutes'] = ( $record->fetch_interval_minutes + 5 );
            $set_array['next_fetch_ts']          = ( time() + ( ( $record->fetch_interval_minutes + 5 ) * 60 ) );
            $interval_increase                   = 5;
          }
        }

        // if we've not adjusted the fetch interval above, simply set the next fetch TS
        if (!isset($set_array['next_fetch_ts'])) {
          $set_array['next_fetch_ts'] = ( time() + ( $record->fetch_interval_minutes * 60 ) );
        }

        $inc_array['total_fetches'] = 1;
        $inc_array['empty_fetches'] = 1;
        $set_array['subsequent_stable_fetch_intervals'] = 0;
      }

      $data = [];

      if (count($set_array)) {
        $data['$set'] = $set_array;
      };

      if (count($inc_array)) {
        $data['$inc'] = $inc_array;
      }

      $mongo->{MONGO_DB_NAME}->feeds->updateOne([ '_id' => $record->_id ], $data);

      echo 'Fetched "' . (!empty($record->title) ? $record->title : $record->url) . '" ('. $record->url . ') with no new records. ';
      if ($interval_increase) {
        echo 'Interval increased from ' . $record->fetch_interval_minutes . ' to ' . ( $record->fetch_interval_minutes + $interval_increase ) . " minutes.<br>\n";
      } else {
        echo 'Interval holding at ' . $record->fetch_interval_minutes . " minutes $non_increase_cause.<br>\n";
      }
    } else {
      // new items found, update stats
      echo 'Fetched "' . (!empty($record->title) ? $record->title : $record->url) . '" ('. $record->url . ') with ' . $items_inserted . ' new records. Interval remains stable at ' . $record->fetch_interval_minutes . ' minutes for ' . ($record->subsequent_stable_fetch_intervals + 1) . " fetches.<br>\n";

      // build the increment array, as we need to increment values for each of the stories_per_* arrays
      $inc_array = [
        'subsequent_stable_fetch_intervals' => 1,
        'total_fetches' => 1,
      ];

      foreach ($stories_per_month as $key => $value) {
        $inc_array[ 'stories_per_month.' . $key ] = $value;
      }

      foreach ($stories_per_day as $key => $value) {
        $inc_array[ 'stories_per_day.' . $key ] = $value;
      }

      foreach ($stories_per_hour as $key => $value) {
        $inc_array[ 'stories_per_hour.' . $key ] = $value;
      }

      $set_array = [
        'last_fetch_ts' => time(),
        'next_fetch_ts' => (time() + ( $record->fetch_interval_minutes * 60)),
        'last_non_empty_fetch' => time(),
      ];

      // if this feed was dormant for a long time, let's reset its timers, so we can re-train
      // our fetch timers on this feed from scratch
      if ($record->subsequent_stable_fetch_intervals > 10 && ( (($record->empty_fetches / $record->total_fetches) * 100) >= 24) ) {
        $set_array['fetch_interval_minutes'] = 5;
        $set_array['subsequent_stable_fetch_intervals'] = 0;
        unset($inc_array['subsequent_stable_fetch_intervals']);
      }

      // if the timestamp of the first added item is timed long before our actual fetch time,
      // and it's after the actual last fetch time, shorten this feed's fetch time to match the difference
      if ($first_item_stamp >= $record->last_non_empty_fetch && $first_item_stamp < $record->next_fetch_ts) {
        // check if the difference when divided by 5 (minutes) gives us any space to adjust our timer
        $difference = floor((($record->next_fetch_ts - $first_item_stamp) / 60) / 5);
        if ($difference >= 1 && (($record->fetch_interval_minutes - ($difference * 5)) > 0)) {
          echo 'Shortening fetch time by ' . ($difference * 5) . ' minutes from ' . $record->fetch_interval_minutes .' to ' . ($record->fetch_interval_minutes - ($difference * 5)) . ' due to first item having a very early timestamp (item: ' . date('j.m.Y H:i:s', $first_item_stamp) . ', current fetch time: ' . date('j.m.Y H:i:s', $record->next_fetch_ts) . ")<br>\n";
          $set_array['fetch_interval_minutes'] = ($record->fetch_interval_minutes - ($difference * 5));
          $set_array['next_fetch_ts'] = (time() + ( $set_array['fetch_interval_minutes'] * 60));
        }
      }

      $data = [];

      if (count($set_array)) {
        $data['$set'] = $set_array;
      };

      if (count($inc_array)) {
        $data['$inc'] = $inc_array;
      }

      $mongo->{MONGO_DB_NAME}->feeds->updateOne([ '_id' => $record->_id ], $data);
    }
  } catch ( MongoDB\Driver\Exception\BulkWriteException $exception ) {
    // ignore duplicate link errors - we only want a single instance of each article
    // ... this can happen when a feed is fetched with data that's already been fetched before
    //     and this error handling frees us from managing last fetch times ourselves
    if ( $exception->getCode() != 11000 ) {
      // TODO: a DB error occured, log this properly
      //var_dump($exception->getTraceAsString());
      //throw $exception;
      file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-rss-fetch', $exception->getTraceAsString() . "\n", FILE_APPEND );
      $exceptions_counter++;
    } else {
      // reset fetch time interval if we've had previous subsequent errors in this feed
      if ($record->subsequent_errors_counter > 0) {
        echo 'recovery from previous error state - resetting fetch interval for ' . (!empty($record->title) ? $record->title : $record->url) . ' from ' . $record->fetch_interval_minutes . ' to ' . ($record->fetch_interval_minutes - ( $record->subsequent_errors_counter * 5))."<br>\n";
        $mongo->{MONGO_DB_NAME}->feeds->updateOne( [ '_id' => $record->_id ], [
          '$set' => [
            'fetch_interval_minutes' => ($record->fetch_interval_minutes - ( $record->subsequent_errors_counter * 5)),
            'subsequent_errors_counter' => 0,
          ]
        ] );
      }
    }
  } catch (\Exception $exception) {
    // if there was an error getting/processing the feed,
    // record this into the DB
    $errors_counter++;
    echo '(' . $exception->getCode() . ') Error (nr. ' . ($record->subsequent_errors_counter + 1) . ') processing "' . (!empty($record->title) ? $record->title : $record->url) . '" with message: ' . $exception->getMessage() . ' (file ' . $exception->getFile() . ', line ' . $exception->getLine() .'). Interval increased from ' . $record->fetch_interval_minutes . ' to ' . ($record->fetch_interval_minutes + 5) ." minutes.<br>\n";

    $set_array = [
      'last_fetch_ts' => time(),
      'last_error_ts' => time(),
      'last_error' => '(' . $exception->getCode() . ') ' . $exception->getMessage(),
    ];

    // don't go above 10 days with fetch interval
    if ( ($record->fetch_interval_minutes + 5) < (60 * 24 * 10) ) {
      $set_array['fetch_interval_minutes'] = ($record->fetch_interval_minutes + 5);
      $set_array['next_fetch_ts'] = (time() + ( ($record->fetch_interval_minutes + 5) * 60));
    } else {
      $set_array['next_fetch_ts'] = (time() + ( $record->fetch_interval_minutes * 60));
    }

    $mongo->{MONGO_DB_NAME}->feeds->updateOne([ '_id' => $record->_id ], [
      '$set' => $set_array,
      '$inc' => [
        'total_errors' => 1,
        'total_fetches' => 1,
        'subsequent_errors_counter' => 1
      ],
    ]);
  }
}

$time_end = microtime(true);
echo '<br><br>[' . date('j.m.Y, H:i:s') . '] ' . (round($time_end - $time_start,3) * 1000) . 'ms for ' . $counter .' feeds<br><br>';

// insert data into log
$mongo->{MONGO_DB_NAME}->logs->insertOne([
  'type' => 'rss-fetch',
  'start' => $time_start,
  'end' => $time_end,
  'duration' => (round($time_end - $time_start,3) * 1000),
  'feeds_count' => $counter,
  'errors_count' => $errors_counter,
  'exceptions_count' => $exceptions_counter,
]);

// mark job as finished
$mongo->{MONGO_DB_NAME}->jobs->updateOne([ '_id' => $job->getInsertedId() ], [ '$set' => [ 'end' => time(), 'lambdas' => 0 ] ]);

?>
<script>
  // reload every 5 minutes 2 seconds
  var reloadTime = (((60 * 5) + 2) * 1000);
  setTimeout(function() {
    document.location.reload();
  }, reloadTime);

  document.write('Reload in: <span id="reloadTime">' + (reloadTime / 1000) + '</span>s');

  setInterval(function() {
    var currentTime = parseInt(document.getElementById('reloadTime').innerHTML);

    if (currentTime > 0) {
      document.getElementById('reloadTime').innerHTML = currentTime - 1;
    }
  }, 1000);
</script>