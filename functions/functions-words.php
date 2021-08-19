<?php

$measurement_units_array = [
  '%',
  '°c',
  '°f',
  '°d',
  '°r',
  'µ',
  'µl',
  'µm',
  'µn',
  'µr',
  'µs',
  '$',
  'bq',
  'btc',
  'bps',
  'mbps',
  'kbps',
  'tbps',
  'aud',
  'au',
  'bit',
  'gbp',
  'btu',
  'byte',
  'kbyte',
  'bytes',
  'kbytes',
  'mbyte',
  'mbytes',
  'mb',
  'b',
  'kb',
  'gb',
  'tb',
  'cal',
  'mcal',
  'kcal',
  'cad',
  'cc',
  'cm',
  'm',
  'mm',
  'dm',
  'km',
  'mile',
  'miles',
  'cl',
  'mm²',
  'cm²',
  'm²',
  'dm²',
  'km²',
  'mm³',
  'cm³',
  'm³',
  'dm³',
  'km³',
  'ct',
  'ft',
  'yd',
  'ft³',
  'in',
  'in³',
  'pc',
  'czk',
  'eur',
  'dl',
  'ml',
  'l',
  'hl',
  'deg',
  'egp',
  '€',
  'fs',
  'g',
  'mg',
  'kg',
  't',
  'lb',
  'gal',
  'min',
  'hr',
  's',
  'sec',
  'ns',
  'hour',
  'hours',
  'minute',
  'minutes',
  'second',
  'seconds',
  'ms',
  'millisecond',
  'milliseconds',
  'nanosecond',
  'nanoseconds',
  'jpy',
  'j',
  'joule',
  'k',
  'kbit',
  'kn',
  'knot',
  'knots',
  'pa',
  'mpa',
  'kpa',
  'kw',
  'kwh',
  'mwh',
  'wh',
  'w',
  'watt',
  'watts',
  'ws',
  'kws',
  'mws',
  'mi',
  'mms',
  'cms',
  'dms',
  'kms',
  'mph',
  'kph',
  'kmh',
  'mh',
  'dmh',
  'cmh',
  'mmh',
  'kms',
  'mps',
  'mmps',
  'dmps',
  'cmps',
  'fps',
  'fpm',
  'fph',
  'mpg',
  'oz',
  'pt',
  'qt',
  'quart',
  'r',
  'rad',
  'usd',
  'yard',
  'yards'
];

function sanitize_title_or_word( $text, $lang, $remove_all_dots = false ) {
  global $mongo;
  static $stopwords;

  if ( ! isset( $stopwords[ $lang ] ) ) {
    $record = $mongo->{MONGO_DB_NAME}->stopwords->findOne( [ 'lang' => $lang ] );
    if ( $record ) {
      $stopwords[ $lang ] = (array) $record->words;
    } else {
      $stopwords[ $lang ] = [];
    }
  }

  // convert HTML entities into real characters
  $text = html_entity_decode( $text );

  // common combined measurement units
  $units = [
    'mm/m' => 'mmm',
    'cm/m' => 'cmm',
    'dm/m' => 'dmm',
    'm/m'  => 'mm',
    'km/m' => 'kmm',
    'mm/h' => 'mmh',
    'cm/h' => 'cmh',
    'dm/h' => 'dmh',
    'm/h'  => 'mh',
    'km/h' => 'kmh',
    'mm/s' => 'mms',
    'cm/s' => 'cms',
    'dm/s' => 'dms',
    'm/s'  => 'ms',
    'km/s' => 'kms',
    'ft/s' => 'fts',
    'ft/m' => 'ftm',
    'ft/h' => 'fth',
    // non-breaking space into ordinary space
    ' '    => ' ',
  ];
  $text  = str_replace( array_keys( $units ), array_values( $units ), $text );

  // smart quotes
  $text = str_replace( [ '“', '”' ], [ '"' ], $text );

  // stopwords
  $text = preg_replace( '/\b(' . implode( '|', $stopwords[ $lang ] ) . ')\b/miu', '', $text );

  // orphaned apostrophe after removing a stopword
  $text = preg_replace( '/[ ]+\'[ ]+/m', ' ', $text );

  // word-boundary replacements
  $text = str_replace( [ '+', ';', ':' ], ' ', $text );

  // full-stop that does not delimit numeric values
  $text = preg_replace( '/([^\d])\.([^\d])\.?/m', '$1 $2', $text );
  $text = preg_replace( '/([\d])\.([^\d])\.?/m', '$1 $2', $text );
  $text = preg_replace( '/([^\d])\.([\d])\.?/m', '$1 $2', $text );

  // comma that does not delimit numeric values
  $text = preg_replace( '/([^\d]),([^\d]),?/m', '$1 $2', $text );
  $text = preg_replace( '/([\d]),([^\d]),?/m', '$1 $2', $text );
  $text = preg_replace( '/([^\d]),([\d]),?/m', '$1 $2', $text );

  if ( $remove_all_dots ) {
    // full-stop at the end of sentence splitted with the word
    $text = preg_replace( '/([^\d])\./m', '$1', $text );
  }

  // non-word and non-numeric characters
  $text = preg_replace( '/[^\w+-,.; %°µ$€\'"]/um', '', $text );

  // double-spaces
  $text = preg_replace( '/[ ]{2,}/m', ' ', $text );

  // lowercase
  $text = mb_strtolower( $text );

  return $text;
}

