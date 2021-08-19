<?php

  // calls recalculate_conformed_score and update_total_interest_change_percentage with the same filter
  // ... a shortcut for places where these 2 are called together (which is a lot)
  function adjust_percentages_and_score($filter) {
    update_total_interest_change_percentage( $filter );
    recalculate_conformed_score( $filter );
  }

  // recalculates conformed score for all the records determined by the given filter
  function recalculate_conformed_score($filter) {
    global $mongo, $user;

    // only perform calculations on unarchived items
    $filter['archived'] = [ '$ne' => 1 ];

    // update total interest change percentage
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany(
      $filter,
      [
        [
          '$set' => [
            'score_conformed' => [
              '$multiply' => [
                [
                  // if the interest is negative (in case of manually scored words), we need to prevent
                  // multiplying negative number with a negative number or we'd get a positive one
                  '$cond' => [
                    [
                      '$and' => [
                        [
                          '$lt' => [
                            '$interest_average_percent_total',
                            0
                          ]
                        ],
                        [
                          '$lt' => [
                            '$score',
                            0
                          ]
                        ]
                      ]
                    ],
                    [
                      '$abs' => '$interest_average_percent_total'
                    ],
                    '$interest_average_percent_total',
                  ],
                ],
                '$score',
              ]
            ]
          ]
        ]
      ]
    );
  }

  function recalculate_ngrams_total_percentage( $ngram_ids ) {
    global $mongo, $user;
    // recalculate n-grams score increment total percentage
    $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->updateMany( [ 'ngrams' => [ '$in' => $ngram_ids ], 'archived' => [ '$ne' => 1 ] ],
      [
        [
          '$set' => [
            'score_increment_from_ngrams_percent' => [
              '$cond' => [
                [
                  '$gt' => [
                    '$score',
                    0,
                  ]
                ],
                [
                  '$multiply' => [
                    [
                      '$divide' => [
                        '$score_increment_from_ngrams',
                        '$score'
                      ]
                    ],
                    100
                  ],
                ],
                0,
              ],
            ]
          ],
        ]
      ]
    );
  }

function load_feed_score_adjustments( $feed_object ) {
  global $mongo, $user, $scoring_adjustments;

  // build indexes of our potential feed-wide scoring adjustments
  $scoring_adjustments = [
    'word'             => 0,
    'number'           => 0,
    'measurement_unit' => 0
  ];

  $feed_data = $mongo->{MONGO_DB_NAME}->{'feeds-' . $user->short_id}->findOne([ '_id' => $feed_object ]);

  if ( $feed_data && isset( $feed_data['scoring_priority'] ) ) {
    $priorities_count = count( $feed_data['scoring_priority'] );

    if ( ( $index = array_search( 'word', (array) $feed_data['scoring_priority'] ) ) !== false ) {
      $scoring_adjustments['word'] = $priorities_count - ++$index + 1;
    }

    if ( ( $index = array_search( 'number', (array) $feed_data['scoring_priority'] ) ) !== false ) {
      $scoring_adjustments['number'] = $priorities_count - ++$index + 1;
    }

    if ( ( $index = array_search( 'measurement_unit', (array) $feed_data['scoring_priority'] ) ) !== false ) {
      $scoring_adjustments['measurement_unit'] = $priorities_count - ++$index + 1;
    }
  }

  return $feed_data;
}

