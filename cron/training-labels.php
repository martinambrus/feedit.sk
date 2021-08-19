<?php
  // check for possible labels for this link, if our title consists of at least 4 words
  $words_in_link = count( $score['words_details'] );

  // make link labels objects
  $link_label_objects = [];
  foreach ( $link_labels as $lnk_label_key => $lnk_label_count ) {
    $link_label_objects[] = new MongoDB\BSON\ObjectId( $lnk_label_key );
  }

  // load all labels that this link's words are present in,
  // or those that have the same label as any of the words in this link
  $possible_labels = $mongo->bayesian->{'labels-' . $user->short_id}->find( [
    'feed' => $feed_object,
    '$or'  => [
      [ '_id' => [ '$in' => $link_label_objects ] ],
      [ 'label' => [ '$in' => array_keys( $score['words_details'] ) ] ]
    ]
  ] );

  $update_array['label_predictions'] = [];

  foreach ( $possible_labels as $possible_label ) {
    $has_110_percent_probability_word = false;

    // check if any of our words match the actual label name, in which case
    // we'll set this label as a permanent one
    foreach ( $score['words_details'] as $word => $word_data ) {
      if ( $word == $possible_label->label ) {
        $has_110_percent_probability_word = true;
        break;
      }
    }

    if ( $has_110_percent_probability_word ) {
      if ( !isset($update_array['labels']) ) {
        $update_array['labels'] = [];
      }

      $update_array['labels'][] = $possible_label->_id;
    } else if ( $words_in_link > 3 ) {
      // only add a prediction for links with at least 4 words
      $update_array['label_predictions'][] = [
        'id'          => $possible_label->_id,
        'label'       => $possible_label->label,
        'probability' => round( ( $link_labels[ (string) $possible_label->_id ] / $words_in_link ) * 100 )
      ];
    }
  }

  // we may not have any label predictions if this link's title is <4 words
  if (!count($update_array['label_predictions'])) {
    unset($update_array['label_predictions']);
  }