function parse_words( $txt, $lang, $ngrams_length = 3 ) {
  global $measurement_units_array;

  // remove special characters and formatting
  $txt = trim( sanitize_title_or_word( $txt, $lang ) );

  // simple explode, listing all words separately
  $words = explode( ' ', $txt );

  // adjust words to cater for measurement units
  $adjusted_words = [];

  $skip_next = false;
  foreach ( $words as $index => $word ) {
    if ( $skip_next ) {
      $skip_next = false;
      continue;
    }

    if ( is_numeric( $word ) && isset( $words[ $index + 1 ] ) && in_array( $words[ $index + 1 ], $measurement_units_array ) ) {
      $word      = $word . $words[ $index + 1 ];
      $skip_next = true;
    }

    $adjusted_words[] = $word;
  }

  // n-grams generation
  $words_count = count( $adjusted_words );
  $ngrams      = [];

  if ( $words_count >= $ngrams_length ) {
    // fill-in the indexes for an initial n-gram combination
    // ... these will then be shifted until we get 0 at the first $combos index,
    //     which will signal us that we have all the n-grams calculated and can exit the calculation loop
    $combos = [];
    for ( $i = 0; $i < $ngrams_length; $i ++ ) {
      $combos[] = $i;
    }

    // n-grams calculation loop
    // ... shifts indexes of all $combos elements, so we go n-gram after n-gram until $combos[0] is at 0 again
    $x = 0;
    do {
      $ngram = [];
      for ( $i = 0; $i < $ngrams_length; $i ++ ) {
        $ngram[] = $adjusted_words[ $combos[ $i ] ++ ];
      }
      $ngrams[] = implode( ' ', $ngram );

      // check and adjust indexes in the $combos array, so we don't get out of bounds
      $index_adjustment = null;
      for ( $i = 0; $i < $ngrams_length; $i ++ ) {
        // we already had to adjust the index, so just update the $combos index according to $index_adjustment
        if ( $index_adjustment !== null ) {
          $combos[ $i ] = ++ $index_adjustment;
        } else {
          // we've not yet gone out of bounds, check if we wouldn't with this index
          if ( $combos[ $i ] > $words_count - 1 ) {
            // we would go out of bounds now, let's start adjusting indexes to start from 0
            $index_adjustment = 0;
            $combos[ $i ]     = $index_adjustment;
          }
        }
      }

      // if anything goes wrong, break out here
      if ( $x ++ > 1000 ) {
        // we would potentially have wrong engrams generated, so just bail out with an empty array
        $ngrams = [];
        break;
      }
    } while ( $combos[0] > 0 );
  }

  // remove last $ngrams_length - 1 ngrams, which are the ones that contain words overlapping
  // from end of title to its beginning again
  // ... just to keep the above algorithm, since it took a little while to figure out originally :P
  $ngrams = array_slice( $ngrams, 0, count( $ngrams ) - $ngrams_length + 1 );

  return [ $words, $ngrams ];
}