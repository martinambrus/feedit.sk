<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";
  require_once "../functions/functions-score-global.php";
  require_once "../functions/functions-words.php";
  require_once "../functions/functions-training.php";

  if (empty($_POST['data']) || !is_array($_POST['data'])) {
    send_error($lang['Missing parameter'], $lang['Trained Article Data Incomplete'], 400, 'validation', ['api' => 'training-detailed', 'field' => 'data']);
  }

  // convert all link IDs into ObjectIDs and gather them all
  $ids = [];
  array_walk($_POST['data'], function($value, $key) {
    global $lang, $ids;

    // convert each item in the array into a MongoDB ObjectID
    try {
      $_POST['data'][ $key ]['id'] = new MongoDB\BSON\ObjectId( (string) $value['id'] );
      $ids[] = $_POST['data'][ $key ]['id'];
    } catch (\Exception $ex) {
      send_error($lang['System Error'], $lang['Article ID not ObjectID'], 400, 'validation', [ 'api' => 'training-detailed', 'id' => $value['id'] ]);
    }
  });

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
  $auto_rated_items = [];

  // first, load data from the global processed table
  $records = [];
  foreach ($mongo->{MONGO_DB_NAME}->processed->find( ['_id' => [ '$in' => $ids ] ], [
    'projection' => [
      'description' => 1,
    ],
    'sort' => [
      'feed' => 1,
    ],
  ] ) as $record) {
    $records[ (string) $record->_id ] = $record;
  }

  foreach ($mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->find( ['_id' => [ '$in' => $ids ] ] ) as $record) {
    // check if we need to train this item first
    $rate = false;
    $current_link_data = false;

    foreach ($_POST['data'] as $training_data) {
      if ($training_data['id'] == (string) $record->_id) {
        $current_link_data = $training_data;

        // check that we have a rating from the training
        if (isset($training_data['rate'])) {
          // we'll rate this link
          $rate = (int) $training_data['rate'];
        } else {
          // there is no rating from training, check whether we have requested any words training
          // other than ignoring them, in which case we'll need to auto-train this link prior to doing the detailed training
          // (because unrated words can't have their average percentage calculated)
          $auto_rating_required = false;

          if (!empty($training_data['words'])) {
            foreach ($training_data['words'] as $word_trained) {
              if ((int) $word_trained['rate'] === 0 || (int) $word_trained['rate'] === 1) {
                $auto_rating_required = true;
                break;
              }
            }
          }

          if (!empty($training_data['categories'])) {
            foreach ($training_data['categories'] as $category_trained) {
              if ((int) $category_trained['rate'] === 0 || (int) $category_trained['rate'] === 1) {
                $auto_rating_required = true;
                break;
              }
            }
          }

          if (!empty($training_data['phrases'])) {
            foreach ($training_data['phrases'] as $phrase_trained) {
              if ((int) $phrase_trained['rate'] === 0 || (int) $phrase_trained['rate'] === 1) {
                $auto_rating_required = true;
                break;
              }
            }
          }

          if (
            !empty($training_data['author']) &&
            !empty($training_data['author']['rate']) &&
            (
              (int) $training_data['author']['rate'] === 0 || (int) $training_data['author']['rate'] === 1
            )
          ) {
            $auto_rating_required = true;
          }

          // if we need to auto-train this article before we can update words' scoring,
          // do it here
          if ($auto_rating_required && !$record->trained) {
            $positives = 0;
            $negatives = 0;

            if (!empty($training_data['words'])) {
              foreach ( $training_data['words'] as $word_trained ) {
                if ( (int) $word_trained['rate'] === 0 ) {
                  $negatives ++;
                } else if ( (int) $word_trained['rate'] === 1 ) {
                  $positives ++;
                }
              }
            }

            if (!empty($training_data['categories'])) {
              foreach ( $training_data['categories'] as $category_trained ) {
                if ( (int) $category_trained['rate'] === 0 ) {
                  $negatives ++;
                } else if ( (int) $category_trained['rate'] === 1 ) {
                  $positives ++;
                }
              }
            }

            if (!empty($training_data['phrases'])) {
              foreach ( $training_data['phrases'] as $phrase_trained ) {
                if ( (int) $phrase_trained['rate'] === 0 ) {
                  $negatives ++;
                } else if ( (int) $phrase_trained['rate'] === 1 ) {
                  $positives ++;
                }
              }
            }

            if (
              !empty($training_data['author']) &&
              !empty($training_data['author']['rate'])
            ) {
              if ((int) $training_data['author']['rate'] === 0) {
                $negatives++;
              } else if ((int) $training_data['author']['rate'] === 1) {
                $positives++;
              }
            }

            // determine how we're going to train this article
            if ($positives >= $negatives) {
              // rate positively if positives prevail or positive and negative is on par
              $rate = 1;
            } else {
              // rate negatively if negatives prevail or there was an error
              $rate = 0;
            }
          }
        }

        // break the loop, as we've just found our link
        break;
      }
    }

    // if we're rating, rate link here
    if ($rate !== false) {
      // verify rate
      if ( $rate < -1 || $rate > 1 ) {
        // possibly a spoofed rating value
        // TODO: log properly
        //throw new \Exception( 'Possibly spoofed rate value: ' . $rate );
        file_put_contents( 'logs.txt', 'Possibly spoofed rate value: ' . $rate . "\n", FILE_APPEND );
      }

      rate_link( $record, $rate );

      // add to a list of manually rated items
      $auto_rated_items[ (string) $record->_id ] = $rate;
    }

    // train words for current link
    if (!empty($current_link_data['words'])) {
      foreach ($current_link_data['words'] as $word_data) {
        // word was voted up/down
        if (isset($word_data['rate_intensity']) && isset($word_data['rate'])) {
          word_train_score( $word_data['word'], (int) $word_data['rate_intensity'], $record->feed );
        }

        // word is being ignored
        if (isset($word_data['rate']) && (int) $word_data['rate'] == -1) {
          word_train_ignore( $word_data['word'], $record->feed );
        }

        // word is being un-ignored
        if (isset($word_data['rate']) && (int) $word_data['rate'] == 2) {
          word_train_unignore( $word_data['word'], $record->feed );
        }
      }
    }

    // train categories for current link
    if (!empty($current_link_data['categories'])) {
      foreach ($current_link_data['categories'] as $category_data) {
        // category was voted up/down
        if (isset($category_data['rate_intensity']) && isset($category_data['rate'])) {
          category_train_score( $category_data['name'], (int) $category_data['rate_intensity'], $record->feed );
        }

        // category is being ignored
        if (isset($category_data['rate']) && (int) $category_data['rate'] == -1) {
          category_train_ignore( $category_data['name'], $record->feed );
        }

        // category is being un-ignored
        if (isset($category_data['rate']) && (int) $category_data['rate'] == 2) {
          category_train_unignore( $category_data['name'], $record->feed );
        }
      }
    }

    // train phrases for current link
    if (!empty($current_link_data['phrases'])) {
      foreach ($current_link_data['phrases'] as $phrase_data) {
        // phrase was voted up/down
        if (isset($phrase_data['rate_intensity']) && isset($phrase_data['rate'])) {
          phrase_train_score( $phrase_data['text'], (int) $phrase_data['rate_intensity'], $record->feed );
        }

        // phrase is being removed
        if (isset($phrase_data['rate']) && (int) $phrase_data['rate'] == -1) {
          phrase_train_remove( $phrase_data['text'], $record->feed );
        }
      }
    }

    // train author for current link
    if (!empty($current_link_data['author'])) {
      // author was voted up/down
      if (isset($current_link_data['author']['rate_intensity']) && isset($current_link_data['author']['rate'])) {
        author_train_score( $current_link_data['author']['name'], (int) $current_link_data['author']['rate_intensity'], $record->feed );
      }

      // author is being ignored
      if (isset($current_link_data['author']['rate']) && (int) $current_link_data['author']['rate'] == -1) {
        author_train_ignore( $current_link_data['author']['name'], $record->feed );
      }

      // author is being un-ignored
      if (isset($current_link_data['author']['rate']) && (int) $current_link_data['author']['rate'] == 2) {
        author_train_unignore( $current_link_data['author']['name'], $record->feed );
      }
    }
  }

  // if we've auto-rated any items, list them, otherwise no output is sent out if everything went smoothly
  if (count($auto_rated_items)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode( [ 'auto_rated_items' => $auto_rated_items ], \JSON_UNESCAPED_UNICODE );
  }