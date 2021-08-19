<?php
  $cached_labels = [];

  function cache_labels() {
    global $mongo, $user, $cached_labels;
    static $cached = false;

    if ($cached) {
      return;
    }

    // cache labels for this user
    foreach ($mongo->{MONGO_DB_NAME}->{'labels-' . $user->short_id}->find() as $label_record) {
      // make sure we have labels as an array
      if (!empty($_POST['labels']) && !is_array($_POST['labels'])) {
        $_POST['labels'] = [ $_POST['labels'] ];
      }

      if (!empty($_POST['labels']) && in_array((string) $label_record->_id, $_POST['labels'])) {
        $label_titles[] = $label_record->label;
      }

      $cached_labels[ (string) $label_record->_id ] = $label_record;
    }

    $cached = true;
  }

  function build_filters_and_options() {
    global $mongo, $user, $cached_labels;

    // filters
    $feed = (string) $_POST['feed'];

    if ($feed != 'bookmarks' && $feed != 'all') {
      $feed_object = new MongoDB\BSON\ObjectId( $feed );
    }

    $label_titles  = [];

    if (isset($feed_object)) {
      $filter = [ 'feed' => $feed_object ];
    } else {
      if ($feed == 'bookmarks') {
        $filter = [ 'bookmarked' => 1 ];
      } else {
        $filter = [];
      }
    }

    $options       = [
      'sort' => [
        'score_conformed' => -1,
        'score' => -1,
        'interest_average_percent_total' => -1,
        'zero_scored_words' => 1,
        'date' => -1,
        '_id' => -1,
      ]
    ];

    $trained = $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id};

    // don't filter on read or trained if we're showing bookmarks
    if ($feed != 'bookmarks') {
      // display all, unread or untrained links?
      if ( $_POST['status'] == 'unread' ) {
        $filter['read'] = 0;
      } else if ( $_POST['status'] == 'untrained' ) {
        $filter['trained'] = 0;
      } else if ( $_POST['status'] == 'trained_pos' ) {
        $filter['trained'] = 1;
        $filter['rated'] = 1;
      }
    }

    // we always work with labels as if they were an array
    if (!empty($_POST['labels']) && !is_array($_POST['labels'])) {
      $_POST['labels'] = [ $_POST['labels'] ];
    }

    cache_labels();

    // display links with a certain label?
    if ( !empty($_POST['labels']) ) {
      // select all requested labels, so we can get their text values
      array_walk($_POST['labels'], function(&$value) {
        // convert each item in the array into a MongoDB ObjectID
        $value = new MongoDB\BSON\ObjectId( (string) $value );
      });

      $label_objects = $_POST['labels'];

      foreach ($cached_labels as $cached_label) {
        foreach ($label_objects as $label_object) {
          // check that this label was selected to be filtered by
          if ((string) $label_object == (string) $cached_label->_id) {
            $label_titles[] = $cached_label->label;
          }
        }
      }

      if (!isset($filter['$and'])) {
        $filter['$and'] = [];
      }

      $filter['$and'][] = [
        '$or' => [
          [ 'labels' => [ '$in' => $label_objects ] ],
          [ 'label_predictions' => [ '$elemMatch' => [ 'label' => [ '$in' => $label_titles ], 'probability' => [ '$gt' => 80 ] ] ] ]
        ]
      ];
    }

    // get average score for this feed in the DB
    $feed_average_score = $trained->aggregate([
      [
        '$match' => (isset($feed_object) ? [
          'feed' => $feed_object,
          'score_conformed' => [ '$gte' => 0 ]
        ] : [
          'bookmarked'      => 1,
          'score_conformed' => [ '$gte' => 0 ]
        ])
      ],
      [
        '$group' => [
          '_id' => null,
          'average' => [
            '$avg' => '$score_conformed'
          ]
        ]
      ]
    ]);

    foreach ( $feed_average_score as $record ) {
      $feed_average_score = $record->average;
      break;
    }

    // hide links based on user interest average percent value?
    if (!empty($_POST['hide'])) {

      $hiding = (int) $_POST['hide'];
      if ( $hiding > 1 ) {
        if ( $hiding == 2 ) {
          if (!isset($filter['$and'])) {
            $filter['$and'] = [];
          }

          $filter['$and'][] = [
            '$or' => [
              [
                'interest_average_percent_total' => [ '$gt' => 5 ],
                'words_rated_above_50_percent' => 0,
              ],
              [
                'interest_average_percent_total' => [ '$gte' => 0 ],
                'words_rated_above_50_percent' => [ '$gt' => 0 ]
              ]
            ]
          ];
        } else if ( $hiding == 3 ) {
          if (!isset($filter['$and'])) {
            $filter['$and'] = [];
          }

          $filter['$and'][] = [
            '$or' => [
              [
                'interest_average_percent_total' => [ '$gt' => 10 ],
                'words_rated_above_50_percent' => 0,
              ],
              // also select tier 2, if the score of this links is above average score for our feed,
              // as quite a few tier 2 links were observed to be as relevant as tier 3 links, albeit
              // being scored at a lower interest rate percentage
              [
                'interest_average_percent_total' => [ '$gt' => 5 ],
                'score_conformed' => [ '$gt' => $feed_average_score ]
              ],
              [
                'interest_average_percent_total' => [ '$gte' => 0 ],
                'words_rated_above_50_percent' => [ '$gt' => 0 ]
              ]
            ]
          ];
        } else if ( $hiding == 4 ) {
          if (!isset($filter['$and'])) {
            $filter['$and'] = [];
          }

          $filter['$and'][] = [
            '$or' => [
              [
                'interest_average_percent_total' => [ '$gt' => 30 ],
              ],
              [
                'interest_average_percent_total' => [ '$gte' => 0 ],
                'words_rated_above_50_percent' => [ '$gt' => 0 ]
              ]
            ]
          ];
        } // TIER 5 is handled in the ELSE section of the IF-ELSE statement below
          // ... it has the interest_average_percent_total => [ $gt => 50 ] part

        // always show links with 100% words unrated,
        // otherwise we'd end up unable to train new articles
        // while reviewing the filtered interesting ones
        if ( $hiding >= 2 && $hiding <= 4 ) {
          $filter['$and'][ count($filter['$and']) - 1 ]['$or'][] = [
            'interest_average_percent_total' => 0,
            'zero_rated_scored_words_percentage' => 0
          ];
        } else {
          if (!isset($filter['$and'])) {
            $filter['$and'] = [];
          }

          $filter['$and'][] = [
            '$or' => [
              [
                'interest_average_percent_total' => 0,
                'zero_rated_scored_words_percentage' => 0
              ],
              [
                'interest_average_percent_total' => [ '$gt' => 50 ]
              ]
            ]
          ];
        }

        // for tier 3 displaying only - since we are also including tier 2 results with total score above average,
        // we need to make sure to only include those where zero rated scored words percentage is above 60%
        // or we risk showing too many irrelevant tier 2 records
        if ($hiding == 3 && isset($filter['$and'][ count($filter['$and']) - 1 ]['$or'][1]['interest_average_percent_total'])) {
          $filter['$and'][ count($filter['$and']) - 1 ]['$or'][1]['zero_rated_scored_words_percentage'] = [ '$lte' => 60 ];
        }
      }

    } else {
      $hiding = 1;
    }

    // include archived records where the tier has been permanently set
    $filter['$or'] = [
      [
        'tier' => [
          '$exists' => false
        ]
      ],
      [
        'tier' => [ '$gte' => (int) $hiding ]
      ]
    ];

    // hide links rated below 0 (i.e. manually adjusted via word, author, category or phrase adjustment to a negative value)
    // ... but only if we're not loading bookmarks data, or it might not load all of them
    if (isset($feed_object) || $_POST['feed'] == 'all' /* && $_POST['status'] != 'all'*/) { // the "all" commented part is for debugging only
      $filter['score'] = [ '$gte' => 0 ];
    }

    // per page limit
    $per_page = (!empty($_POST['per_page']) ? (int) $_POST['per_page'] : 25);

    // limit validation
    if ($per_page < 5 || $per_page > 250) {
      $per_page = 25;
    }

    // order by score or date?
    if (!empty($_POST['sort'])) {
      $post_comparison_value = ($_POST['sort'] == 'score' ? 'sc' : 'date');
      if ($post_comparison_value == 'date') {
        // add this, so we can use index for sorting selected DB results
        $filter['zero_scored_words'] = [
          '$exists' => true,
        ];

        // change default sort options for date sorting
        $options['sort'] = [
          'date' => -1,
          '_id' => -1,
          'zero_scored_words' => -1,
        ];
      }

      if (!empty($_POST['way']) && $_POST['way'] == 'down') {
        if (
          isset( $_POST['use_skip_limit'] ) &&
          (int) $_POST['use_skip_limit'] == 0 &&
          isset( $_POST[ $post_comparison_value ] )
        ) {
          // use filter data from the page to paginate data
          if ($post_comparison_value == 'date') {
            $_POST[ $post_comparison_value ] = (int) $_POST[ $post_comparison_value ];
            $filter['date'] = [ '$lte' => $_POST[ $post_comparison_value ] ];
          } else {
            if ( is_numeric( $_POST[ $post_comparison_value ] ) ) {
              $_POST[ $post_comparison_value ] = (float) $_POST[ $post_comparison_value ];
            } else {
              $_POST[ $post_comparison_value ] = (int) $_POST[ $post_comparison_value ];
            }

            $filter['score_conformed'] = [ '$lte' => $_POST[ $post_comparison_value ] ];
          }

          if ( ! empty( $_POST['exclude_ids'] ) && is_array( $_POST['exclude_ids'] ) && count( $_POST['exclude_ids'] ) ) {
            array_walk( $_POST['exclude_ids'], function ( &$value ) {
              // convert each ID in the array into a MongoDB ObjectID
              $value = new MongoDB\BSON\ObjectId( (string) $value );
            } );

            $filter['_id'] = [ '$nin' => $_POST['exclude_ids'] ];
          }
        } else if (
          isset( $_POST['use_skip_limit'] ) &&
          (int) $_POST['use_skip_limit'] == 1 &&
          ! empty( $_POST['page'] )
        ) {
          // use skip-limit for sorting, as we cannot reliably use a filter here
          $options['skip'] = $per_page * (int) $_POST['page'];
        }
      } else if (!empty($_POST['way']) && $_POST['way'] == 'up') {
        // inverse pagination
        if ($post_comparison_value == 'date') {
          $options['sort'] = [
            'date' => 1,
            '_id' => 1,
            'zero_scored_words' => 1,
          ];
        } else {
          $options['sort'] = [
            'score_conformed'                => 1,
            'score'                          => 1,
            'interest_average_percent_total' => 1,
            'zero_scored_words'              => - 1,
            'date'                           => 1,
            '_id'                            => 1,
          ];
        }

        if (
          isset( $_POST['use_skip_limit'] ) &&
          (int) $_POST['use_skip_limit'] == 0 &&
          isset( $_POST[ $post_comparison_value ] )
        ) {

          if ($post_comparison_value == 'date') {
            $_POST[ $post_comparison_value ] = (int) $_POST[ $post_comparison_value ];
            $filter['date'] = [ '$gte' => $_POST[ $post_comparison_value ] ];
          } else {
            if ( is_numeric( $_POST[ $post_comparison_value ] ) ) {
              $_POST[ $post_comparison_value ] = (float) $_POST[ $post_comparison_value ];
            } else {
              $_POST[ $post_comparison_value ] = (int) $_POST[ $post_comparison_value ];
            }

            $filter['score_conformed'] = [ '$gte' => $_POST[ $post_comparison_value ] ];
          }

          if ( ! empty( $_POST['exclude_ids'] ) && is_array( $_POST['exclude_ids'] ) && count( $_POST['exclude_ids'] ) ) {
            array_walk( $_POST['exclude_ids'], function ( &$value ) {
              // convert each ID in the array into a MongoDB ObjectID
              $value = new MongoDB\BSON\ObjectId( (string) $value );
            } );

            $filter['_id'] = [ '$nin' => $_POST['exclude_ids'] ];
          }
        } else if (
          isset( $_POST['use_skip_limit'] ) &&
          (int) $_POST['use_skip_limit'] == 1 &&
          ! empty( $_POST['page'] )
        ) {
          // get total items for our current filters selection
          $total_items = $trained->countDocuments($filter, $options);

          // calculate number of pages for these items
          $pages_count = (int) ceil($total_items / $per_page);

          // calculate how many records to skip from the end to get to the previous page
          // ... first, calculate how many records to skip for all pages
          $skip = ( $pages_count - (int) $_POST['page'] + 1 ) * $per_page;

          // if we have a non-per-page remainder of items on the last page,
          // subtract the amount of items missing to the $per_page amount from $skip variable,
          // so we can calculate our backwards pagination correctly
          $last_page_remainder = $total_items % $per_page;
          if ($last_page_remainder > 0) {
            $skip -= ($per_page - $last_page_remainder);
          }

          // use skip-limit for sorting, as we cannot reliably use a filter here
          $options['skip'] = $skip;
        }
      }
    }

    $options['limit'] = $per_page;

    // return only what we need for the API result
    $options['projection'] = [
      'read'                           => 1,
      'bookmarked'                     => 1,
      'rated'                          => 1,
      'labels'                         => 1,
      'label_predictions'              => 1,
      'score_conformed'                => 1,
    ];

    // if we're showing bookmarks or all items, also add feed into projection
    if ($feed == 'bookmarks' || $feed == 'all') {
      $options['projection']['feed'] = 1;
    }

    return [ $filter, $options, $trained ];
  }

  // adds data from the processed collection into our existing results from trained collection of current user
  function complete_trained_data( &$processed, &$processed_ids) {
    global $mongo, $cached_labels, $locales;

    // retrieve actual title, URL and description data for the selected links
    // re-use this variable...
    $processed_data = $mongo->{MONGO_DB_NAME}->processed->find( [ '_id' => [ '$in' => $processed_ids ] ], [
      'projection' => [
        'title' => 1,
        'description' => 1,
        'link' => 1,
        'date' => 1,
        'img' => '',
      ]
    ] );

    foreach ( $processed_data as $record ) {
      // add the remainder of data to our records
      $processed[ (string) $record->_id ]->title = $record->title;
      $processed[ (string) $record->_id ]->description = $record->description;

      // update all src attributes of images in description, so they are not immediately loaded
      // but rather stored in a data attribute, waiting to be revealed by JS
      $processed[ (string) $record->_id ]->description = preg_replace('/\<img(.+)src\=(?:\"|\')(.+?)(?:\"|\')((?:.+?))\>/m', '<img$1src="img/logo114.png" data-src="$2"$3>', $processed[ (string) $record->_id ]->description);

      // if the description ends with ellipsis, remove them
      if (substr($processed[ (string) $record->_id ]->description, -3) == '...') {
        $processed[ (string) $record->_id ]->description = substr($processed[ (string) $record->_id ]->description,0, -3);
      }

      // provide a tags-clear description as well
      $description = untagize( $record->description );

      // also remove carriage returns and tabs and replace new lines by something more readable
      $description = str_replace( [ "\r", "\t" ], '', $description );
      $description = str_replace( "\n", ' | ', $description );

      // replace multiple | | occurrences after converting new lines into pipes
      $regex = '/\|[ ]+\|/m';
      $breaker = 0;
      while (preg_match($regex, $description) && $breaker < 100) {
        $description = preg_replace($regex, '|', $description);
        $breaker++;
      }

      $processed[ (string) $record->_id ]->description_clear = $description;

      $processed[ (string) $record->_id ]->link = $record->link;

      // if the date is a today's date, leave it in hours:minutes format,
      // otherwise display a short date format with day and month
      if (date('j.m.Y', $record->date) == date('j.m.Y', time())) {
        $processed[ (string) $record->_id ]->date = date( 'H:i', $record->date );
      } else {
        $processed[ (string) $record->_id ]->date = $locales[ LANGUAGE ]['day_names'][ date( 'D', $record->date) ] . ', ' . date( $locales[ LANGUAGE ]['date_format_short'], $record->date ) . '<br>' . date( 'H:i', $record->date );
      }
      $processed[ (string) $record->_id ]->date_long = date($locales[ LANGUAGE ]['date_format'], $record->date);
      $processed[ (string) $record->_id ]->date_stamp = $record->date;
      $processed[ (string) $record->_id ]->img = $record->img;

      // convert MongoDB ObjectID into string, so it can be returned directly, not as a sub-object
      // when JSON-encoding the output value
      $processed[ (string) $record->_id ]['_id'] = (string) $record->_id;

      // remove probabilities from label predictions to save bandwidth
      // ... also, remove all <80% label probabilities
      $predictions_to_unset = [];
      if (!empty($processed[ (string) $record->_id ]->label_predictions)) {
        foreach ($processed[ (string) $record->_id ]->label_predictions as $prediction_key => $prediction_value) {
          if ($prediction_value->probability > 80) {
            $processed[ (string) $record->_id ]->label_predictions[ $prediction_key ] = [
              'id'    => (string) $prediction_value->id,
              'label' => $prediction_value->label
            ];

            // if this is a 110% prediction word, mark it as such
            $processed[ (string) $record->_id ]->label_predictions[ $prediction_key ]['trust'] = 1;
          } else {
            $predictions_to_unset[] = $prediction_key;
          }
        }
      }

      // unset any unwanted predictions
      foreach ($predictions_to_unset as $prediction_key) {
        unset($processed[ (string) $record->_id ]['label_predictions'][ $prediction_key ]);
      }

      // if there are any labels assigned to this item,
      // make sure we return ID and name for them
      if (!empty($processed[ (string) $record->_id ]->labels)) {
        $labels_array_new = [];
        foreach ( $processed[ (string) $record->_id ]->labels as $label ) {
          $labels_array_new[] = [
            'id'    => (string) $label,
            'label' => $cached_labels[ (string) $label]->label
          ];
        }

        $processed[ (string) $record->_id ]->labels = $processed[ (string) $record->_id ]['labels'] = $labels_array_new;
      }
    }

    // if we're paginating to previous page, we need to reverse our result,
    // so items can be added from first to last to the top content section
    if (!empty($_POST['way']) && $_POST['way'] == 'up') {
      $processed = array_reverse($processed);
    }
  }