function calculate_score( $feed_object, $user_id, $words, $link, $rate = false, $update_db = false, $rate_previous = false ) {
  global $mongo, $measurement_units_array, $scoring_adjustments, $scored_words, $scored_ngrams;

  $detailed                          = [];
  $score                             = 0;
  $score_increment_from_ngrams_total = 0;
  $zero_scored_words                 = 0;  // words with score of 0, used to populate untrained links in the front-end
                                           // where downvoted links are displayed below links that were upvoted
  $zero_scored_words_rated           = 0;  // rated words with score of 0, used to filter out links that have 60+% words that are unwanted
  $words_counter                     = []; // holds number of instances for each word in the title

  // load a list of words for which score
  // we should get from the DB (as it's not cached)
  $words_to_get = [];
  $skip_next    = false;
  foreach ( $words[0] as $index => $w ) {
    if ( $skip_next ) {
      $skip_next = false;
      continue;
    }

    // check for a measurement unit word
    if ( is_numeric( $w ) && isset( $words[0][ $index + 1 ] ) && in_array( $words[0][ $index + 1 ], $measurement_units_array ) ) {
      $w         = $w . $words[0][ $index + 1 ];
      $skip_next = true;
    }

    if ( ! isset( $scored_words[ $w ] ) ) {
      $words_to_get[] = $w;
    }
  }

  // cache score for our new words
  if ( count( $words_to_get ) ) {
    foreach (
      $mongo->{MONGO_DB_NAME}->{'words-' . $user_id}->find( [
        'feed' => $feed_object,
        'word' => [ '$in' => $words_to_get ]
      ] ) as $record
    ) {
      $scored_words[ $record->word ] = $record;
    }
  }

  // load a list of n-grams for which score
  // we should get from the DB (as it's not cached)
  $ngrams_to_get = [];
  foreach ( $words[1] as $n ) {
    if ( ! isset( $scored_ngrams[ $n ] ) ) {
      $ngrams_to_get[] = $n;
    }
  }

  // cache score for our new n-grams
  if ( count( $ngrams_to_get ) ) {
    foreach (
      $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user_id}->find( [
        'feed'  => $feed_object,
        'ngram' => [ '$in' => $ngrams_to_get ]
      ] ) as $record
    ) {
      $scored_ngrams[ $record->ngram ] = $record;
    }
  }

  // update n-grams in DB
  if ( $update_db && count( $words[1] ) ) {
    foreach ( $words[1] as $ngram ) {
      $ngram_words       = explode( ' ', $ngram );
      $ngram_words_count = count( $ngram_words );
      $skip_ngram        = false;

      foreach ( $ngram_words as $ngram_word ) {
        if ( ! isset( $scored_words[ $ngram_word ] ) ) {
          $skip_ngram = true;
        }
      }

      if ( $skip_ngram ) {
        continue;
      }

      $updateQuery = [
        'ngram' => $ngram,
        'feed'  => $feed_object
      ];

      $updateFields = [
        '$set' => [
          'ngram' => $ngram,
          'feed'  => $feed_object
        ],
        '$inc' => [
          'weight'     => 0,
          'weightings' => (( isset( $scored_words[ $ngram_word ] ) && isset( $scored_words[ $ngram_word ]['ignored'] ) && $scored_words[ $ngram_word ]['ignored'] ) ? 0 : 1)
        ]
      ];

      // 1 n-gram word has an added weight of ((n-gram-words-count - 2) * 25) to make n-grams more prominent when scoring
      if ( !isset( $scored_words[ $ngram_word ]['ignored'] ) || !$scored_words[ $ngram_word ]['ignored'] ) {
        if ( $rate === 1 ) {
          $updateFields['$inc']['weight'] = ( ( $ngram_words_count - 2 ) * 25 );
        } else if ( $rate === false ) {
          // don't update weightings if we're not rating but inserting new n-grams into the DB
          $updateFields['$inc']['weightings'] = 0;
        } else if ($rate === -1) {
          // we're un-training this link, decrease weight and weightings
          $updateFields['$inc']['weightings'] = -1;

          // only update weight if this link was rated 1 before, otherwise weight did not previously change
          if ($rate_previous === 1) {
            $updateFields['$inc']['weight'] = - ( ( $ngram_words_count - 2 ) * 25 );
          }
        }
      }

      // update or insert a new n-gram with new value
      $ngram_data = $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user_id}->findOneAndUpdate( $updateQuery, $updateFields, [
        'upsert'         => true,
        'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
      ] );

      if ( $ngram_data === null ) {
        $ngram_data = $mongo->{MONGO_DB_NAME}->{'ngrams-' . $user_id}->findOne( $updateQuery );
      }

      if (
        ($rate_previous === false && $updateFields['$inc']['weight'] > 0 && $ngram_data->weight > 25 && $ngram_data->weightings > 1) ||
        ($rate_previous !== false && ($ngram_data->weight + $updateFields['$inc']['weight']) > 25 && ($ngram_data->weightings - 1) > 1)
      ) {
        // increase / decrease score of all links where this n-gram is used
        // ... score must be increased / decreased for each word present in the n-gram
        $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateMany(
          [ 'ngrams' => $ngram_data->_id, 'archived' => [ '$ne' => 1 ] ],
          [
            '$inc' => [
              'score' => $updateFields['$inc']['weight'] * $ngram_words_count,
              'score_increment_from_ngrams' => $updateFields['$inc']['weight'] * $ngram_words_count
            ]
          ]
        );

        // recalculate n-grams score increment total percentage
        recalculate_ngrams_total_percentage( [ $ngram_data->_id ] );

        // recalculate the conformed score for all relevant links
        recalculate_conformed_score([ 'ngrams' => $ngram_data->_id ]);
      }

      if ($rate_previous === false) {
        // add this n-gram to current link, if we're not un-training
        $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateOne(
          [ '_id' => new MongoDB\BSON\ObjectId( $link ) ],
          [ '$push' => [ 'ngrams' => $ngram_data->_id ] ]
        );
      } else {
        // remove this n-gram from current link, as we're un-training
        // and keeping it here would mean duplicating n-grams during the next training
        $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateOne(
          [ '_id' => new MongoDB\BSON\ObjectId( $link ) ],
          [ '$pull' => [ 'ngrams' => $ngram_data->_id ] ]
        );
      }

      // update the cached scored n-gram weight, so we don't use the old, potentially negative value
      if ( isset( $scored_ngrams[ $ngram ] ) ) {
        $scored_ngrams[ $ngram ]->weight += $updateFields['$inc']['weight'];
        $scored_ngrams[ $ngram ]->weightings += $updateFields['$inc']['weightings'];
      } else {
        $scored_ngrams[ $ngram ]         = new StdClass();
        $scored_ngrams[ $ngram ]->weight = $updateFields['$inc']['weight'];
        $scored_ngrams[ $ngram ]->weightings = $updateFields['$inc']['weightings'];
      }
    }
  }

  // words scoring
  $skip_next = false;
  foreach ( $words[0] as $index => $word ) {
    if ( $skip_next ) {
      $skip_next = false;
      continue;
    }

    if ($rate_previous === false) {
      $score_increment_by = 1;
    } else {
      $score_increment_by = -1;
    }

    $score_increment_from_ngrams = 0;

    // check for a number followed by a measurement unit
    if ( is_numeric( $word ) ) {
      if ( isset( $words[0][ $index + 1 ] ) && in_array( $words[0][ $index + 1 ], $measurement_units_array ) ) {
        $score_increment_by += ($rate_previous === false ? $scoring_adjustments['measurement_unit'] : -$scoring_adjustments['measurement_unit']);
        $word               = $word . $words[0][ $index + 1 ];
        $skip_next          = true;
      } else {
        $score_increment_by += ($rate_previous === false ? $scoring_adjustments['number'] : -$scoring_adjustments['number']);
      }
    } else {
      $score_increment_by += ($rate_previous === false ? $scoring_adjustments['word'] : -$scoring_adjustments['word']);
    }

    // single-digits and letters are ignored
    if ( strlen( $word ) == 1 ) {
      continue;
    }

    // check for n-grams for this word and adjust score by their presence as necessary
    if (!isset( $scored_words[ $word ]->ignored ) || ! $scored_words[ $word ]->ignored) {
      foreach ( $scored_ngrams as $ngram => $ngram_data ) {
        // only adjust score if this n-gram is used in more than a single link and is not being ignored
        if (
          isset($ngram_data->weightings) &&
          $ngram_data->weightings > 1 &&
          isset($ngram_data->weight) &&
          $ngram_data->weight > 25 &&
          ( ! isset( $ngram_data->ignored ) || ! $ngram_data->ignored ) &&
          ( ( strpos( $ngram, $word . ' ' ) !== false ) || ( strpos( $ngram, ' ' . $word ) !== false ) ) ) {
          // check if this scored DB n-gram is present in any of the current n-grams generated for our current text
          foreach ( $words[1] as $ngram_current ) {
            if ( $ngram == $ngram_current ) {
              $score_increment_from_ngrams += $ngram_data->weight;
              $score_increment_from_ngrams_total += $ngram_data->weight;

              // store how much was this word adjusted through n-grams
              if ( ! isset( $detailed[ $word ] ) ) {
                $detailed[ $word ] = [ 'ngram_adjustments' => $ngram_data->weight ];
              } else {
                $detailed[ $word ]['ngram_adjustments'] += $ngram_data->weight;
              }
            }
          }
        }
      }
    }

    // update this word's scoring in database
    if ( $update_db ) {
      $updateQuery = [
        'word' => $word,
        'feed' => $feed_object
      ];

      $updateFields = [
        '$set' => [
          'word' => $word,
          'feed' => $feed_object
        ],
        '$inc' => [
          'upvoted_times' => 0,
          'weightings'    => (( ! isset( $scored_words[ $word ]->ignored ) || ! $scored_words[ $word ]->ignored ) ? 1 : 0),
          'weight'        => 0,
          'weight_raw'    => 0 // weight without adjustments for feed priorities,
                               // i.e. the raw weight of this word as voted up by the user
        ]
      ];

      if ( ! isset( $scored_words[ $word ]->ignored ) || ! $scored_words[ $word ]->ignored ) {
        if ( $rate === 1 ) {
          $updateFields['$inc']['upvoted_times'] = 1;

          // if this is a number, only increase weight if this number has been upwoted in more than 60% of all weightings,
          // otherwise our sorting would depend too much on numbers
          // ... do this only if we did not priorize numbers in this feed, in which case we actually want them to count
          //     as we set them up
          if ( is_numeric( $word ) && $scoring_adjustments['number'] == 0 ) {
            $record = $mongo->{MONGO_DB_NAME}->{'words-' . $user_id}->findOne( [ 'feed' => $feed_object, 'word' => $word ] );
            if ( ! $record || ( ($record->upvoted_times / ( $record->weightings + 1 )) * 100 > 60) ) {
              $updateFields['$inc']['weight'] = $score_increment_by;
              $updateFields['$inc']['weight_raw'] ++;
            } else {
              // reset this, so we don't count this number into final score
              $score_increment_by = 0;
            }
          } else {
            $updateFields['$inc']['weight'] = $score_increment_by;
            $updateFields['$inc']['weight_raw'] ++;
          }
        } else if ( $rate === 0 ) {
          // negative rating, no increment
          $score_increment_by = 0;
        } else if ($rate === -1) {
          // we're un-training this item, decrease weight and weightings
          $updateFields['$inc']['weightings'] = -1;

          // only do this is link was previously upvoted
          if ($rate_previous === 1) {
            $updateFields['$inc']['upvoted_times'] = - 1;

            // if this is a number, only decrease weight if this number has previously been upvoted in more than 60% of all weightings,
            // otherwise our sorting would depend too much on numbers
            // ... do this only if we did not priorize numbers in this feed, in which case we actually want them to count
            //     as we set them up
            if ( is_numeric( $word ) && $scoring_adjustments['number'] == 0 ) {
              $record = $mongo->{MONGO_DB_NAME}->{'words-' . $user_id}->findOne( [ 'feed' => $feed_object, 'word' => $word ] );
              if ( ($record->weightings > 0 && ( ( ($record->upvoted_times - 1) / $record->weightings) * 100 > 60)) ) {
                $updateFields['$inc']['weight'] = $score_increment_by; // $score_increment_by is already negative at this point
                $updateFields['$inc']['weight_raw'] --;
              } else {
                // reset this, so we don't count this number into final score
                $score_increment_by = 0;
              }
            } else {
              $updateFields['$inc']['weight'] = $score_increment_by; // $score_increment_by is already negative at this point
              $updateFields['$inc']['weight_raw'] --;
            }
          }
        } else {
          // reset this, as we're not rating but storing into DB, which means
          // this is a new link coming from a new RSS fetch
          $updateFields['$inc']['weightings'] = 0;
          $score_increment_by                 = 0;
        }
      }

      // update or insert a new word with new value
      $word_data = $mongo->{MONGO_DB_NAME}->{'words-' . $user_id}->findOneAndUpdate( $updateQuery, $updateFields, [
        'upsert'         => true,
        'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
      ] );

      if ( $word_data === null ) {
        $word_data = $mongo->{MONGO_DB_NAME}->{'words-' . $user_id}->findOne( $updateQuery );
      }

      $recalculate_averages = false;

      // calculate and update by how many percent did the potential user interest of this word increase by
      // ... if this word is not ignored
      if ( $rate !== false && (! isset( $word_data->ignored ) || ! $word_data->ignored )) {
        if ($word_data->weight_raw == 0 && $updateFields['$inc']['weightings'] > -1) {
          // weight has not changed, as this is probably a number which was at weight 0 before
          // and still remains at 0 and we've not just decreased the weight by un-training this link
          $words_interest_average_percent_new = $words_interest_average_percent_old = 0;
        } else {
          // weight has changed
          if ($word_data->weightings > 0) {
            $words_interest_average_percent_new = ( ( $word_data->weight_raw / $word_data->weightings ) * 100 );
          } else {
            // weightings can be 0 if we're un-training this link
            $words_interest_average_percent_new = 0;
          }

          if ($rate_previous === false) {
            // we're training this link
            $words_interest_average_percent_old = ( $word_data->weightings > 1 ? ( ( ( $word_data->weight_raw - $rate ) / ( $word_data->weightings - 1 ) ) * 100 ) : 0 );
          } else {
            // we're un-training this link
            if ($word_data->weightings > -1) {
              $words_interest_average_percent_old = ( ( ( $word_data->weight_raw - $updateFields['$inc']['weight'] ) / ( $word_data->weightings + 1 ) ) * 100 );
            } else {
              $words_interest_average_percent_old = 0;
            }
          }

          // update count of words rated above 50% in links where this word is present
          // if its percentage dropped from 50+% to a value below that
          // note: we do this only for non-numeric words, unless they get priorized in this feed, as that would potentially generate
          //       an abundance of false positives for various auctions, trading feeds etc.
          if (!is_numeric( $word_data->word ) || $scoring_adjustments['number']) {
            if (
              (
                $words_interest_average_percent_old > 50 &&
                $words_interest_average_percent_new <= 50 &&
                (
                  $word_data->weightings > 2 || // we're training the link or un-training
                                                // but its still weighted properly for this calculation
                  (
                    $updateFields['$inc']['weightings'] == -1 && // we're un-traning the link
                    ($word_data->weightings + 1) > 2
                  )
                )
              )
              ||
              (
                // we're un-training this item and this just became either a <50% word or a word that's below 3 ratings
                $rate_previous !== false &&
                (
                  $words_interest_average_percent_old > 50 &&
                  (
                    $words_interest_average_percent_new <= 50 ||
                    $word_data->weightings == 2
                  )
                )
                && $word_data->weightings == 2
              )
            ) {
              $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => - 1 ] ] );
            } else if (
              (
                $words_interest_average_percent_old <= 50 &&
                $words_interest_average_percent_new > 50 &&
                (
                  $word_data->weightings > 2 || // we're training the link or un-training
                                                // but its still weighted properly for this calculation
                  (
                    $updateFields['$inc']['weightings'] == -1 && // we're un-traning the link
                    ($word_data->weightings + 1) > 2
                  )
                )
              )
              ||
              (
                // we're training this item and it's just became a 50+% word with 3 ratings
                $rate_previous === false &&
                $words_interest_average_percent_old > 50 &&
                $words_interest_average_percent_new > 50 &&
                $word_data->weightings == 3
              )
            ) {
              // do the same update as above in reverse, if applicable
              $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => 1 ] ] );
            }
          }
        }

        // if we're un-training, decrease count by 1 if word was weighted for the 1st time before,
        // otherwise increase count by 1 if the word is currently weighted at 1 now
        $count_increment = ($rate_previous !== false ? -($word_data->weightings + 1 == 1 ? 1 : 0) : ($updateFields['$inc']['weightings'] > 0 && $word_data->weightings == 1 ? 1 : 0));

        if ($words_interest_average_percent_new != $words_interest_average_percent_old || $count_increment != 0) {
          $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateMany(
            [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ],
            [
              [
                '$set' => [
                  'words_interest_total_percent'   => [
                    '$add' => [
                      [
                        '$add' => [
                          '$words_interest_total_percent',
                          [
                            '$multiply' => [
                              '$words_counter.' . $word_data->_id,
                              - $words_interest_average_percent_old,
                            ]
                          ],
                        ]
                      ],
                      [
                        '$multiply' => [
                          '$words_counter.' . $word_data->_id,
                          $words_interest_average_percent_new,
                        ]
                      ],
                    ]
                  ],
                  'words_interest_count' => [
                    '$add' => [
                      '$words_interest_count',
                      [
                        '$multiply' => [
                          '$words_counter.' . $word_data->_id,
                          $count_increment,
                        ],
                      ],
                    ],
                  ],
                  'words_interest_average_percent' => [
                    '$cond' => [
                      [
                        '$gt' => [
                          [
                            '$add' => [
                              '$words_interest_count',
                              [
                                '$multiply' => [
                                  '$words_counter.' . $word_data->_id,
                                  $count_increment,
                                ],
                              ],
                            ]
                          ],
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
                                  [
                                    '$multiply' => [
                                      '$words_counter.' . $word_data->_id,
                                      - $words_interest_average_percent_old,
                                    ]
                                  ],
                                ]
                              ],
                              [
                                '$multiply' => [
                                  '$words_counter.' . $word_data->_id,
                                  $words_interest_average_percent_new,
                                ]
                              ],
                            ]
                          ],
                          [
                            '$add' => [
                              '$words_interest_count',
                              [
                                '$multiply' => [
                                  '$words_counter.' . $word_data->_id,
                                  $count_increment,
                                ],
                              ],
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

          $recalculate_averages = true;
        }
      }

      // add this word to current link, if we're inserting this link's data into the DB
      // ... duplicate words in this array purposefully to retain words count in case of duplicates
      if ( $rate === false ) {
        $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateOne(
          [ '_id' => new MongoDB\BSON\ObjectId( $link ) ],
          [ '$push' => [ 'words' => $word_data->_id ] ]
        );
      }

      // count this instance of the word
      if (!isset($words_counter[ (string) $word_data->_id ])) {
        $words_counter[ (string) $word_data->_id ] = 1;
      } else {
        $words_counter[ (string) $word_data->_id ]++;
      }

      // increase / decrease score of all links where this word is used - if our score actually changed
      if ( $updateFields['$inc']['weight'] != 0 ) {
        $processedUpdateQuery4Words = [
          [
            '$set' => [
              'score' => [
                '$add' => [
                  '$score',
                  [
                    '$multiply' => [
                      '$words_counter.' . $word_data->_id,
                      $updateFields['$inc']['weight'],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ];
      } else {
        $processedUpdateQuery4Words = [];
      }

      // if we're rating up and the old weight for this word was 0,
      // or we're rating down and the weight of this word remains at 0,
      // or if we're un-training this item and it was previously rated up and the weight is now at 0,
      // let's also adjust the zero_scored_words count for this link
      if (
        ($score_increment_by > 0 && $word_data->weight - $score_increment_by == 0) ||
        ($updateFields['$inc']['weight'] == -1 && $word_data->weight == 0)
      ) {
        $processedUpdateQuery4Words[0]['$set']['zero_scored_words'] = [
          '$add' => [
            '$zero_scored_words',
            [
              '$multiply' => [
                '$words_counter.' . $word_data->_id,
                ( $updateFields['$inc']['weight'] == -1 ? 1 : -1 ),
              ],
            ]
          ],
        ];
      }

      // training this link
      $adjustment = false;

      if ($rate_previous === false) {
        // word was zero-scored and is now scored above zero, update zero-scored words count
        if ( $word_data->weight > 0 && $word_data->weight - $score_increment_by == 0 && $word_data->weightings > 1 ) {
          $adjustment = -1;
        } else if ($word_data->weight == 0 && $word_data->weightings == 1) {
          // word was zero-scored before and is now at 0 score still but has weightings as well
          $adjustment = 1;
        }
      } else if ($rate_previous !== false) {
        // un-training this link
        if (
          $word_data->weight - $updateFields['$inc']['weight'] == 0 &&
          $word_data->weightings - $updateFields['$inc']['weightings'] > 0
        ) {
          // word was zero-scored before, check if it's still zero-scored
          if ($word_data->weight == 0 && $word_data->weightings == 0) {
            // word is no longer zero-scored, as it is not scored at all now - adjust zero-scored words count
            $adjustment = -1;
          }
        } else if ( $word_data->weight - $updateFields['$inc']['weight'] > 0 ) {
          // word wasn't zero-scored, check if that's changed
          if ( $word_data->weight == 0 && $word_data->weightings > 0 ) {
            // word is now zero-scored, adjust zero-scored words count
            $adjustment = 1;
          }
        }
      }

      if ( $adjustment !== false ) {
        $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ],
          [
            [ '$set' =>
                [
                  'zero_scored_words_rated' => [
                    '$add' => [
                      '$zero_scored_words_rated',
                      [
                        '$multiply' => [
                          '$words_counter.' . $word_data->_id,
                          $adjustment,
                        ],
                      ],
                    ]
                  ],
                  'zero_rated_scored_words_percentage' => [
                    '$multiply' => [
                      [
                        '$divide' => [
                          [
                            '$add' => [
                              '$zero_scored_words_rated',
                              [
                                '$multiply' => [
                                  '$words_counter.' . $word_data->_id,
                                  $adjustment,
                                ],
                              ],
                            ]
                          ],
                          [
                            '$size' => '$words'
                          ]
                        ]
                      ],
                      100
                    ]
                  ]
                ]
            ]
          ]
        );
      }

      if ( count($processedUpdateQuery4Words) ) {
        $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], $processedUpdateQuery4Words );
        $recalculate_averages = true;
      }

      // recalculate IAPT and conformed score, if needed
      if ($recalculate_averages) {
        adjust_percentages_and_score([ 'words' => $word_data->_id ]);
      }

      // update the cached scored word weight, so we don't use the old, potentially negative value
      $scored_words[ $word ] = $word_data;
    }

    if ( isset( $scored_words[ $word ] ) ) {

      if ( ! isset( $detailed[ $word ] ) ) {
        $detailed[ $word ] = [ 'score' => 0 ];
      } else if ( ! isset( $detailed[ $word ]['score'] ) ) {
        $detailed[ $word ]['score'] = 0;
      }

      if ( ! isset( $scored_words[ $word ]->ignored ) || ! $scored_words[ $word ]->ignored ) {
        $detailed[ $word ]['score'] = ( $scored_words[ $word ]->weight + $score_increment_from_ngrams );
      }

      $detailed[ $word ]['weight']     = $scored_words[ $word ]->weight;
      $detailed[ $word ]['weight_raw'] = $scored_words[ $word ]->weight_raw;
      $detailed[ $word ]['weightings'] = $scored_words[ $word ]->weightings;
      $detailed[ $word ]['ignored']    = ( isset( $scored_words[ $word ]->ignored ) ? $scored_words[ $word ]->ignored : 0 );
      $detailed[ $word ]['_id']        = (string) $scored_words[ $word ]->_id;
      $detailed[ $word ]['in_labels']  = ( ! empty( $scored_words[ $word ]->in_labels ) ? $scored_words[ $word ]->in_labels : [] );

      if (isset($scored_words[ $word ]->rated)) {
        $detailed[ $word ]['rated']    = $scored_words[ $word ]->rated;
      }

      if (!isset($detailed[ $word ]['count'])) {
        $detailed[ $word ]['count'] = 1;
      } else {
        $detailed[ $word ]['count']++;
      }
    }
  }

  // insert words count to the link, if we're just inserting it into the DB
  if ($update_db && $rate === false) {
    $mongo->{MONGO_DB_NAME}->{'training-' . $user_id}->updateOne(
      [ '_id' => new MongoDB\BSON\ObjectId( $link ) ],
      [ '$set' => [ 'words_counter' => $words_counter  ] ]
    );
  }

  // now calculate the final score from all our potentially newly scored words
  $skip_next = false;
  foreach ( $words[0] as $index => $word ) {

    if ( $skip_next ) {
      $skip_next = false;
      continue;
    }

    // single-digits and letters are ignored
    if ( strlen( $word ) == 1 ) {
      continue;
    }

    if ( is_numeric( $word ) ) {
      if ( isset( $words[0][ $index + 1 ] ) && in_array( $words[0][ $index + 1 ], $measurement_units_array ) ) {
        $word      = $word . $words[0][ $index + 1 ];
        $skip_next = true;
      }
    }

    if ( isset( $scored_words[ $word ] ) ) {
      if ( ! isset( $scored_words[ $word ]->ignored ) || ! $scored_words[ $word ]->ignored ) {

        if (!isset($detailed[ $word ]['score'])) {
          $detailed[ $word ]['score'] = 0;
        }

        $score += $detailed[ $word ]['score'];

        // we'll be returning the number of 0-scored words for this link, so let's count this one in
        if ( $scored_words[ $word ]->weight == 0 ) {
          $zero_scored_words ++;
          // increment the number of weighted words with a score of 0
          if ( $scored_words[ $word ]->weightings > 0 ) {
            $zero_scored_words_rated ++;
          }
        }
      }
    }
  }

  // remove all words that only have n-grams but are not scored in the DB
  $words_to_remove = [];
  foreach ( $detailed as $word => $data ) {
    if ( ! isset( $data['weightings'] ) ) {
      $words_to_remove[] = $word;
    }
  }

  if ( count( $words_to_remove ) ) {
    foreach ( $words_to_remove as $word ) {
      unset( $detailed[ $word ] );
    }
  }

  $ret = [
    'score' => $score,
    'score_increment_from_ngrams' => $score_increment_from_ngrams_total,
    'zero_scored_words' => $zero_scored_words,
    'zero_scored_words_rated' => $zero_scored_words_rated,
    'words_details' => $detailed,
  ];

  return $ret;
}