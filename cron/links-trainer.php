<?php
set_time_limit( 600 );
session_write_close();

$time_start = microtime(true);

require_once "../api/bootstrap.php";

// don't start a new job if the last one is still running and hasn't timed-out yet
if ($mongo->bayesian->jobs->findOne([
    'type' => 'links-trainer',
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
  'type' => 'links-trainer',
  'lambdas' => 1,
  'start' => time(),
  'expires' => time() + (60 * 14), // 14 minutes job expiration
]);

require_once "../functions/functions-words.php";
require_once "../functions/functions-score-global.php";
require_once "../functions/functions-training.php";

// load all active users from our database and start training unprocessed links from their feeds
$user_counter = 0;
$link_counter = 0;
$exceptions_counter = 0;
$duplications_counter = 0;

// caches used in calculate_score() method, so we can clear them for the next feed
$scored_words = [];
$scored_ngrams = [];
$cached_authors = [];
$cached_categories = [];

// the following variable will serve to empty our caches from previous feed
// when we switch to the next one, so we don't store all caches at once
$last_feed_used = '';

// holds IDs of processed links, so they can all be marked as such at the end
$processed_ids = [];

// holds IDs of feeds for which we've executed the archival procedure,
// so we don't try to archive items from the same feed multiple times
$archival_done_feed_ids = [];

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

  // iterate over feeds this user is subscribed to
  // get all unprocessed items ordered by feed
  foreach ($user->feeds as $feed_object) {
    try {
      // cache this feed's scoring data, if needed
      if ($last_feed_used != (string) $feed_object) {
        // update last feed ID
        $last_feed_used = (string) $feed_object;

        // reset scored words and n-grams caches
        $scored_words = [];
        $scored_ngrams = [];
        $cached_authors = [];
        $cached_categories = [];

        // reset cache and load all scored authors for this user and this feed
        $scored_authors = [];
        foreach ( $mongo->bayesian->{'authors-' . $user->short_id}->find( [
          'feed' => $feed_object,
          'ignored'    => [ '$ne' => 1 ],
          'weightings' => [ '$gt' => 0 ],
        ] ) as $author ) {
          $scored_authors[ $author->author ] = $author;
        }

        // reset cache and load all scored categories for this user and this feed
        $scored_categories = [];
        foreach ( $mongo->bayesian->{'categories-' . $user->short_id}->find( [
          'feed' => $feed_object,
          'ignored'    => [ '$ne' => 1 ],
          'weightings' => [ '$gt' => 0 ],
        ] ) as $category ) {
          $scored_categories[ $category->category ] = $category;
        }

        $feed_data = load_feed_score_adjustments( $feed_object );
      }

      // go through all the unprocessed links for this feed,
      // insert the link into this user's training collection,
      // train them and put them into the processed collection at the end,
      foreach ( $mongo->bayesian->unprocessed->find( [ 'feed' => $feed_object, 'processed' => 0 ] ) as $link ) {
        try {
          // store this item's ID, so it can be marked processed at the end
          $processed_ids[ (string) $link->_id ] = $link->_id;

          // check for duplicates - if not prevented by feed setting
          if (empty($feed_data->allow_duplicates)) {
            $true_dupe  = false;
            $dupe_type = '';
            $dupe_check = $mongo->bayesian->{'training-' . $user->short_id}->findOne( [
              '$or' => [
                [ 'link' => $link->link ],
                [ 'title' => $link->title ],
              ],
            ],
            [
              'projection' => [ '_id' => 1, 'link' => 1, ]
            ]);

            // if there is a trained item with this title for this user, check the description and image as well
            if ( $dupe_check ) {
              // if this is a duplicate link, mark it as a true duplicate and bail out - no further checks needed
              if ($dupe_check->link == $link->link) {
                $true_dupe = true;
                $dupe_type = 'link';
              } else {
                // get all items with this title to determine whether our current article is a duplicate of either of them
                foreach ($mongo->bayesian->{'training-' . $user->short_id}->find( [ 'title' => $link->title ], [ ' projection' => [ '_id' => 1, 'feed' => 1, ] ] ) as $potential_dupe ) {
                  // load data from the processed collection - either directly by the ID
                  // or indirectly by querying for the same title as we have on this item
                  $dupe_check = $mongo->bayesian->processed->findOne( [
                    '$or' => [
                      [ '_id' => $potential_dupe->_id ],
                      [
                        'feed' => $potential_dupe->feed,
                        'title' => $link->title,
                      ]
                    ]
                  ], [
                    'projection' => [
                      'description' => 1,
                      'img'         => 1,
                    ]
                  ] );

                  // double-check that we have this item in the processed collection
                  if ( $dupe_check ) {
                    // check description - only if we have 80+ characters
                    if ( mb_strlen( $link->description ) >= 80 || ( $link->img && $dupe_check->img ) ) {
                      if ( mb_strlen( $link->description ) >= 80 && ( mb_substr( $link->description, 0, 80 ) == mb_substr( $dupe_check->description, 0, 80 ) ) ) {
                        // descriptions match, mark as duplicate
                        $true_dupe = true;
                        $dupe_type = 'title';
                        break;
                      } else if ( $link->img == $dupe_check->img ) {
                        // we don't have description but the image is the same,
                        // mark as duplicate
                        $true_dupe = true;
                        $dupe_type = 'img without desc';
                        break;
                      }
                    }
                  }
                }
              }
            }

            // we have a duplicate item, continue with the next one
            if ( $true_dupe ) {
              echo 'duplication DETECTED (type: ' . $dupe_type . ') for item ' . $link->_id . ' (' . $link->title .', imgs: ' . (isset($link->img) ? $link->img : 'none') . ' .. ' . (isset($dupe_check->img) ? $dupe_check->img : 'none') . '), feed ' . $feed_object . '<br>';
              continue;
            }
          }

          // try to insert data into this collection and if a duplicate is found,
          // simply bail out (because we might not be checking duplicates as by a per-feed option)

          try {
            // prepare the basic training array to be inserted into this user's training collection
            $training_array = [
              '_id'        => $link->_id,
              'title'      => $link->title,
              'fetched'    => $link->fetched,
              'date'       => $link->date,
              'feed'       => $link->feed,
              'link'       => $link->link,
              'trained'    => 0,
              'read'       => 0,
              'bookmarked' => 0,
            ];

            if ( ! empty( $link->author ) ) {
              $training_array['author'] = $link->author;
            }

            if ( ! empty( $link->categories ) ) {
              $training_array['categories'] = $link->categories;
            }

            // DB insert into user's training collection
            $mongo->bayesian->{'training-' . $user->short_id}->insertOne( $training_array );
          } catch ( MongoDB\Driver\Exception\BulkWriteException $ex ) {
            if ( $ex->getCode() != 11000 ) {
              // TODO: something went wrong while trying to insert the data, log this properly
              // throw new \Exception( $ex );
              file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-train', $ex->getTraceAsString() . "\n", FILE_APPEND );
              $exceptions_counter++;
            } else {
              // duplicate found, contine with next record
              echo 'duplication (training collection) for item ' . $link->_id . ' (' . $link->title .'), feed ' . $feed_object . ' .. ' . $ex->getMessage() . "<br>\n";
              continue;
            }
          }

          // calculate score for this link for this user, while updating the item in the training collection
          $words                                               = parse_words( $link->title, $feed_data->lang );
          $score                                               = calculate_score( $feed_object, $user->short_id, $words, $link->_id, false, true );
          $words_count                                         = count( $score['words_details'] );
          $update_array                                        = [];
          $word_ids                                            = []; // used below for label determination
          $link_labels                                         = [];
          $update_array['score']                               = $score['score']; // the actual final score for this link
          $update_array['score_increment_from_ngrams']         = $score['score_increment_from_ngrams'];
          $update_array['score_increment_from_ngrams_percent'] = ( $score['score_increment_from_ngrams'] ? ( ( $score['score_increment_from_ngrams'] / $score['score'] ) * 100 ) : 0 );
          $update_array['zero_scored_words']                   = $score['zero_scored_words'];
          $update_array['zero_scored_words_rated']             = $score['zero_scored_words_rated'];
          $update_array['zero_rated_scored_words_percentage']  = ( $words_count ? ( ( $update_array['zero_scored_words_rated'] / $words_count ) * 100 ) : 0 );
          $average_calculation_items_counted                   = 0; // number of all items (words, authors, categories, adjustment phrases)
                                                                    // that we have a valid average calculated for, i.e. an average that would not be
                                                                    // solely calculated from non-rated words/authors/categories

          // calculate average user interest for words in percent
          $update_array['words_interest_average_percent'] = 0;
          $update_array['words_interest_count']           = 0;
          $update_array['words_interest_total_percent']   = 0;
          $update_array['words_rated_above_50_percent']   = 0;
          $processed_words                                = 0; // contains number of words that were actually rated at least once,
                                                               // so our percentage average gets calculated correctly

          // if score of this item is below 2900, remove it from the DB completely
          if ( $update_array['score'] < -2900 ) {
            $mongo->bayesian->{'training-' . $user->short_id}->deleteOne( [ '_id' => $link->_id ] );
            continue;
          }

          // calculate interest percentages for our scored words
          require "training-words.php";

          // calculate interest percentages for author of this link
          require "training-author.php";

          // calculate interest percentages for categories of this link
          require "training-categories.php";

          // add scoring for custom phrases manually set by the user
          require "training-phrases.php";

          // update label predictions for this link
          require "training-labels.php";

          // get total average of all interest percentages, so we can filter by them (i.e. filter by tiers)
          if ( $average_calculation_items_counted ) {
            $update_array['interest_average_percent_total'] =
              ( ( $update_array['categories_interest_average_percent'] +
                  $update_array['author_interest_average_percent'] +
                  $update_array['words_interest_average_percent'] +
                  ( (isset( $update_array['score_increment_from_adjustments'] ) && $update_array['score'] > 0) ? ( ( $update_array['score_increment_from_adjustments'] / $update_array['score'] ) * 100 ) : 0 ) ) / $average_calculation_items_counted );
          } else {
            $update_array['interest_average_percent_total'] = 0;
          }

          // calculate conformed score
          if ($update_array['interest_average_percent_total'] < 0 && $update_array['score'] < 0) {
            $update_array['score_conformed'] = ( abs( $update_array['interest_average_percent_total'] ) * $update_array['score'] );
          } else {
            $update_array['score_conformed'] = ( $update_array['interest_average_percent_total'] * $update_array['score'] );
          }

          $mongo->bayesian->{'training-' . $user->short_id}->updateOne( [ '_id' => $link->_id ], [ '$set' => $update_array ] );
          $link_counter ++;

          // at last, insert the item into the processed collection
          // ... if this link was already inserted, we'll get to the exception handler below
          //     which will simply ignore the duplicate record, so we can continue with next link
          $insert_value = [
            '_id'         => $link->_id,
            'title'       => $link->title,
            'description' => $link->description,
            'link'        => $link->link,
            'img'         => $link->img,
            'date'        => $link->date,
            'fetched'     => $link->fetched,
            'feed'        => $link->feed
          ];

          $mongo->bayesian->processed->insertOne( $insert_value );
        } catch ( MongoDB\Driver\Exception\BulkWriteException $ex ) {
          // duplicate error can only happen in the processed collection and that's in case when we try to insert
          // a record with link that's already been added, albeit for another user in the past
          // ... since we might be adding this record to a new user who's just added this feed, we need to update
          //     ID of that user's record to actually point to the existing record in the processed collection
          if ( $ex->getCode() != 11000 ) {
            // TODO: something went wrong while trying to insert the data, log this properly
            // throw new \Exception( $ex );
            file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-train', $ex->getTraceAsString() . "\n", FILE_APPEND );
            $exceptions_counter++;
          } else {
            $duplications_counter++;
            echo 'duplication for user ' . $user->short_id . ', item ' . $link->_id . ' (' . $link->title .'), feed ' . $feed_object . ' .. ' . $ex->getMessage() . "<br>\n";

            // load and remove trained data, as they would not have a processed collection counterpart
            // ... we will be re-adding them below, as _id field cannot be updated as such
            $old_data = $mongo->bayesian->{'training-' . $user->short_id}->findOne( [ '_id' => $link->_id ] );
            $mongo->bayesian->{'training-' . $user->short_id}->deleteOne( [ '_id' => $link->_id ] );

            // load the ID from processed collection
            $existing_id = $mongo->bayesian->processed->findOne([ 'feed' => $link->feed, 'link' => $link->link ], [ 'projection' => [ '_id' => 1 ] ]);
            // something's wrong, as we did not find this link in the processed collection...
            if (!$existing_id) {
              throw new \Exception('Duplicate link  ' . $link->link . ' not found in processed collection - cannot update training data!');
            } else {
              // update ID in $training_array
              $old_data[ '_id' ] = $existing_id->_id;

              // insert a new record with the proper ID
              $mongo->bayesian->{'training-' . $user->short_id}->insertOne( $old_data );
              echo 'ID updated from ' . $link->_id .' to ' . $existing_id->_id . "<br>\n";
            }
          }
        }
      }
    } catch ( \Exception $ex ) {
      // TODO: something went wrong during program execution, log this properly
      // throw new \Exception( $ex );
      file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-train', $ex->getTraceAsString() . "\n", FILE_APPEND );
      $exceptions_counter++;
    }
  }
}

