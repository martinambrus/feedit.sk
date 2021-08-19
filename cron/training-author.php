<?php
  // calculate average user interest for author in percent
  $update_array['author_interest_average_percent'] = 0;
  $update_array['author_interest_average_count']   = 0;

  if ( isset( $training_array['author'] ) && isset($scored_authors[ $training_array['author'] ] ) ) {
    // calculate average user interest for author in percent
    $author_rating = $scored_authors[ $training_array['author'] ];
    $update_array['author_interest_average_percent'] = ( ( $author_rating->weight / 0.1 ) / $author_rating->weightings ) * 100;
    $average_calculation_items_counted ++;
    $update_array['author_interest_average_count'] = 1;
    $update_array['score']                         += $author_rating->weight;
    $cached_authors[ $training_array['author'] ]   = $author_rating->weight;
  }