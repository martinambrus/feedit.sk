<?php
  // adjust score for any phrases manually input by the user
  $lowercase_title                    = mb_strtolower( $link->title );
  $adjustment_phrases_score_increment = 0;
  if ( isset( $feed_data->adjustment_phrases ) && count( $feed_data->adjustment_phrases ) ) {
    foreach ( $feed_data->adjustment_phrases as $phrase => $phrase_weight ) {
      $lowercase_phrase = mb_strtolower( $phrase );
      if ( mb_strpos( $lowercase_title, $lowercase_phrase ) !== false ) {
        $adjustment_phrases_score_increment += $phrase_weight;
      }
    }

    $update_array['score']                            += $adjustment_phrases_score_increment;
    $update_array['score_increment_from_adjustments'] = $adjustment_phrases_score_increment;

    if ( $adjustment_phrases_score_increment ) {
      $average_calculation_items_counted ++;
    }
  }