// mark processed links as processed
if ( count($processed_ids) ) {
  $mongo->bayesian->unprocessed->updateMany( [ '_id' => [ '$in' => array_values( $processed_ids ) ] ], [ '$set' => [ 'processed' => 1, 'processed_ts' => time() ] ] );
}

// remove all processed links that's been processed for more than 11 days from the unprocessed collection
$mongo->bayesian->unprocessed->deleteMany(['processed' => 1, 'fetched' => [ '$lt' => (time() - (60 * 60 * 24 * 11))] ]);

$time_end = microtime(true);
echo '<br><br>[' . date('j.m.Y, H:i:s') . '] ' . (round($time_end - $time_start,3) * 1000) . 'ms for ' . $link_counter .' links with ' . $user_counter . ' users trained<br><br>';

// insert data into log
$mongo->bayesian->logs->insertOne([
  'type' => 'links-trainer',
  'start' => $time_start,
  'end' => $time_end,
  'duration' => (round($time_end - $time_start,3) * 1000),
  'links_count' => $link_counter,
  'users_count' => $user_counter,
  'duplicates_count' => $duplications_counter,
  'exceptions_count' => $exceptions_counter,
]);

// mark job as finished
$mongo->bayesian->jobs->updateOne([ '_id' => $job->getInsertedId() ], [ '$set' => [ 'end' => time(), 'lambdas' => 0 ] ]);
?>
<script>
  // reload every minute
  var reloadTime = 60000;
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