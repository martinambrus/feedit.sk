<?php
  // calculate average user interest for categories in percent
  $update_array['categories_interest_average_percent'] = 0;
  $update_array['categories_interest_count']           = 0;
  $update_array['categories_interest_total_percent']   = 0;
  $processed_categories                                = 0; // contains number of categories that were actually rated at least once,
                                                            // so our percentage average gets calculated correctly

  if ( isset( $training_array['categories'] ) ) {
    foreach ($training_array['categories'] as $cat) {
      if (isset($scored_categories[ $cat ])) {
        $category                                            = $scored_categories[ $cat ];
        $weight_percentage                                   = ( $category->weight ? ( ( ( $category->weight / 0.01 ) / $category->weightings ) * 100 ) : 0 );
        $update_array['categories_interest_average_percent'] += $weight_percentage;
        $update_array['categories_interest_total_percent']   += $weight_percentage;
        $update_array['score']                               += $category->weight;
        $processed_categories ++;
      }
    }

    if ( $processed_categories ) {
      $update_array['categories_interest_average_percent'] /= $processed_categories;
      $average_calculation_items_counted ++;
    }

    $update_array['categories_interest_count'] = $processed_categories;
  }