<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['ids'])) {
    send_error($lang['Missing parameter'], $lang['Trained Article Data Incomplete'], 400, 'validation', ['api' => 'training-modal', 'field' => 'trained_items']);
  }

  require_once "../functions/functions-words.php";
  require_once "../functions/functions-score-global.php";
  require_once "../functions/functions-content.php";

  // cache labels for this user
  cache_labels();

  /***
   * GET DATA FROM DB
   */

  // make sure we don't have more than 25 IDs to train
  $_POST['ids'] = array_slice($_POST['ids'], 0, 25);

  // convert all IDs into ObjectIDs
  array_walk($_POST['ids'], function(&$value) {
    // convert each item in the array into a MongoDB ObjectID
    $value = new MongoDB\BSON\ObjectId( (string) $value );
  });

  // select the items we have determined from the DB
  $filter = [
    '_id' => [
      '$in' => $_POST['ids']
    ]
  ];

  // retrieve data for all items
  $processed_data = $mongo->bayesian->{'training-' . $user->short_id}->find( $filter, [
    'projection' => [
      'feed' => 1,
      'interest_average_percent_total' => 1,
      'score' => 1,
      'date' => 1,
      'rated' => 1,
      'author' => 1,
      'categories' => 1,
      'labels' => 1,
      'label_predictions' => 1,
    ]
  ] );

  $processed_ids       = [];
  $processed           = [];
  $scoring_adjustments = [];
  $scored_authors      = [];
  $scored_categories   = [];
  $scored_words        = [];
  $scored_ngrams       = [];
  $feed_objects        = []; // IDs of all feeds for all the items we're training
  $feeds_data          = []; // data for each feed we need it for

  foreach ( $processed_data as $record ) {
    $processed[ (string) $record->_id ] = $record;
    $processed_ids[] = $record->_id;
    $feed_objects[(string) $record->feed] = $record->feed;
  }

  if (!count($feed_objects)) {
    // nothing found, raise error
    send_error($lang['No Data Returned'], $lang['No Data Returned'], 500, 'database', ['api' => 'training-modal', 'ids' => $_POST['ids']]);
  }

  complete_trained_data( $processed, $processed_ids, false );
  foreach ($feed_objects as $feed_object_id => $feed_object) {
    $feed_data[ $feed_object_id ] = load_feed_score_adjustments( $feed_object );
  }
  $out = [];

  // load all scored authors for all of our feeds
  foreach ( $mongo->bayesian->{'authors-' . $user->short_id}->find( [ 'feed' => [ '$in' => array_values($feed_objects) ] ] ) as $record ) {
    if (!isset($scored_authors[ (string) $record->feed ])) {
      $scored_authors[ (string) $record->feed ] = [];
    }

    $scored_authors[ (string) $record->feed ][ $record->author ] = $record;
  }

  // load all scored categories for all of our feeds
  foreach ( $mongo->bayesian->{'categories-' . $user->short_id}->find( [ 'feed' => [ '$in' => array_values($feed_objects) ] ] ) as $record ) {
    if (!isset($scored_categories[ (string) $record->feed ])) {
      $scored_categories[ (string) $record->feed ] = [];
    }

    $scored_categories[ (string) $record->feed ][ $record->category ] = $record;
  }

  /***
   * ADD WORDS TRAINING DATA
   */

  // add training data for each of the selected links
  foreach ($processed as $item) {
    $words                   = parse_words( $item['title'], $feed_data[ (string) $item->feed ]->lang );
    $detailed_score          = calculate_score( $item->feed, $user->short_id, $words, (string) $item->_id, false, false );
    $detailed_score_remapped = [];

    // remap calculated data, as we'll need to be checking for next word being a measurement unit
    foreach ( $detailed_score['words_details'] as $detail_word => $detail ) {
      $detailed_score_remapped[] = [
        'word'   => $detail_word,
        'detail' => $detail
      ];
    }

    // build and cache calculated data
    $skip_next = false;
    foreach ( $detailed_score_remapped as $detail_index => $detail_key ) {
      if ( $skip_next ) {
        $skip_next = false;
        continue;
      }

      $score_increment_by = 1;
      if ( is_numeric( $detail_key['word'] ) ) {
        if ( isset( $detailed_score_remapped[ $detail_index + 1 ] ) && in_array( $detailed_score_remapped[ $detail_index + 1 ], $measurement_units_array ) ) {
          $skip_next          = true;
          $detail_key['word'] = $detail_key['word'] . $detailed_score_remapped[ $detail_index + 1 ];
          $score_increment_by += $scoring_adjustments['measurement_unit'];
        } else {
          $score_increment_by += $scoring_adjustments['number'];
        }
      } else {
        $score_increment_by += $scoring_adjustments['word'];
      }

      if ( $detail_key['detail']['weightings'] > 0 ) {
        $percentage_color = round( ( $detail_key['detail']['score'] / $detail_key['detail']['weightings'] ) * 100 );
      } else {
        $percentage_color = 50;
      }

      $color = [
        'red'   => 255 - abs( $percentage_color > 50 ? 2.55 * ( $percentage_color > 0 ? $percentage_color : 255 ) : 0 ),
        'green' => 255 - abs( $percentage_color < 50 ? 2.55 * ( $percentage_color > 0 ? $percentage_color : 255 ) : 0 ),
      ];

      // adjust for negative or 100+% scores
      if ($color['red'] < 0) {
        $color['red'] = 0;
      } else if ($color['red'] > 255) {
        $color['red'] = 255;
      }

      if ($color['green'] < 0) {
        $color['green'] = 0;
      } else if ($color['green'] > 255) {
        $color['green'] = 255;
      }

      $detailed_score['words_details'][ $detail_key['word'] ]['percentage']         = $percentage_color;
      $detailed_score['words_details'][ $detail_key['word'] ]['color']              = $color;
      $detailed_score['words_details'][ $detail_key['word'] ]['scoring_adjustment'] = $score_increment_by - 1;
    }

    /***
     * ADD THIS ITEM'S INFO TO THE OUTPUT
     */
    $data = [
      'id' => $item->_id,
      'interest_average_percent_total' => $item->interest_average_percent_total,
      'score' => $item->score,
      'link' => $item->link,
      'title' => $item->title,
      'date' => $item->date,
      'date_long' => $item->date_long,
      'description' => $item->description_clear,
    ];

    if (isset($item->rated)) {
      $data['rated'] = $item->rated;
    }

    // add image, if exists
    if ($item->img) {
      $data['img'] = $item->img;
    }

    // add link-assigned labels
    if (isset($item->labels)) {
      $data['labels'] = $item->labels;
    }

    // add label predictions for the link
    if (isset($item->label_predictions)) {
      $data['label_predictions'] = $item->label_predictions;
    }

    // add scored words
    if (isset($detailed_score['words_details']) && count($detailed_score['words_details'])) {
      $data['words'] = [];

      foreach ( $detailed_score['words_details'] as $word => $word_data ) {
        $data['words'][] = [
          'word'     => $word,
          'ignored'  => $word_data['ignored'],
          'bg_color' => ($word_data['ignored'] ? '#F4F4F4' : adjustBrightness( rgb2hex( [ $word_data['color']['red'], $word_data['color']['green'], 0 ] ), 0.7) ),
        ];
      }
    }

    // add feed phrases
    if ( isset($feed_data[ (string) $item->feed ]->adjustment_phrases) && count( $feed_data[ (string) $item->feed ]->adjustment_phrases ) ) {
      $data['phrases'] = [];
      $lowercase_title = mb_strtolower( $item['title'] );
      $lowercase_description = mb_strtolower( $item['description'] );
      foreach ( $feed_data[ (string) $item->feed ]->adjustment_phrases as $phrase => $phrase_weight ) {
        $lowercase_phrase = mb_strtolower( $phrase );
        if ( strpos( $lowercase_title, $lowercase_phrase ) !== false || strpos( $lowercase_description, $lowercase_phrase ) !== false ) {
          $data['phrases'][] = [
            'text'     => $phrase,
            'bg_color' => ( $phrase_weight >= 0 ? '#b3ffb3' : '#ffb3b3' ),
          ];
        }
      }

      if (!count($data['phrases'])) {
        unset($data['phrases']);
      }
    }

    // add link author
    if ( isset( $item['author'] ) && isset($scored_authors[ (string) $item->feed ]) ) {
      foreach ( $scored_authors[ (string) $item->feed ] as $author => $author_record ) {
        if ( $item['author'] == $author ) {
          // calculate scoring percentage
          if ( $author_record->weightings > 0 ) {
            $percentage_color = round( ( $author_record->weight / $author_record->weightings ) * 100 );
          } else {
            $percentage_color = 50;
          }

          $color = [
            'red'   => 255 - abs( $percentage_color > 50 ? 2.55 * ( $percentage_color > 0 ? $percentage_color : 255 ) : 0 ),
            'green' => 255 - abs( $percentage_color < 50 ? 2.55 * ( $percentage_color > 0 ? $percentage_color : 255 ) : 0 ),
          ];

          // adjust for negative or 100+% scores
          if ($color['red'] < 0) {
            $color['red'] = 0;
          } else if ($color['red'] > 255) {
            $color['red'] = 255;
          }

          if ($color['green'] < 0) {
            $color['green'] = 0;
          } else if ($color['green'] > 255) {
            $color['green'] = 255;
          }

          $data['author'] = [
            'name' => $author,
            'ignored' => (isset($author_record->ignored) ? $author_record->ignored : 0),
            'bg_color' => (isset($author_record->ignored) && $author_record->ignored ? '#F4F4F4' : adjustBrightness( rgb2hex( [ $color['red'], $color['green'], 0 ] ), 0.7) ),
          ];

          // only 1 author per article supported for the moment
          break;
        }
      }
    }

    // add categories
    if ( isset( $item->categories ) && isset($scored_categories[ (string) $item->feed ]) ) {
      $data['categories'] = [];
      foreach ( $scored_categories[ (string) $item->feed ] as $category => $category_record ) {
        foreach ( $item->categories as $item_category ) {
          if ( $item_category == $category ) {
            // calculate scoring percentage
            if ( $category_record->weightings > 0 ) {
              $percentage_color = round( ( $category_record->weight / $category_record->weightings ) * 100 );
            } else {
              $percentage_color = 50;
            }

            $color = [
              'red'   => 255 - abs( $percentage_color > 50 ? 2.55 * ( $percentage_color > 0 ? $percentage_color : 255 ) : 0 ),
              'green' => 255 - abs( $percentage_color < 50 ? 2.55 * ( $percentage_color > 0 ? $percentage_color : 255 ) : 0 ),
            ];

            // adjust for negative or 100+% scores
            if ($color['red'] < 0) {
              $color['red'] = 0;
            } else if ($color['red'] > 255) {
              $color['red'] = 255;
            }

            if ($color['green'] < 0) {
              $color['green'] = 0;
            } else if ($color['green'] > 255) {
              $color['green'] = 255;
            }

            $data['categories'][] = [
              'name' => $category,
              'ignored' => (isset($category_record->ignored) ? $category_record->ignored : 0),
              'bg_color' => (isset($category_record->ignored) && $category_record->ignored ? '#F4F4F4' : adjustBrightness( rgb2hex( [ $color['red'], $color['green'], 0 ] ), 0.7) ),
            ];
          }
        }
      }

      if (!count($data['categories'])) {
        unset($data['categories']);
      }
    }

    $out[ $data['id'] ] = $data;
  }

  // make sure the $out array has the same sorting as our "ids" post data
  $out_new = [];
  foreach ($_POST['ids'] as $id) {
    $out_new[] = $out[ (string) $id ];
  }

  $out = $out_new;

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( $out, \JSON_UNESCAPED_UNICODE );