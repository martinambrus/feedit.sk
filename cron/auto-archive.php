<?php
set_time_limit( 600 );
session_write_close();

$time_start = microtime(true);

require_once "../api/bootstrap.php";

// don't start a new job if the last one is still running and hasn't timed-out yet
if ($mongo->bayesian->jobs->findOne([
    'type' => 'auto-archive',
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
$job = $mongo->bayesian->jobs->insertOne([
  'type' => 'auto-archive',
  'lambdas' => 1,
  'start' => time(),
  'expires' => time() + (60 * 5), // 5 minutes job expiration
]);

$user_counter = 0;
$exceptions_counter = 0;

// get active users
// TODO: this would just fire up events with up to 100 account IDs to train links for to SNS for worker lambdas to process
foreach ($mongo->bayesian->accounts->find([ 'active' => 1 ], [
  'limit' => 100,
  'sort' => [ 'feed' => 1 ],
  'projection' => [
    'feeds' => 1,
    'short_id' => 1,
  ]
]) as $user) {
  $user_counter++;

  // user has not yet subscribed to any feeds
  if (empty($user->feeds)) {
    continue;
  }

  $archival_user_first_step_done = false;

  // iterate over feeds this user is subscribed to
  // get all unprocessed items ordered by feed
  foreach ($user->feeds as $feed_object) {
    try {
      // the first step is feed-agnostic for each user, so it only needs to be done once
      if (!$archival_user_first_step_done) {
        // 1) mark all negatively scored articles older than 24 hours
        //    or articles with a score below 0 older than 12 hours
        //    or articles older than 3 months
        //    as archived, unset their detailed scoring data and calculate their tiers
        $mongo->bayesian->{'training-' . $user->short_id}->updateMany(
        [
          '$or' => [
            [
              // negatively trained items older than 24 hours
              'trained' => 1,
              'bookmarked' => 0,
              'rated' => 0,
              'fetched' => [
                '$lt' => ( time() - (60 * 60 * 24) ),
              ]
            ],
            [
              // items with score <0 older than 12 hours
              'trained' => 0,
              'bookmarked' => 0,
              'score' => [
                '$lt' => 0,
              ],
              'fetched' => [
                '$lt' => ( time() - (60 * 60 * 12) ),
              ]
            ],
            [
              // un-bookmarked articles older than 1 month
              'bookmarked' => 0,
              'fetched' => [
                '$lt' => ( time() - (60 * 60 * 24 * 31) ),
              ]
            ],
          ],
        ],
        [
          [
            '$set' => [
              'archived' => 1,
              'tier' => [
                '$switch' => [
                  'branches' => [
                    [
                      'case' => [
                        '$lte' => [
                          '$interest_average_percent_total',
                          0
                        ]
                      ],
                      'then' => 1,
                    ],
                    [
                      'case' => [
                        '$and' => [
                          [
                            '$gt' => [
                              '$interest_average_percent_total',
                              0
                            ],
                          ],
                          [
                            '$lt' => [
                              '$interest_average_percent_total',
                              10
                            ]
                          ]
                        ]
                      ],
                      'then' => 2,
                    ],
                    [
                      'case' => [
                        '$and' => [
                          [
                            '$gte' => [
                              '$interest_average_percent_total',
                              10
                            ],
                          ],
                          [
                            '$lt' => [
                              '$interest_average_percent_total',
                              30
                            ]
                          ]
                        ]
                      ],
                      'then' => 3,
                    ],
                    [
                      'case' => [
                        '$and' => [
                          [
                            '$gte' => [
                              '$interest_average_percent_total',
                              30
                            ],
                          ],
                          [
                            '$lt' => [
                              '$interest_average_percent_total',
                              50
                            ]
                          ]
                        ]
                      ],
                      'then' => 4,
                    ],
                    [
                      'case' => [
                        '$gte' => [
                          '$interest_average_percent_total',
                          50
                        ]
                      ],
                      'then' => 5,
                    ],
                  ]
                ],
              ],
            ]
          ]
        ]);

        $mongo->bayesian->{'training-' . $user->short_id}->updateMany(
          [
            'archived' => 1,
            'words' => [ '$exists' => true ],
          ],
          [
            '$unset' => [
              'bookmarked' => 1,
              'words' => 1,
              'words_counter' => 1,
              'author' => 1,
              'author_interest_average_count' => 1,
              'author_interest_average_percent' => 1,
              'categories_interest_average_percent' => 1,
              'categories_interest_count' => 1,
              'categories_interest_total_percent' => 1,
              'score_increment_from_ngrams' => 1,
              'score_increment_from_ngrams_percent' => 1,
              'words_interest_average_percent' => 1,
              'words_interest_count' => 1,
              'words_interest_total_percent' => 1,
              'words_rated_above_50_percent' => 1,
              'zero_rated_scored_words_percentage' => 1,
              'zero_scored_words_rated' => 1,
              'ngrams' => 1,
            ]
          ]);

        $archival_user_first_step_done = true;
      }

      // 2) if user has more than 1000 articles unread in this feed,
      //    make all articles beyond the first 1000 read and archived
      if ( $mongo->bayesian->{'training-' . $user->short_id}->countDocuments([ 'feed' => $feed_object, 'read' => 0, 'bookmarked' => 0, 'archived' => [ '$ne' => 1 ] ]) > 1000 ) {
        $last_unread_id = $mongo->bayesian->{'training-' . $user->short_id}->findOne([ 'feed' => $feed_object ], [
          'sort' => [ '_id' => -1 ],
          'skip' => 1000,
          'limit' => 1,
          'projection' => [ '_id' => 1 ],
        ])->_id;

        $mongo->bayesian->{'training-' . $user->short_id}->updateMany([ 'feed' => $feed_object, 'bookmarked' => 0, '_id' => [ '$lte' => $last_unread_id ] ],[
          '$set' => [
            'read' => 1,
            'archived' => 1,
            'tier' => 1,
          ],
          '$unset' => [
            'bookmarked' => 1,
            'words' => 1,
            'words_counter' => 1,
            'author' => 1,
            'author_interest_average_count' => 1,
            'author_interest_average_percent' => 1,
            'categories_interest_average_percent' => 1,
            'categories_interest_count' => 1,
            'categories_interest_total_percent' => 1,
            'score_increment_from_ngrams' => 1,
            'score_increment_from_ngrams_percent' => 1,
            'words_interest_average_percent' => 1,
            'words_interest_count' => 1,
            'words_interest_total_percent' => 1,
            'words_rated_above_50_percent' => 1,
            'zero_rated_scored_words_percentage' => 1,
            'zero_scored_words_rated' => 1,
            'ngrams' => 1,
          ],
        ]);
      }
    } catch ( \Exception $ex ) {
      // TODO: something went wrong during program execution, log this properly
      //throw new \Exception( $ex );
      file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-auto-archive', $ex->getTraceAsString() . "\n", FILE_APPEND );
      $exceptions_counter++;
    }
  }
}

$time_end = microtime(true);
echo '<br><br>[' . date('j.m.Y, H:i:s') . '] ' . (round($time_end - $time_start,3) * 1000) . 'ms for ' . $user_counter . ' users\' feeds cleaned up<br><br>';

// insert data into log
$mongo->bayesian->logs->insertOne([
  'type' => 'auto-archive',
  'start' => $time_start,
  'end' => $time_end,
  'duration' => (round($time_end - $time_start,3) * 1000),
  'users_count' => $user_counter,
  'exceptions_count' => $exceptions_counter,
]);

// mark job as finished
$mongo->bayesian->jobs->updateOne([ '_id' => $job->getInsertedId() ], [ '$set' => [ 'end' => time(), 'lambdas' => 0 ] ]);
?>
<script>
  // reload every 6 hours
  var reloadTime = (60000 * 60 * 6);
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
