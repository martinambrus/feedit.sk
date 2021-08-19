<?php
  function train_link( $feed_object, $link, $link_data, $item_lang, $adjustment_phrases, $rate, $rate_previous = false ) {
    global $mongo, $user, $scoring_adjustments;

    if (!is_array($scoring_adjustments)) {
      load_feed_score_adjustments( $feed_object );
    }

    $unsets                                           = []; // things to unset, if neede  d
    $words                                            = parse_words( $link_data['title'], $item_lang );
    $word_ids                                         = [];
    $score                                            = calculate_score( $feed_object, $user->short_id, $words, $link_data['_id'], $rate, true, $rate_previous );
    $words_count                                      = count( $score['words_details'] );
    $link_data['score']                               = $score['score']; // the actual final score for this link
    $link_data['score_increment_from_ngrams']         = $score['score_increment_from_ngrams'];
    $link_data['score_increment_from_ngrams_percent'] = ( $score['score_increment_from_ngrams'] ? ( ( $score['score_increment_from_ngrams'] / $score['score'] ) * 100 ) : 0 );
    $link_data['zero_scored_words']                   = $score['zero_scored_words'];
    $link_data['zero_scored_words_rated']             = $score['zero_scored_words_rated'];
    $link_data['zero_rated_scored_words_percentage']  = ( $words_count ? ( ( $link_data['zero_scored_words_rated'] / $words_count ) * 100 ) : 0 );
    $average_calculation_items_counted                = 0; // number of all items (words, authors, categories, adjustment phrases)
                                                           // that we have a valid average calculated for, i.e. an average that would not be
                                                           // solely calculated from non-rated words/authors/categories
  
    // calculate average user interest for words in percent
    $link_data['words_interest_average_percent'] = 0;
    $link_data['words_interest_total_percent']   = 0;
    $link_data['words_rated_above_50_percent']   = 0;
    $processed_words                             = 0; // contains number of words that were actually rated at least once,
                                                      // so our percentage average gets calculated correctly
    foreach ( $score['words_details'] as $word => $word_data ) {
      if ( ! isset( $word_data['ignored'] ) || ! $word_data['ignored'] ) {
        $is_valid_average_word = ( isset( $word_data['weightings'] ) && $word_data['weightings'] > 0 );
        $word_percentage       = ( $is_valid_average_word ? ( ( $word_data['weight_raw'] / $word_data['weightings'] ) * 100 ) : 0 );
  
        $link_data['words_interest_average_percent'] += ($word_percentage * $word_data['count']);
        $link_data['words_interest_total_percent']   += ($word_percentage * $word_data['count']);
  
        if ( $is_valid_average_word ) {
          $word_ids[] = new MongoDB\BSON\ObjectId( $word_data['_id'] );
          $processed_words += $word_data['count'];
  
          if ($word_percentage >= 50 && $word_data['weightings'] > 2 && (!is_numeric( $word ) || $scoring_adjustments['number'])) {
            $link_data['words_rated_above_50_percent'] += $word_data['count'];
          }
        } else if ( $rate_previous !== false ) {
          $word_ids[] = new MongoDB\BSON\ObjectId( $word_data['_id'] );
        }
      }
    }
  
    if ( $processed_words ) {
      $link_data['words_interest_average_percent'] /= $processed_words;
      $average_calculation_items_counted ++;
    }
  
    // update this calculation if we have any valid processed words
    // or if we're un-training
    if ( $processed_words || $rate_previous !== false ) {
      adjust_percentages_and_score( [ 'words' => [ '$in' => $word_ids ] ] );
    }
  
    $link_data['words_interest_count'] = $processed_words;
  
    // unset any label predictions we may have for this trained link
    $unsets['label_predictions'] = '';
  
    // if we've given labels to this link and we've rated positively, train them here
    // ... only positive rating is counted towards our label's words, since we want to be
    //     guessing labels only for items that the user actually wants to see, as they are
    //     visually well spotted - which makes it counterproductive to point attention to unwanted content
    if ( $rate === 1 && !empty($link_data['labels']) ) {
      train_link_labels( $link_data['labels'], $score['words_details'] );
    }
  
    // adjust score for any phrases manually input by the user
    $lowercase_title          = mb_strtolower( $link_data['title'] );
    $adjustment_phrases_found = 0;
    if ( count( $adjustment_phrases ) ) {
      foreach ( $adjustment_phrases as $phrase => $phrase_weight ) {
        if ( strpos( $lowercase_title, $phrase ) !== false ) {
          $link_data['score'] += ($rate_previous === false ? $phrase_weight : -$phrase_weight);
          $adjustment_phrases_found ++;
        }
      }
    }
  
    if ( $adjustment_phrases_found ) {
      $average_calculation_items_counted ++;
    }
  
    // adjust score for potential weight from rated authors
    if ($rate_previous === false) {
      $link_data['author_interest_average_percent'] = 0;
      $link_data['author_interest_average_count']   = 0;
    }
  
    if ( isset( $link_data['author'] ) ) {
      // update and add this author's rating into the final link score
      $author_rating_increase_value = ( ($rate === 1 || $rate === -1) ? 0.1 : 0 );
      $weight_increase              = ($rate_previous === 1 ? -$author_rating_increase_value : ($rate_previous === false ? $author_rating_increase_value : 0));

      try {
        $author_rating = $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->findOneAndUpdate( [
          'author'  => $link_data['author'],
          'feed'    => $feed_object,
          'ignored' => [ '$ne' => 1 ]
        ], [
          '$inc' => [
            'weight'     => $weight_increase,
            'weightings' => ( $rate_previous === false ? 1 : - 1 ),
          ]
        ],
          [ 'upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER ]
        );
      } catch ( MongoDB\Driver\Exception\BulkWriteException $ex ) {
        // if we get a duplicate key error, it would mean that  we're trying to upsert an author
        // that's being ignored, in which case MongoDB would try to
        // insert a new document, not finding the 'ignored' => [ '$ne' => 1 ] one
        // but there already is the same document with ignored set to 1 in the DB
        if ( $ex->getCode() == 11000 ) {
          // update the data to get the ignored author
          $author_rating = $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->findOne( [
            'author'  => $link_data['author'],
            'feed'    => $feed_object,
          ]);
        } else {
          // TODO: something went wrong while trying to insert the data, log this properly
          //var_dump($ex->getTraceAsString());
          //throw new \Exception( $ex );
          file_put_contents( 'logs.txt', $ex->getTraceAsString() . "\n", FILE_APPEND );
        }
      }
  
      // adjust score of all links with this author present
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'author' => $link_data['author'], 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $weight_increase ] ] );
  
      // calculate average user interest for author in percent
      if ( $author_rating ) {
        // this branch will be active when we're un-training a link
        if ($author_rating->weightings > 0) {
          $author_interest_percentage = ( ( $author_rating->weight / 0.1 ) / $author_rating->weightings ) * 100;
        } else {
          $author_interest_percentage = 0;
        }
      } else {
        // this branch will be active if we're training the link for the first time
        $author_interest_percentage = ( ( $weight_increase / 0.1 ) / 1 ) * 100;
      }
  
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'author' => $link_data['author'], 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
        [
          '$set' => [
            'author_interest_average_percent' => $author_interest_percentage,
            'author_interest_average_count'   => ($rate_previous === false ? 1 : 0)
          ]
        ]
      );
  
      $link_data['author_interest_average_percent'] = $author_interest_percentage;
      $link_data['author_interest_average_count']   = ($rate_previous === false ? 1 : 0);

      adjust_percentages_and_score( [ 'author' => $link_data['author'], 'feed' => $feed_object ] );
  
      // add to the actual score of this link
      $link_data['score']                           += ( $author_rating ? $author_rating->weight : $weight_increase );
      $link_data['author_interest_average_percent'] = ( $author_rating ? $author_interest_percentage : 100 );
      $average_calculation_items_counted ++;
    }
  
    // adjust score for any rated categories of this link
    $link_data['categories_interest_average_percent'] = 0;
    $link_data['categories_interest_total_percent']   = 0;
  
    if ( isset( $link_data['categories'] ) ) {
      // update and add all categories' rating into the final link score
      $category_rate_update_value = ( ($rate === 1 || $rate === -1) ? 0.01 : 0 );
      $weight_increase            = ($rate_previous === 1 ? -$category_rate_update_value : ($rate_previous === false ? $category_rate_update_value : 0));
  
      foreach ( $link_data['categories'] as $category ) {
        try {
          $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->updateOne(
            [
              'feed'     => $feed_object,
              'category' => $category,
              'ignored'  => [ '$ne' => 1 ]
            ],
            [
              '$inc' => [
                'weight'     => $weight_increase,
                'weightings' => ($rate_previous === false ? 1 : -1),
              ]
            ],
            [ 'upsert' => true ]
          );
        } catch ( MongoDB\Driver\Exception\BulkWriteException $ex ) {
          // we can safely ignore the duplicate key error,
          // as that can happen if we're trying to upsert a category
          // that's being ignored, in which case MongoDB would try to
          // insert a new document, not finding the 'ignored' => [ '$ne' => 1 ] one
          // but there already is the same document with ignored set to 1 in the DB
          if ( $ex->getCode() != 11000 ) {
            // TODO: something went wrong while trying to insert the data, log this properly
            //var_dump($ex->getTraceAsString());
            //throw new \Exception( $ex );
            file_put_contents( 'logs.txt', $ex->getTraceAsString() . "\n", FILE_APPEND );
          }
        }
  
        // increment score of all links where our link's category exist
        if ($weight_increase != 0) {
          $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $weight_increase ] ] );
        }
      }
  
      // re-select all categories in the DB, so we can use their current weight to adjust the final score
      $categories_score_increase_value = 0;
      $categories_processed            = 0;
      $categories_weighted_above_zero   = 0;
      $processed_category_names        = [];
      $categories_percentage_changed   = false;
      foreach (
        $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->find( [
          'feed'       => $feed_object,
          'category'   => [ '$in' => $link_data['categories'] ],
          'ignored'    => [ '$ne' => 1 ],
        ] ) as $category
      ) {
        $categories_processed ++;
  
        if ($category->weightings) {
          $categories_weighted_above_zero++;
        }
  
        $processed_category_names[] = $category->category;
  
        // add this category's weight into final score
        $categories_score_increase_value                           += $category->weight;
        $category_percentage                                       = ( ($category->weight && $category->weightings) ? ( ( ( $category->weight / 0.01 ) / $category->weightings ) * 100 ) : 0 );
        $category_interest_percentage_new                          = $category_percentage;
        $link_data['categories_interest_average_percent'] += $category_percentage;
        $link_data['categories_interest_total_percent']   += $category_percentage;
  
        if ( $rate_previous === false ) {
          // we're training this link
          $category_interest_percentage_old = ( $category->weightings > 1 ? ( ( ( ( $category->weight - $category_rate_update_value ) / 0.01 ) / ( $category->weightings - 1 ) ) * 100 ) : 0 );
        } else {
          // we're un-training this link
          $category_interest_percentage_old = ( ( ( ( $category->weight - $weight_increase ) / 0.01 ) / ( $category->weightings + 1 ) ) * 100 );
        }
  
        $category_interest_percentage_change = $category_interest_percentage_new - $category_interest_percentage_old;
  
        if ( $category_interest_percentage_change ) {
          $categories_percentage_changed = true;
        }
  
        $count_increment = ( $rate_previous !== false ? ( $category->weightings + 1 == 1 ? -1 : 0 ) : ( $category->weightings == 1 ? 1 : 0 ) );
        if ( $category_interest_percentage_change != 0 || $count_increment != 0 ) {
          $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
            [ 'categories' => $category->category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
            [
              [
                '$set' => [
                  'categories_interest_total_percent'   => [
                    '$add' => [
                      '$categories_interest_total_percent',
                      $category_interest_percentage_change
                    ]
                  ],
                  'categories_interest_count'           => [
                    '$add' => [
                      '$categories_interest_count',
                      $count_increment
                    ]
                  ],
                  'categories_interest_average_percent' => [
                    '$cond' => [
                      [
                        '$gt' => [
                          [
                            '$add' => [
                              '$categories_interest_count',
                              $count_increment
                            ]
                          ],
                          0
                        ]
                      ],
                      [
                        '$divide' => [
                          [
                            '$add' => [
                              '$categories_interest_total_percent',
                              $category_interest_percentage_change
                            ]
                          ],
                          [
                            '$add' => [
                              '$categories_interest_count',
                              $count_increment
                            ]
                          ]
                        ]
                      ],
                      0
                    ]
                  ]
                ]
              ]
            ]
          );
        }
      }
  
      // add to the actual score of this link
      $link_data['score'] += $categories_score_increase_value;
  
      if ( $categories_processed ) {
        $link_data['categories_interest_average_percent'] /= $categories_processed;
        $average_calculation_items_counted ++;
      }
  
      // if we've upvoted and categories percentage did not change,
      // there is no need to update total interest percentage
      if ( $categories_percentage_changed ) {
        adjust_percentages_and_score( [ 'categories' => [ '$in' => $processed_category_names ], 'feed' => $feed_object ] );
      }
  
      $link_data['categories_interest_count'] = $categories_weighted_above_zero;
  
    }
  
    // adjust words interest average percentage by any n-grams found for this link
    if ($link_data['score_increment_from_ngrams_percent'] && $processed_words) {
      $link_data['words_interest_average_percent'] += ($link_data['score_increment_from_ngrams_percent'] / $processed_words);
    }
  
    // get total average of all interest percentages, so we can filter by them (i.e. filter by tiers)
    if ( $average_calculation_items_counted ) {
      $link_data['interest_average_percent_total'] =
        ( ( $link_data['categories_interest_average_percent'] +
            $link_data['author_interest_average_percent'] +
            $link_data['words_interest_average_percent'] +
            ( (isset( $link_data['score_increment_from_adjustments'] ) && $link_data['score'] > 0) ? ( ( $link_data['score_increment_from_adjustments'] / $link_data['score'] ) * 100 ) : 0 ) ) / $average_calculation_items_counted );
    } else {
      $link_data['interest_average_percent_total'] = 0;
    }

    // calculate conformed score
    if ($link_data['interest_average_percent_total'] < 0 && $link_data['score'] < 0) {
      $link_data['score_conformed'] = ( abs( $link_data['interest_average_percent_total'] ) * $link_data['score'] );
    } else {
      $link_data['score_conformed'] = ( $link_data['interest_average_percent_total'] * $link_data['score'] );
    }

    $link_data['read'] = 1;
  
    // we're training this link
    if ($rate_previous === false) {
      $link_data['trained'] = 1;
      $link_data['rated']   = $rate;
    } else {
      // we're un-training this link
      $link_data['trained'] = 0;
      // set this to empty string, as our other queries
      // that update zero scored words will count with its existence
      $link_data['rated']   = '';
    }

    // remove description from data to save
    if (isset($link_data['description'])) {
      unset( $link_data['description'] );
    }

    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $link ) ], [ '$set' => $link_data ] );
  
    if ( count( $unsets ) ) {
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $link ) ], [ '$unset' => $unsets ] );
    }
  }

  // will train all given labels for the link in question
  function train_link_labels( $labels, $link_scored_words ) {
    global $mongo, $user;

    foreach ($labels as $label_id) {
      // update our words to include this label, if not present yet
      foreach ( $link_scored_words as $word => $word_data ) {
        // initialize array of labels
        if ( empty( $word_data['in_labels'] ) ) {
          $word_data['in_labels'] = [];
        }

        // check for label presence for this word
        $label_is_present = false;
        foreach ( $word_data['in_labels'] as $existing_label_id ) {
          if ( (string) $existing_label_id == (string) $label_id ) {
            $label_is_present = true;
            break;
          }
        }

        // label is not present for this word, add it
        if ( ! $label_is_present ) {
          $word_data['in_labels'][] = $label_id;
          $mongo->{MONGO_DB_NAME}->{'words-' . $user->short_id}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $word_data['_id'] ) ],
            [
              '$set' => [
                'in_labels' => $word_data['in_labels']
              ]
            ]
          );
        }
      }
    }
  }

  // updates total interest percentage value in DB based on the filter given
  // ... used when adjusting and ignoring words, authors, categories and adjustment phrases
  function update_total_interest_change_percentage($filter) {
    global $mongo, $user;

    // only perform calculations on unarchived items
    $filter['archived'] = [ '$ne' => 1 ];

    // update total interest change percentage
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
      $filter,
      [
        [
          '$set' => [
            'interest_average_percent_total' => [
              '$cond' => [
                [
                  '$gt' => [
                    [
                      '$add' => [
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$author_interest_average_count',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ],
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$categories_interest_count',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ],
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$words_interest_count',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ],
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$score_increment_from_adjustments',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ]
                      ]
                    ],
                    0
                  ]
                ],
                [
                  '$divide' => [
                    [
                      '$add' => [
                        '$author_interest_average_percent',
                        '$categories_interest_average_percent',
                        '$words_interest_average_percent',
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$score_increment_from_adjustments',
                                0
                              ]
                            ],
                            [
                              '$multiply' => [
                                [
                                  '$divide' => [
                                    '$score_increment_from_adjustments',
                                    '$score',
                                  ]
                                ],
                                100
                              ]
                            ],
                            0
                          ]
                        ]
                      ]
                    ],
                    [
                      '$add' => [
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$author_interest_average_count',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ],
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$categories_interest_count',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ],
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$words_interest_count',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ],
                        [
                          '$cond' => [
                            [
                              '$gt' => [
                                '$score_increment_from_adjustments',
                                0
                              ]
                            ],
                            1,
                            0
                          ]
                        ]
                      ]
                    ]
                  ],
                ],
                0
              ]
            ]
          ]
        ]
      ]
    );
  }

  function rate_link( $record, $rating ) {
    global $mongo, $user, $records, $last_feed_used, $scored_words, $scored_ngrams, $cached_authors, $cached_categories;

    // cache this feed's scoring data, if needed
    if ($last_feed_used != (string) (string) $record->feed) {
      // update last feed ID
      $last_feed_used = (string) $record->feed;

      // reset scored words and n-grams caches
      $scored_words = [];
      $scored_ngrams = [];
      $cached_authors = [];
      $cached_categories = [];

      // reset cache and load all scored authors for this user and this feed
      $scored_authors = [];
      foreach ( $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->find( [ 'feed' => $record->feed ] ) as $author ) {
        $scored_authors[ $author->author ] = $author;
      }

      // reset cache and load all scored categories for this user and this feed
      $scored_categories = [];
      foreach ( $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->find( [ 'feed' => $record->feed ] ) as $category ) {
        $scored_categories[ $category->category ] = $category;
      }
    }

    // cache feed data for this item's feed, if not cached yet
    if (!isset($cached_feed_data[ (string) $record->feed ])) {
      $cached_feed_data[ (string) $record->feed ] = $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->findOne(
        [
          '_id' => $record->feed
        ],
        [
          'projection' => [
            'lang' => 1,
            'adjustment_phrases' => 1,
          ],
        ]);
    }

    // update record with the data from global records array
    $record->description = $records[ (string) $record->_id ]->description;

    if (($rating == 1 || $rating == 0) && $record->trained) {
      // item is trained - check if not in the same way
      if ($record->rated == $rating) {
        // this shouldn't happen on the front-end but we're rating the same way,
        // so let's leave this one be and continue with the next one
        return;
      }

      // item is trained and we're training it in a different way,
      // we need to un-train it first
      train_link(
        $record->feed,
        (string) $record->_id,
        $record,
        $cached_feed_data[ (string) $record->feed ]->lang,
        (isset($cached_feed_data[ (string) $record->feed ]->adjustment_phrases) ? $cached_feed_data[ (string) $record->feed ]->adjustment_phrases : []),
        -1,
        $record->rated
      );
    }

    // now, train the link as requested
    if (($rating == 1 || $rating == 0)) {
      // we're TRAINING this link
      train_link(
        $record->feed,
        (string) $record->_id,
        $record,
        $cached_feed_data[ (string) $record->feed ]->lang,
        (isset($cached_feed_data[ (string) $record->feed ]->adjustment_phrases) ? $cached_feed_data[ (string) $record->feed ]->adjustment_phrases : []),
        $rating
      );
    } else {
      // we're UN-TRAINING this link, check if trained
      if (isset($record->rated) && $record->rated !== '') {
        train_link(
          $record->feed,
          (string) $record->_id,
          $record,
          $cached_feed_data[ (string) $record->feed ]->lang,
          ( isset( $cached_feed_data[ (string) $record->feed ]->adjustment_phrases ) ? $cached_feed_data[ (string) $record->feed ]->adjustment_phrases : [] ),
          -1,
          $record->rated
        );
      }
    }
  }

  function word_train_score( $word, $amount, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;

    if (!is_array($scoring_adjustments)) {
      load_feed_score_adjustments( $feed_object );
    }

    $old_record = $mongo->{MONGO_DB_NAME}->{'words-' . $user->short_id}->findOne( [ 'feed' => $feed_object, 'word' => $word ], [ 'projection' => [ 'weight' => 1, 'weightings' => 1, 'ignored' => 1, ] ] );

    // if the word was previously ignored, un-ignore it now
    if (isset($old_record->ignored) && $old_record->ignored) {
      word_train_unignore( $word, $feed_object );
    }

    // don't allow adjusting unrated words, as we cannot calculate percentage for them
    if ( $amount && $old_record->weightings ) {
      $word_data = $mongo->{MONGO_DB_NAME}->{'words-' . $user->short_id}->findOneAndUpdate( [ 'feed' => $feed_object, 'word' => $word ], [
        '$inc' => [
          'weight'     => $amount,
          'weight_raw' => $amount
        ]
      ], [ 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER ] );

      // update score for all links with this word scored in them
      // as well as percentage of score adjustments from ngrams
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ],
        [
          [
            '$set' => [
              'score' => [
                '$add' => [
                  '$score',
                  $amount
                ]
              ],
              'score_increment_from_ngrams_percent' => [
                '$cond' => [
                  [
                    '$gt' => [
                      '$score_increment_from_ngrams',
                      0
                    ]
                  ],
                  [
                    '$multiply' => [
                      [
                        '$divide' => [
                          '$score_increment_from_ngrams',
                          [
                            '$add' => [
                              '$score',
                              $amount
                            ]
                          ]
                        ]
                      ],
                      100
                    ]
                  ],
                  0
                ]
              ]
            ]
          ]
        ]
      );

      // calculate and update by how many percent did the potential user interest of this word increase / decrease
      $words_interest_average_percent_new    = ( ( $word_data->weight_raw / $word_data->weightings ) * 100 );
      $words_interest_average_percent_old    = ( ( ( $word_data->weight_raw - $amount ) / $word_data->weightings ) * 100 );

      // update count of words rated above 50% in links where this word is present
      // if its percentage dropped from 50+% to a value below that
      // note: we do this only for non-numeric words, unless they get priorized in this feed, as that would potentially generate
      //       an abundance of false positives for various auctions, trading feeds etc.
      if (!is_numeric( $word_data->word ) || $scoring_adjustments['number']) {
        if ( $words_interest_average_percent_old >= 50 && $words_interest_average_percent_new < 50 && $word_data->weightings > 2 ) {
          $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => - 1 ] ] );
        } else if ( $words_interest_average_percent_old < 50 && $words_interest_average_percent_new >= 50 && $word_data->weightings > 2 ) {
          // do the same update as above in reverse, if applicable
          $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => 1 ] ] );
        }
      }

      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ],
        [
          [
            '$set' => [
              'words_interest_total_percent' => [
                '$add' => [
                  [
                    '$add' => [
                      '$words_interest_total_percent',
                      - $words_interest_average_percent_old
                    ]
                  ],
                  $words_interest_average_percent_new
                ]
              ],
              'words_interest_average_percent' => [
                '$add' => [
                  [
                    '$divide' => [
                      '$score_increment_from_ngrams_percent',
                      '$words_interest_count'
                    ]
                  ],
                  [
                    '$cond' => [
                      [
                        '$gt' => [
                          '$words_interest_count',
                          0
                        ]
                      ],
                      [
                        '$divide' => [
                          [
                            '$add' => [
                              [
                                '$add' => [
                                  '$words_interest_total_percent',
                                  - $words_interest_average_percent_old
                                ]
                              ],
                              $words_interest_average_percent_new
                            ]
                          ],
                          '$words_interest_count'
                        ]
                      ],
                      0
                    ]
                  ]
                ]
              ]
            ]
          ]
        ]
      );

      // update total interest change percentage
      adjust_percentages_and_score( [ 'words' => $word_data->_id ] );
    }
  }

  function word_train_ignore( $word, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;

    if (!is_array($scoring_adjustments)) {
      load_feed_score_adjustments( $feed_object );
    }

    // update the word itself
    $old_record = $mongo->{MONGO_DB_NAME}->{'words-' . $user->short_id}->findOneAndUpdate( [ 'feed' => $feed_object, 'word' => $word ], [ '$set' => [ 'ignored' => 1 ] ] );

    // the word was already ignored before, bail out
    if (isset($old_record->ignored) && $old_record->ignored) {
      return;
    }

    // update all links with this word scored
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'words' => $old_record->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => - $old_record->weight ] ] );

    // update all ngrams with this word present
    $ngram_ids_to_update = [];
    $ngrams_to_look_for = $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user->short_id}->find(
      [
        '$and' =>
          [
            [
              'feed' => $feed_object,
            ],
            // first, narrow down results to those that contain our search word
            [
              '$text' => [
                '$search' => $old_record->word,
              ]
            ],
            // then use regex to search for the exact word position
            [
              'ngram' => [ '$regex' => '( ' . $old_record->word . '|' . $old_record->word . ' )' ],
            ],
          ],
      ],
      [
        'projection' => [
          '_id'        => 1,
          'ngram'      => 1,
          'weight'     => 1,
          'weightings' => 1
        ]
      ]);

    // update all links with the affected ngrams
    foreach ( $ngrams_to_look_for as $ngram ) {
      $ngram_ids_to_update[] = $ngram->_id;
      if (($ngram->weight > 25 && $ngram->weightings > 1)) {
        $ngram_words_count = count( explode(' ', $ngram->ngram) );
        $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'ngrams' => $ngram->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => - ($ngram->weight * $ngram_words_count), 'score_increment_from_ngrams' => - ($ngram->weight * $ngram_words_count) ] ] );
      }
    }

    // set all ngrams found as ignored
    if (count($ngram_ids_to_update)) {
      // recalculate n-grams score increment total percentage
      recalculate_ngrams_total_percentage( $ngram_ids_to_update );

      // set n-grams as ignored
      $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user->short_id}->updateMany( [ '_id' => [ '$in' => $ngram_ids_to_update ] ], [ '$set' => [ 'ignored' => 1 ] ] );
    }

    // update words interest percentage value for all links where this word exists
    if ($old_record->weight_raw) {
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'words' => $old_record->_id, 'archived' => [ '$ne' => 1 ] ],
        [
          [
            '$set' => [
              'words_interest_total_percent' => [
                '$add' => [
                  '$words_interest_total_percent',
                  - ( ( $old_record->weight_raw / $old_record->weightings ) * 100 )
                ]
              ],
              'words_interest_count' => [
                '$add' => [
                  '$words_interest_count',
                  -1
                ]
              ],
              'words_interest_average_percent' => [
                '$cond' => [
                  [
                    '$gt' => [
                      [
                        '$add' => [
                          '$words_interest_count',
                          -1
                        ]
                      ],
                      0
                    ]
                  ],
                  [
                    '$add' => [
                      [
                        '$divide' => [
                          '$score_increment_from_ngrams_percent',
                          [
                            '$add' => [
                              '$words_interest_count',
                              -1
                            ]
                          ]
                        ]
                      ],
                      [
                        '$divide' => [
                          [
                            '$add' => [
                              '$words_interest_total_percent',
                              - ( ( $old_record->weight_raw / $old_record->weightings ) * 100 )
                            ]
                          ],
                          [
                            '$add' => [
                              '$words_interest_count',
                              -1
                            ]
                          ]
                        ]
                      ]
                    ]
                  ],
                  0
                ]
              ]
            ]
          ]
        ]
      );

      // update total interest change percentage
      adjust_percentages_and_score( [ 'words' => $old_record->_id ] );

      // update count of words rated above 50% in links where this word is present if needed
      if ($old_record->weightings) {
        $words_interest_average_percent = ( ( $old_record->weight_raw / $old_record->weightings ) * 100 );

        if ( $words_interest_average_percent >= 50 && $old_record->weightings > 2 && (!is_numeric( $old_record->word ) || $scoring_adjustments['number']) ) {
          $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'words' => $old_record->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => - 1 ] ] );
        }
      }
    }
  }

  function word_train_unignore($word, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;

    if (!is_array($scoring_adjustments)) {
      load_feed_score_adjustments( $feed_object );
    }

    // update the word itself
    $old_record = $mongo->{MONGO_DB_NAME}->{'words-' . $user->short_id}->findOneAndUpdate( [ 'feed' => $feed_object, 'word' => $word ], [ '$unset' => [ 'ignored' => '' ] ] );

    // the word was already unignored before, bail out
    if (isset($old_record->ignored) && !$old_record->ignored) {
      return;
    }

    // update all links with this word scored
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'words' => $old_record->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $old_record->weight ] ] );

    // update all ngrams with this word present
    $ngram_ids_to_update = [];
    $ngrams_to_look_for = $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user->short_id}->find(
      [
        '$and' =>
          [
            [
              'feed' => $feed_object,
            ],
            // first, narrow down results to those that contain our search word
            [
              '$text' => [
                '$search' => $old_record->word,
              ]
            ],
            // then use regex to search for the exact word position
            [
              'ngram' => [ '$regex' => '( ' . $old_record->word . '|' . $old_record->word . ' )' ],
            ],
          ],
      ],
      [
        'projection' => [
          '_id'        => 1,
          'ngram'      => 1,
          'weight'     => 1,
          'weightings' => 1
        ]
      ]);

    // update all links with the affected ngrams
    foreach ( $ngrams_to_look_for as $ngram ) {
      $ngram_ids_to_update[] = $ngram->_id;
      if (($ngram->weight > 25 && $ngram->weightings > 1)) {
        $ngram_words_count = count( explode(' ', $ngram->ngram) );
        $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'ngrams' => $ngram->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => ($ngram->weight * $ngram_words_count), 'score_increment_from_ngrams' => ($ngram->weight * $ngram_words_count) ] ] );
      }
    }

    // set all ngrams found as unignored
    if (count($ngram_ids_to_update)) {
      // recalculate n-grams score increment total percentage
      recalculate_ngrams_total_percentage( $ngram_ids_to_update );

      // set n-grams as ignored
      $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user->short_id}->updateMany( [ '_id' => [ '$in' => $ngram_ids_to_update ] ], [ '$unset' => [ 'ignored' => '' ] ] );
    }

    // update words interest percentage value for all links where this word exists
    if ($old_record->weight_raw) {
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'words' => $old_record->_id, 'archived' => [ '$ne' => 1 ] ],
        [
          [
            '$set' => [
              'words_interest_total_percent' => [
                '$add' => [
                  '$words_interest_total_percent',
                  ( ( $old_record->weight_raw / $old_record->weightings ) * 100 )
                ]
              ],
              'words_interest_count' => [
                '$add' => [
                  '$words_interest_count',
                  1
                ]
              ],
              'words_interest_average_percent' => [
                '$add' => [
                  [
                    '$divide' => [
                      '$score_increment_from_ngrams_percent',
                      [
                        '$add' => [
                          '$words_interest_count',
                          1
                        ]
                      ]
                    ]
                  ],
                  [
                    '$divide' => [
                      [
                        '$add' => [
                          '$words_interest_total_percent',
                          ( ( $old_record->weight_raw / $old_record->weightings ) * 100 )
                        ]
                      ],
                      [
                        '$add' => [
                          '$words_interest_count',
                          1
                        ]
                      ]
                    ]
                  ]
                ]
              ]
            ]
          ]
        ]
      );

      // update total interest change percentage
      adjust_percentages_and_score( [ 'words' => $old_record->_id ] );

      // update count of words rated above 50% in links where this word is present if needed
      if ($old_record->weightings) {
        $words_interest_average_percent = ( ( $old_record->weight_raw / $old_record->weightings ) * 100 );

        if ( $words_interest_average_percent >= 50 && $old_record->weightings > 2 && (!is_numeric( $old_record->word ) || $scoring_adjustments['number']) ) {
          $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'words' => $old_record->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => 1 ] ] );
        }
      }
    }
    
  }

  function category_train_score( $category, $amount, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;

    if ( ! is_array( $scoring_adjustments ) ) {
      load_feed_score_adjustments( $feed_object );
    }

    $old_record = $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->findOne( [ 'category' => $category, 'feed' => $feed_object ], [ 'projection' => [ 'weight' => 1, 'weightings' => 1, 'ignored' => 1, ] ] );

    // if this category was previously ignored, un-ignore it now
    if (isset($old_record->ignored) && $old_record->ignored) {
      category_train_unignore( $category, $feed_object );
    }

    // don't allow adjusting unrated categories, as we cannot calculate percentage for them
    if ( $amount && $old_record->weightings ) {
      $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->updateOne( [ 'category' => $category, 'feed' => $feed_object ], [ '$inc' => [ 'weight' => $amount ] ] );

      // update all links with this category
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $amount ] ] );

      $category_interest_percentage_new    = ( ( ( ( ($old_record->weight + $amount) / 0.01) ) / $old_record->weightings ) * 100 );
      $category_interest_percentage_old    = ( ( ( $old_record->weight / 0.01 ) / $old_record->weightings ) * 100 );

      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
        [
          [
            '$set' => [
              'categories_interest_total_percent' => [
                '$add' => [
                  [
                    '$add' => [
                      '$categories_interest_total_percent',
                      - $category_interest_percentage_old
                    ]
                  ],
                  $category_interest_percentage_new
                ]
              ],
              'categories_interest_average_percent' => [
                '$cond' => [
                  [
                    '$gt' => [
                      '$categories_interest_count',
                      0
                    ]
                  ],
                  [
                    '$divide' => [
                      [
                        '$add' => [
                          [
                            '$add' => [
                              '$categories_interest_total_percent',
                              - $category_interest_percentage_old
                            ]
                          ],
                          $category_interest_percentage_new
                        ]
                      ],
                      '$categories_interest_count'
                    ]
                  ],
                  0
                ]
              ]
            ]
          ]
        ]
      );

      // update total interest change percentage
      adjust_percentages_and_score([ 'categories' => $category, 'feed' => $feed_object ]);
    }
  }

  function category_train_ignore($category, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;
  
    if ( ! is_array( $scoring_adjustments ) ) {
      load_feed_score_adjustments( $feed_object );
    }

    $old_record = $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->findOneAndUpdate( [ 'category' => $category, 'feed' => $feed_object ], [ '$set' => [ 'ignored' => 1 ] ] );

    // category was already ignored before, bail out
    if (isset($old_record->ignored) && $old_record->ignored) {
      return;
    }

    // update all links with this category
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => - $old_record->weight ] ] );

    // update categories interest percentage value for all links where this category exists
    if ($old_record->weight) {
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
        [
          [
            '$set' => [
              'categories_interest_total_percent' => [
                '$add' => [
                  '$categories_interest_total_percent',
                  - ( ( ( ( $old_record->weight / 0.01) ) / $old_record->weightings ) * 100 )
                ]
              ],
              'categories_interest_count' => [
                '$add' => [
                  '$categories_interest_count',
                  -1
                ]
              ],
              'categories_interest_average_percent' => [
                '$cond' => [
                  [
                    '$gt' => [
                      [
                        '$add' => [
                          '$categories_interest_count',
                          -1
                        ]
                      ],
                      0
                    ]
                  ],
                  [
                    '$divide' => [
                      [
                        '$add' => [
                          '$categories_interest_total_percent',
                          - ( ( ( ( $old_record->weight / 0.01) ) / $old_record->weightings ) * 100 )
                        ]
                      ],
                      [
                        '$add' => [
                          '$categories_interest_count',
                          -1
                        ]
                      ]
                    ]
                  ],
                  0
                ]
              ]
            ]
          ]
        ]
      );

      // update total interest change percentage
      adjust_percentages_and_score([ 'categories' => $category, 'feed' => $feed_object ]);
    }
  }

  function category_train_unignore($category, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;
  
    if ( ! is_array( $scoring_adjustments ) ) {
      load_feed_score_adjustments( $feed_object );
    }

    $old_record = $mongo->{MONGO_DB_NAME}->{'categories-' . $user->short_id}->findOneAndUpdate( [ 'category' => $category, 'feed' => $feed_object ], [ '$unset' => [ 'ignored' => '' ] ] );

    // category was already unignored before, bail out
    if (isset($old_record->ignored) && !$old_record->ignored) {
      return;
    }

    // update all links with this category
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $old_record->weight ] ] );

    // update categories interest percentage value for all links where this category exists
    if ($old_record->weight) {
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
        [
          [
            '$set' => [
              'categories_interest_total_percent' => [
                '$add' => [
                  '$categories_interest_total_percent',
                  ( ( ( ( $old_record->weight / 0.01) ) / $old_record->weightings ) * 100 )
                ]
              ],
              'categories_interest_count' => [
                '$add' => [
                  '$categories_interest_count',
                  1
                ]
              ],
              'categories_interest_average_percent' => [
                '$divide' => [
                  [
                    '$add' => [
                      '$categories_interest_total_percent',
                      ( ( ( ( $old_record->weight / 0.01) ) / $old_record->weightings ) * 100 )
                    ]
                  ],
                  [
                    '$add' => [
                      '$categories_interest_count',
                      1
                    ]
                  ]
                ]
              ],
            ]
          ]
        ]
      );

      // update total interest change percentage
      adjust_percentages_and_score( [ 'categories' => $category, 'feed' => $feed_object ] );
    }
  }

  function phrase_train_score( $phrase, $amount, $feed_object ) {
    global $mongo, $user, $scoring_adjustments, $cached_feed_data;
  
    if ( ! is_array( $scoring_adjustments ) ) {
      load_feed_score_adjustments( $feed_object );
    }

    $phrase = trim( $phrase );

    // cache feed data for this item's feed, if not cached yet
    if (!isset($cached_feed_data[ (string) $feed_object ])) {
      $cached_feed_data[ (string) $feed_object ] = $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->findOne(
        [
          '_id' => $feed_object
        ],
        [
          'projection' => [
            'lang' => 1,
            'adjustment_phrases' => 1,
          ],
        ]);
    }

    if ( ! isset( $cached_feed_data[ (string) $feed_object ]->adjustment_phrases ) ) {
      $cached_feed_data[ (string) $feed_object ]->adjustment_phrases = [];
    }

    if ( ! isset( $cached_feed_data[ (string) $feed_object ]->adjustment_phrases[ $phrase ] ) ) {
      $cached_feed_data[ (string) $feed_object ]->adjustment_phrases[ $phrase ] = $amount;
    } else {
      $cached_feed_data[ (string) $feed_object ]->adjustment_phrases[ $phrase ] += $amount;
    }

    if ( $amount ) {
      // update score of all links that this phrase is present in
      // first, select IDs of all such links from the global processed collection
      $ids = [];
      foreach ($mongo->{MONGO_DB_NAME}->processed->find([
        '$and' => [
          [
            'feed' => $feed_object,
          ],
          [
            'archived' => [ '$ne' => 1 ]
          ],
          // first, narrow down results to those that contain any of our search words
          [
            '$text' => [
              '$search' => $phrase,
            ]
          ],
          // then use regex to search for the exact phrase
          [
            '$or' => [
              [
                'title' => [
                  '$regex'   => $phrase,
                  '$options' => 'im'
                ]
              ],
              [
                'description' => [
                  '$regex'   => $phrase,
                  '$options' => 'im'
                ]
              ]
            ],
          ],
        ]
      ], [
        'projection' => [ '_id' => 1 ]
      ]) as $record) {
        $ids[] = $record->_id;
      }

      // now update these records
      if (count($ids)) {
        $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ '_id' => [ '$in' => $ids ] ], [
          '$inc' => [
            'score' => $amount,
            'score_increment_from_adjustments' => $amount
          ]
        ] );

        // update total interest change percentage
        adjust_percentages_and_score([ '_id' => [ '$in' => $ids ] ]);
      }

      // update the actual phrase
      $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->updateOne( [ '_id' => $feed_object ], [ '$set' => [ 'adjustment_phrases' => $cached_feed_data[ (string) $feed_object ]->adjustment_phrases ] ] );
    }
  }

  function phrase_train_remove($phrase, $feed_object ) {
    global $mongo, $user, $scoring_adjustments, $cached_feed_data;
  
    if ( ! is_array( $scoring_adjustments ) ) {
      load_feed_score_adjustments( $feed_object );
    }

    // cache feed data for this item's feed, if not cached yet
    if (!isset($cached_feed_data[ (string) $feed_object ])) {
      $cached_feed_data[ (string) $feed_object ] = $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->findOne(
        [
          '_id' => $feed_object
        ],
        [
          'projection' => [
            'lang' => 1,
            'adjustment_phrases' => 1,
          ],
        ]);
    }

    if ( isset( $cached_feed_data[ (string) $feed_object ]->adjustment_phrases ) && isset( $cached_feed_data[ (string) $feed_object ]->adjustment_phrases[ $phrase ] ) ) {
      // update all links that have this phrase present
      $update_links_by = $cached_feed_data[ (string) $feed_object ]->adjustment_phrases[ $phrase ];
      if ( $update_links_by != 0 ) {
        // first, select IDs of all such links from the global processed collection
        $ids = [];
        foreach ($mongo->{MONGO_DB_NAME}->processed->find([
          '$and' => [
            [
              'feed' => $feed_object,
            ],
            [
              'archived' => [ '$ne' => 1 ]
            ],
            // first, narrow down results to those that contain any of our search words
            [
              '$text' => [
                '$search' => $phrase,
              ]
            ],
            // then use regex to search for the exact phrase
            [
              '$or' => [
                [
                  'title' => [
                    '$regex'   => $phrase,
                    '$options' => 'im'
                  ]
                ],
                [
                  'description' => [
                    '$regex'   => $phrase,
                    '$options' => 'im'
                  ]
                ]
              ],
            ],
          ]
        ], [
          'projection' => [ '_id' => 1 ]
        ]) as $record) {
          $ids[] = $record->_id;
        }

        // now update these records
        if (count($ids)) {
          $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ '_id' => [ '$in' => $ids ] ], [
            '$inc' => [
              'score' => - $update_links_by,
              'score_increment_from_adjustments' => - $update_links_by
            ]
          ] );

          // update total interest change percentage
          adjust_percentages_and_score( [ '_id' => [ '$in' => $ids ] ] );
        }
      }

      // remove the actual phrase from the feed
      unset( $cached_feed_data[ (string) $feed_object ]->adjustment_phrases[ $phrase ] );
      $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->updateOne( [ '_id' => $feed_object ], [ '$set' => [ 'adjustment_phrases' => $cached_feed_data[ (string) $feed_object ]->adjustment_phrases ] ] );
    }
  }

  function author_train_score( $author, $amount, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;
  
    if ( ! is_array( $scoring_adjustments ) ) {
      load_feed_score_adjustments( $feed_object );
    }

    $old_record = $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->findOne( [ 'author' => $author, 'feed' => $feed_object ], [ 'projection' => [ 'weight' => 1, 'weightings' => 1, 'ignored' => 1, ] ] );

    // if the author was previously ignored, un-ignore it now
    if (isset($old_record->ignored) && $old_record->ignored) {
      author_train_unignore( $author, $feed_object );
    }

    // don't allow adjusting unrated authors, as we cannot calculate percentage for them
    if ( $amount && $old_record->weightings ) {
      $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->updateOne( [ 'author' => $author, 'feed' => $feed_object ], [ '$inc' => [ 'weight' => $amount ] ] );

      // update all links with this author
      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'author' => $author, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $amount ] ] );

      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
        [ 'author' => $author, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
        [
          '$set' => [ 'author_interest_average_percent' => (( (($old_record->weight + $amount) / 0.1) / $old_record->weightings ) * 100)]
        ]
      );

      // update total interest change percentage
      adjust_percentages_and_score([ 'author' => $author, 'feed' => $feed_object ]);
    }
  }

  function author_train_ignore( $author, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;
  
    if (!is_array($scoring_adjustments)) {
      load_feed_score_adjustments( $feed_object );
    }

    $old_record = $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->findOneAndUpdate( [ 'author' => $author, 'feed' => $feed_object ], [ '$set' => [ 'ignored' => 1 ] ]);

    // author was already ignored before, bail out
    if (isset($old_record->ignored) && $old_record->ignored) {
      return;
    }

    // update all links with this author
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'author' => $author, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        '$set' => [
          'author_interest_average_percent' => 0
        ] ,
        '$inc' => [
          'score' => - $old_record->weight
        ]
      ]
    );

    // decrease interest average count for this author in all links where they're present
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'author' => $author, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        [
          '$set' => [
            'author_interest_average_count' => [
              '$add' => [
                '$author_interest_average_count',
                -1
              ]
            ]
          ]
        ]
      ]
    );

    // update total interest change percentage
    adjust_percentages_and_score([ 'author' => $author, 'feed' => $feed_object ]);
  }

  function author_train_unignore($author, $feed_object ) {
    global $mongo, $user, $scoring_adjustments;

    if ( ! is_array( $scoring_adjustments ) ) {
      load_feed_score_adjustments( $feed_object );
    }

    $old_record = $mongo->{MONGO_DB_NAME}->{'authors-' . $user->short_id}->findOneAndUpdate( [ 'author' => $author, 'feed' => $feed_object ], [ '$unset' => [ 'ignored' => '' ] ] );

    // author was already unignored before, bail out
    if (isset($old_record->ignored) && !$old_record->ignored) {
      return;
    }

    // update all links with this author
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'author' => $author, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        '$set' => [
          'author_interest_average_percent' => (( ($old_record->weight / 0.1) / $old_record->weightings ) * 100)
        ],
        '$inc' => [
          'score' => $old_record->weight
        ]
      ]
    );

    // increase interest average count for this author in all links where they're present
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'author' => $author, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        [
          '$set' => [
            'author_interest_average_count' => [
              '$add' => [
                '$author_interest_average_count',
                1
              ]
            ]
          ]
        ]
      ]
    );

    // update total interest change percentage
    adjust_percentages_and_score([ 'author' => $author, 'feed' => $feed_object ]);
  }