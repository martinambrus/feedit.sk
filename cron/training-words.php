<?php

  // work out interest percentages for our scored words,
  // if we have any words to work out percentages for...
  if ( count( $score['words_details'] ) ) {
    foreach ( $score['words_details'] as $word => $word_data ) {
      $word_ids[] = new MongoDB\BSON\ObjectId( $word_data['_id'] );

      // if this word is already present in any labels,
      // count the amount it's there
      if ( ! empty( $word_data['in_labels'] ) ) {
        foreach ( $word_data['in_labels'] as $label ) {
          if ( ! isset( $link_labels[ (string) $label ] ) ) {
            $link_labels[ (string) $label ] = 0;
          }

          $link_labels[ (string) $label ] ++; // count how many words from this label we have in this link
        }
      }

      if ( ! isset( $word_data['ignored'] ) || ! $word_data['ignored'] ) {
        $is_valid_average_word = ( isset( $word_data['weightings'] ) && $word_data['weightings'] > 0 );
        $word_percentage       = ( $is_valid_average_word ? ( ( $word_data['weight_raw'] / $word_data['weightings'] ) * 100 ) : 0 );

        if ( $is_valid_average_word ) {
          $update_array['words_interest_average_percent'] += $word_percentage;
          $update_array['words_interest_total_percent']   += $word_percentage;
          $processed_words ++;

          if ( $word_percentage >= 50 && $word_data['weightings'] > 2 && ( ! is_numeric( $word ) || $scoring_adjustments['number'] ) ) {
            $update_array['words_rated_above_50_percent'] ++;
          }
        }
      }
    }

    // adjust interest average percentage
    if ( $processed_words ) {
      $update_array['words_interest_average_percent'] /= $processed_words;
      $average_calculation_items_counted ++;
    }

    $update_array['words_interest_count'] = $processed_words;
  }

  // adjust words interest average percentage by any n-grams found for this link
  if ( $update_array['score_increment_from_ngrams_percent'] && $processed_words ) {
    $update_array['words_interest_average_percent'] += ( $update_array['score_increment_from_ngrams_percent'] / $processed_words );
  }