<?php
set_time_limit( 500 );
session_write_close();
ob_start();

// temporary placeholder user hash ID to use for per-user multi-tenant collections
const USER_HASH = '6zaighc';

$rss_time_start = 0;
$rss_time_end = 0;
$select_time_start = 0;
$select_time_end = 0;
$display_time_start = 0;
$display_time_end = 0;

if (!ini_get('date.timezone')) {
  date_default_timezone_set('Europe/Prague');
}

if ( isset( $_GET['return'] ) || isset( $_POST['return'] ) ) {
  $return_value = ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) );
  $ret          = substr( $return_value, strpos( $return_value, '?' ) + 1 );
  $ret          = explode( '&', $ret );
  foreach ( $ret as $key ) {
    $value             = explode( '=', $key );
    $_GET[ $value[0] ] = $value[1];
  }
}
require_once 'vendor/autoload.php';

$mongo = ( new MongoDB\Client );

$feeds = [];
$feed_ids = [];
// load all feeds for this user
foreach ( $mongo->bayesian->{'feeds-' . USER_HASH}->find() as $feed ) {
  $feeds[ (string) $feed->_id ] = $feed;
  $feed_ids[] = $feed->_id;
}

// load feed data for these feeds
foreach ( $mongo->bayesian->feeds->find(['_id' => [ '$in' => $feed_ids ] ]) as $feed ) {
  // combine user feed data with global feed data
  $user_feed_data = $feeds[ (string) $feed->_id ];
  $feeds[ (string) $feed->_id ] = $feed;
  $feeds[ (string) $feed->_id ]->lang = $user_feed_data->lang;
  $feeds[ (string) $feed->_id ]['lang'] = $user_feed_data->lang;
  if (!empty($user_feed_data->scoring_priority)) {
    $feeds[ (string) $feed->_id ]->scoring_priority = $user_feed_data->scoring_priority;
    $feeds[ (string) $feed->_id ]['scoring_priority'] = $user_feed_data->scoring_priority;
  }
}

$labels = [];
if (isset($_GET['feed'])) {
  foreach ( $mongo->bayesian->{'labels-' . USER_HASH}->find( [ 'feed' => new MongoDB\BSON\ObjectId($_GET['feed']) ], [ 'projection' => [ '_id' => 1, 'label' => 1 ] ] ) as $label ) {
    $labels[ (string) $label->_id ] = $label;
  }
}

echo '
<form id="filters_form" action="index-new.php">
    Feed:
    <select id="feeds_list" name="feed" style="width: 65%" onchange="document.getElementById(\'rss\').selectedIndex = 1">
    <option readonly hidden disabled' . ( ! isset( $_GET['feed'] ) ? ' selected' : '' ) . '>Choose a feed...</option>';

foreach ( $feeds as $feed_data ) {
  echo '
    <option value="' . $feed_data->_id . '"' . ( ( $_GET['feed'] == $feed_data->_id ) ? ' selected' : '' ) . '>' . $feed_data->url . ' (' . $feed_data->lang . ')</option>';
}
echo '
    </select> 
    <input type="button" id="prev_rss" value="Prev RSS" onclick="document.getElementById(\'feeds_list\').selectedIndex = document.getElementById(\'feeds_list\').selectedIndex - 1; document.getElementById(\'filters_form\').submit();" /> | 
    <input type="button" id="next_rss" value="Next RSS" onclick="document.getElementById(\'feeds_list\').selectedIndex = document.getElementById(\'feeds_list\').selectedIndex + 1; document.getElementById(\'filters_form\').submit();" />
    <br><br>';

echo '
    Display:
    <select name="display">
        <option value="unread"' . ( ( ! isset( $_GET['display'] ) || $_GET['display'] == 'unread' ) ? ' selected' : '' ) . '>Unread</option>
        <option value="untrained"' . ( ( isset( $_GET['display'] ) && $_GET['display'] == 'untrained' ) ? ' selected' : '' ) . '>Untrained</option>
        <option value="trained_pos"' . ( ( isset( $_GET['display'] ) && $_GET['display'] == 'trained_pos' ) ? ' selected' : '' ) . '>Trained Positively</option>
        <option value="all"' . ( ( isset( $_GET['display'] ) && $_GET['display'] == 'all' ) ? ' selected' : '' ) . '>All</option>
    </select> &nbsp; | &nbsp;
    Details:
    <select name="details">
        <option value="hide"' . ( ( ! isset( $_GET['details'] ) || $_GET['details'] == 'hide' ) ? ' selected' : '' ) . '>Hide</option>
        <option value="show"' . ( ( isset( $_GET['details'] ) && $_GET['details'] == 'show' ) ? ' selected' : '' ) . '>Show</option>
    </select> &nbsp; | &nbsp;
    Order:
    <select name="order">
        <option value="score"' . ( ( ! isset( $_GET['order'] ) || $_GET['order'] == 'score' ) ? ' selected' : '' ) . '>By Score</option>
        <option value="score-zero_words"' . ( ( ! isset( $_GET['order'] ) || $_GET['order'] == 'score-zero_words' ) ? ' selected' : '' ) . '>By Score, Divide by Positives</option>
        <option value="date"' . ( ( isset( $_GET['order'] ) && $_GET['order'] == 'date' ) ? ' selected' : '' ) . '>By Date</option>
        <option value="date-zero_words"' . ( ( isset( $_GET['order'] ) && $_GET['order'] == 'date-zero_words' ) ? ' selected' : '' ) . '>By Date, Divide by Positives</option>
        <option value="tiers"' . ( ( isset( $_GET['order'] ) && $_GET['order'] == 'tiers' ) ? ' selected' : '' ) . '>By Tier</option>
    </select> &nbsp; | &nbsp;
    Hiding:
    <select name="tier">
        <option value="1"' . ( ( ! isset( $_GET['tier'] ) || $_GET['tier'] == '1' ) ? ' selected' : '' ) . '>Show All</option>
        <option value="2"' . ( ( isset( $_GET['tier'] ) && $_GET['tier'] == '2' ) ? ' selected' : '' ) . '>T2+ (>0%)</option>
        <option value="3"' . ( ( isset( $_GET['tier'] ) && $_GET['tier'] == '3' ) ? ' selected' : '' ) . '>T3+ (>10%)</option>
        <option value="4"' . ( ( isset( $_GET['tier'] ) && $_GET['tier'] == '4' ) ? ' selected' : '' ) . '>T4+ (>30%)</option>
        <option value="5"' . ( ( isset( $_GET['tier'] ) && $_GET['tier'] == '5' ) ? ' selected' : '' ) . '>T5+ (>50%)</option>
    </select> &nbsp; | &nbsp;
    <label for="always-show-fully-unrated-links">Include 100% Unrated:</label>
    <input type="checkbox" name="always-show-fully-unrated-links" id="always-show-fully-unrated-links" value="1" ' . ( ( isset( $_GET['always-show-fully-unrated-links'] ) && $_GET['always-show-fully-unrated-links'] == '1' ) ? ' checked' : '' ) . '/> &nbsp; | &nbsp;
    <label for="hide-below-zeroes">Hide <0%:</label>
    <input type="checkbox" name="hide-below-zeroes" id="hide-below-zeroes" value="1" ' . ( ( isset( $_GET['hide-below-zeroes'] ) && $_GET['hide-below-zeroes'] == '1' ) ? ' checked' : '' ) . '/> &nbsp; | &nbsp;
    Label:
    <select id="label" name="label[]" size="'.(count($labels) > 1 ? 3 : 1).'" multiple>';

  if (!count($labels)) {
    echo '
        <option value="0"' . ( ( ! isset( $_GET['label'] ) || in_array(0, $_GET['label'])) ? ' selected' : '' ) . '>- none -</option>';
  }

foreach ( $labels as $label_data ) {
  echo '
    <option value="' . $label_data->_id . '"' . ( (!empty($_GET['label']) && in_array($label_data->_id, $_GET['label'])) ? ' selected' : '' ) . '>' . $label_data->label . '</option>';
}
echo '
    </select>
    <br><br>
    Max % of Negatively Rated Words: <input type="text" name="negative_words_hide_percentage" id="negative_words_hide_percentage" value="'.(isset($_GET['negative_words_hide_percentage']) ? $_GET['negative_words_hide_percentage'] : '').'" /> &nbsp; | &nbsp; 
    Fulltext Search: <input type="text" name="fulltext_search" id="fulltext_search" value="'.(isset($_GET['fulltext_search']) ? $_GET['fulltext_search'] : '').'" /> &nbsp; | &nbsp; 
    <input id="filters_submit" type="submit" value="OK" />
</form><hr />

<form action="index-new.php" method="post">
<input type="hidden" name="return" value="' . urlencode( $_SERVER['REQUEST_URI'] ) . '" />';

$feed                            = ( isset( $_GET['feed'] ) ? $_GET['feed'] : false );
$feed_object                     = ( $feed ? new MongoDB\BSON\ObjectId( $feed ) : false );
$load_rss                        = ( isset( $_GET['rss'] ) && $_GET['rss'] == 'yes' );
$display                         = ( isset( $_GET['display'] ) ? $_GET['display'] : 'unread' );
$display_details                 = ( isset( $_GET['details'] ) && $_GET['details'] == 'show' );
$order                           = ( isset( $_GET['order'] ) ? $_GET['order'] : 'score' );
$hiding                          = ( isset( $_GET['tier'] ) ? $_GET['tier'] : 1 );
$label                           = ( isset( $_GET['label'] ) ? $_GET['label'] : [ 0 ] );
$negative_words_hide_percentage  = ( isset( $_GET['negative_words_hide_percentage'] ) && is_numeric( $_GET['negative_words_hide_percentage'] ) ? (int) $_GET['negative_words_hide_percentage'] : 0 );
$fulltext_search                 = ( isset( $_GET['fulltext_search'] ) ? $_GET['fulltext_search'] : '' );
$always_show_fully_unrated_links = ( isset( $_GET['always-show-fully-unrated-links'] ) && $_GET['always-show-fully-unrated-links'] == '1' );
$hide_below_zeroes               = ( isset( $_GET['hide-below-zeroes'] ) && $_GET['hide-below-zeroes'] == '1' );
$labels_count                    = count( $label );

$cached_authors          = [];
$cached_categories       = [];
$cached_labels           = [];
$processed               = [];
$scored_authors          = [];
$scored_categories       = [];
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

$select_time_start = microtime(true);
if ( $feed ) {
  // load links for this feed
  $processed_docs = $mongo->bayesian->{'training-' . USER_HASH};
  $filter         = [ 'feed' => $feed_object ];
  $options        = [
    'sort' => [
      'score_conformed' => -1,
      'score' => -1,
      'interest_average_percent_total' => -1,
      'zero_scored_words' => 1,
      'date' => -1,
      '_id' => -1,
    ]
  ];

  // display all, unread or untrained links?
  if ( $display == 'unread' ) {
    $filter['read'] = 0;
  } else if ($display == 'untrained') {
    $filter['trained'] = 0;
  } else if ($display == 'trained_pos') {
    $filter['trained'] = 1;
    $filter['rated'] = 1;
  }

  // display links with a certain label?
  if ( ! ( !$labels_count || ($labels_count == 1 && $label[0] == '0') ) ) {
    $label_objects = [];
    $label_titles = [];
    foreach ($label as $l) {
      $label_objects[] = new MongoDB\BSON\ObjectId($labels[ $l ]->_id);
      $label_titles[] = $labels[ $l ]->label;
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

  // get average conformed score for this feed in the DB
  $feed_average_score = $processed_docs->aggregate([
    [
      '$match' => [
        'feed' => $feed_object,
        'score_conformed' => [ '$gte' => 0 ]
      ]
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
          // also select tier 2, if the conformed score of this links is above average conformed score for our feed,
          // as quite a few tier 2 links were observed to be as relevant as tier 3 links, albeit
          // being scored at a lower interest rate percentage
          [
            'interest_average_percent_total' => [ '$gt' => 0 ],
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
            'interest_average_percent_total' => [ '$gt' => 30 ]
          ],
          [
            'interest_average_percent_total' => [ '$gte' => 0 ],
            'words_rated_above_50_percent' => [ '$gt' => 0 ]
          ]
        ]
      ];
    } else {
      $filter['interest_average_percent_total'] = [ '$gt' => 50 ];
    }

	  // do we want to always show links with 100% words unrated?
	  if ($always_show_fully_unrated_links) {
      if ($hiding >= 2 && $hiding <= 4) {
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
    }

    // add negative words hide percentage, if set
    if ($negative_words_hide_percentage) {
      if ($hiding >= 2 && $hiding <= 4) {
        $filter['$and'][ count($filter['$and']) - 1 ]['$or'][0]['zero_rated_scored_words_percentage'] = [ '$lte' => $negative_words_hide_percentage ];
      } else {
        $filter['zero_rated_scored_words_percentage'] = [ '$lte' => $negative_words_hide_percentage ];
      }
    }

    // for tier 3 displaying only - since we are also including tier 2 results with total conformed score above average,
    // we need to make sure to only include those where zero rated scored words percentage is above 60%
    // or we risk showing too many irrelevant tier 2 records
    if ($hiding == 3 && isset($filter['$and'][ count($filter['$and']) - 1 ]['$or'][1]['interest_average_percent_total'])) {
      $filter['$and'][ count($filter['$and']) - 1 ]['$or'][1]['zero_rated_scored_words_percentage'] = [ '$lte' => 60 ];
    }
  } else {
    // add negative words hide percentage, if set
    if ($negative_words_hide_percentage) {
      $filter['zero_rated_scored_words_percentage'] = [ '$lte' => $negative_words_hide_percentage ];
    }
  }

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
  if ($hide_below_zeroes) {
    $filter['score'] = [ '$gte' => 0 ];
  }

  // perform a fulltext search?
  if ($fulltext_search) {
    $filter['title'] = [
      '$regex'   => $fulltext_search,
      '$options' => 'i'
    ];
  }

  // order by score or date?
  if ($order == 'date' || $order == 'date-zero_words') {
    $filter['zero_scored_words'] = [
      '$exists' => true,
    ];

    $options['sort'] = [
      'date' => -1,
      '_id' => -1,
      'zero_scored_words' => 1,
    ];
  }

  $options['limit'] = 200;
  //var_dump($filter, $options); exit;

  $processed_data = $processed_docs->find( $filter, $options );
  $processed_ids = [];

  foreach ( $processed_data as $record ) {
    $processed[ (string) $record->_id ] = $record;
    $processed_ids[] = $record->_id;
  }

  // retrieve actual title, URL and description data for the selected links
  // re-use this variable...
  $processed_data = $mongo->bayesian->processed->find( [ '_id' => [ '$in' => $processed_ids ] ] );
  foreach ( $processed_data as $record ) {
    // add the remainder of data to our records
    $processed[ (string) $record->_id ]->title = $record->title;
    $processed[ (string) $record->_id ]->description = $record->description;
    $processed[ (string) $record->_id ]->link = $record->link;
    $processed[ (string) $record->_id ]->date = $record->date;
    
    if (isset($record->trained)) {
      $processed[ (string) $record->_id ]->trained = $record->trained;
    }

    if (isset($record->rated)) {
      $processed[ (string) $record->_id ]->rated = $record->rated;
    }
  }

  // load all scored authors for this feed
  foreach ( $mongo->bayesian->{'authors-' . USER_HASH}->find( [ 'feed' => $feed_object ] ) as $record ) {
    $scored_authors[ $record->author ] = $record;
  }

  // load all scored categories for this feed
  foreach ( $mongo->bayesian->{'categories-' . USER_HASH}->find( [ 'feed' => $feed_object ] ) as $record ) {
    $scored_categories[ $record->category ] = $record;
  }

  // build indexes of our potential feed-wide scoring adjustments
  $scoring_adjustments = [
    'word'             => 0,
    'number'           => 0,
    'measurement_unit' => 0
  ];


  if ( isset( $feeds[ $feed ]['scoring_priority'] ) ) {
    $priorities_count = count( $feeds[ $feed ]['scoring_priority'] );

    if ( ( $index = array_search( 'word', (array) $feeds[ $feed ]['scoring_priority'] ) ) !== false ) {
      $scoring_adjustments['word'] = $priorities_count - ++ $index + 1;
    }

    if ( ( $index = array_search( 'number', (array) $feeds[ $feed ]['scoring_priority'] ) ) !== false ) {
      $scoring_adjustments['number'] = $priorities_count - ++ $index + 1;
    }

    if ( ( $index = array_search( 'measurement_unit', (array) $feeds[ $feed ]['scoring_priority'] ) ) !== false ) {
      $scoring_adjustments['measurement_unit'] = $priorities_count - ++ $index + 1;
    }
  }
}
$select_time_end = microtime(true);


function rgb2hex($rgb) {
  list($r, $g, $b) = $rgb;
  return sprintf("#%02x%02x%02x", $r, $g, $b);
}

/**
 * Increases or decreases the brightness of a color by a percentage of the current brightness.
 *
 * @param   string  $hexCode        Supported formats: `#FFF`, `#FFFFFF`, `FFF`, `FFFFFF`
 * @param   float   $adjustPercent  A number between -1 and 1. E.g. 0.3 = 30% lighter; -0.4 = 40% darker.
 *
 * @return  string
 */
function adjustBrightness($hexCode, $adjustPercent) {
  $hexCode = ltrim($hexCode, '#');

  if (strlen($hexCode) == 3) {
    $hexCode = $hexCode[0] . $hexCode[0] . $hexCode[1] . $hexCode[1] . $hexCode[2] . $hexCode[2];
  }

  $hexCode = array_map('hexdec', str_split($hexCode, 2));

  foreach ($hexCode as & $color) {
    $adjustableLimit = $adjustPercent < 0 ? $color : 255 - $color;
    $adjustAmount = ceil($adjustableLimit * $adjustPercent);

    $color = str_pad(dechex($color + $adjustAmount), 2, '0', STR_PAD_LEFT);
  }

  return '#' . implode($hexCode);
}

function sanitize_title_or_word( $text, $lang, $remove_all_dots = false ) {
  global $mongo;
  static $stopwords;

  if ( ! isset( $stopwords[ $lang ] ) ) {
    $record = $mongo->bayesian->stopwords->findOne( [ 'lang' => $lang ] );
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

function recalculate_ngrams_total_percentage( $ngram_ids, $user_id ) {
  global $mongo;
  // recalculate n-grams score increment total percentage
  $mongo->bayesian->{'training-' . $user_id}->updateMany( [ 'ngrams' => [ '$in' => $ngram_ids ], 'archived' => [ '$ne' => 1 ] ],
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

function calculate_score( $user_id, $words, $link, $rate = false, $update_db = false, $rate_previous = false ) {
  global $feed, $feed_object, $scoring_adjustments, $measurement_units_array, $mongo;
  static $scored_words, $scored_ngrams;

  $detailed                          = [];
  $score                             = 0;
  $score_increment_from_ngrams_total = 0;
  $zero_scored_words                 = 0;  // words with score of 0, used to populate untrained links in the front-end
                                           // where downvoted links are displayed below links that were upvoted
  $zero_scored_words_rated           = 0;  // rated words with score of 0, used to filter out links that have 60+% words that are unwanted
  $words_counter                     = []; // holds number of instances for each word in the title

  if ( ! isset( $scored_words[ $feed ] ) ) {
    $scored_words[ $feed ] = [];
  }

  if ( ! isset( $scored_ngrams[ $feed ] ) ) {
    $scored_ngrams[ $feed ] = [];
  }

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

    if ( ! isset( $scored_words[ $feed ][ $w ] ) ) {
      $words_to_get[] = $w;
    }
  }

  if ( count( $words_to_get ) ) {
    foreach (
      $mongo->bayesian->{'words-' . $user_id}->find( [
        'feed' => $feed_object,
        'word' => [ '$in' => $words_to_get ]
      ] ) as $record
    ) {
      $scored_words[ $feed ][ $record->word ] = $record;
    }
  }

  $ngrams_to_get = [];
  foreach ( $words[1] as $n ) {
    if ( ! isset( $scored_ngrams[ $feed ][ $n ] ) ) {
      $ngrams_to_get[] = $n;
    }
  }

  if ( count( $ngrams_to_get ) ) {
    foreach (
      $mongo->bayesian->{'ngrams-' . $user_id}->find( [
        'feed'  => $feed_object,
        'ngram' => [ '$in' => $ngrams_to_get ]
      ] ) as $record
    ) {
      $scored_ngrams[ $feed ][ $record->ngram ] = $record;
    }
  }

  // update n-grams in DB
  if ( $update_db && count( $words[1] ) ) {
    foreach ( $words[1] as $ngram ) {
      $ngram_words       = explode( ' ', $ngram );
      $ngram_words_count = count( $ngram_words );
      $skip_ngram        = false;

      foreach ( $ngram_words as $ngram_word ) {
        if ( ! isset( $scored_words[ $feed ][ $ngram_word ] ) ) {
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
          'weightings' => (( isset( $scored_words[ $feed ][ $ngram_word ] ) && isset( $scored_words[ $feed ][ $ngram_word ]['ignored'] ) && $scored_words[ $feed ][ $ngram_word ]['ignored'] ) ? 0 : 1)
        ]
      ];

      // 1 engram word has an added weight of ((n-gram-length - 2) * 25) to make engrams more prominent when scoring
      if ( !isset( $scored_words[ $feed ][ $ngram_word ]['ignored'] ) || !$scored_words[ $feed ][ $ngram_word ]['ignored'] ) {
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

      // update or insert a new ngram with new value
      $ngram_data = $mongo->bayesian->{'ngrams-' . $user_id}->findOneAndUpdate( $updateQuery, $updateFields, [
        'upsert'         => true,
        'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
      ] );

      if ( $ngram_data === null ) {
        $ngram_data = $mongo->bayesian->{'ngrams-' . $user_id}->findOne( $updateQuery );
      }

      if (
        ($rate_previous === false && $updateFields['$inc']['weight'] > 0 && $ngram_data->weight > 25 && $ngram_data->weightings > 1) ||
        ($rate_previous !== false && ($ngram_data->weight + $updateFields['$inc']['weight']) > 25 && ($ngram_data->weightings - 1) > 1)
      ) {
        // increase / decrease score of all links where this n-gram is used
        // ... score must be increased / decreased for each word present in the n-gram
        $mongo->bayesian->{'training-' . $user_id}->updateMany(
          [ 'ngrams' => $ngram_data->_id, 'archived' => [ '$ne' => 1 ] ],
          [
            '$inc' => [
              'score' => $updateFields['$inc']['weight'] * $ngram_words_count,
              'score_increment_from_ngrams' => $updateFields['$inc']['weight'] * $ngram_words_count
            ]
          ]
        );

        // recalculate n-grams score increment total percentage
        recalculate_ngrams_total_percentage( [ $ngram_data->_id ], $user_id );

        // recalculate the conformed score for all relevant links
        recalculate_conformed_score([ 'ngrams' => $ngram_data->_id ]);
      }

      if ($rate_previous === false) {
        // add this n-gram to current link, if we're not un-training
        $mongo->bayesian->{'training-' . $user_id}->updateOne(
          [ '_id' => new MongoDB\BSON\ObjectId( $link ) ],
          [ '$push' => [ 'ngrams' => $ngram_data->_id ] ]
        );
      } else {
        // remove this n-gram from current link, as we're un-training
        // and keeping it here would mean duplicating n-grams during the next training
        $mongo->bayesian->{'training-' . $user_id}->updateOne(
          [ '_id' => new MongoDB\BSON\ObjectId( $link ) ],
          [ '$pull' => [ 'ngrams' => $ngram_data->_id ] ]
        );
      }

      // update the cached scored n-gram weight, so we don't use the old, potentially negative value
      if ( isset( $scored_ngrams[ $feed ][ $ngram ] ) ) {
        $scored_ngrams[ $feed ][ $ngram ]->weight += $updateFields['$inc']['weight'];
        $scored_ngrams[ $feed ][ $ngram ]->weightings += $updateFields['$inc']['weightings'];
      } else {
        $scored_ngrams[ $feed ][ $ngram ]         = new StdClass();
        $scored_ngrams[ $feed ][ $ngram ]->weight = $updateFields['$inc']['weight'];
        $scored_ngrams[ $feed ][ $ngram ]->weightings = $updateFields['$inc']['weightings'];
      }
    }
  }

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
    if (isset( $scored_ngrams[ $feed ] ) && ( ! isset( $scored_words[ $feed ][ $word ]->ignored ) || ! $scored_words[ $feed ][ $word ]->ignored )) {
      foreach ( $scored_ngrams[ $feed ] as $ngram => $ngram_data ) {
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
          'weightings'    => (( ! isset( $scored_words[ $feed ][ $word ]->ignored ) || ! $scored_words[ $feed ][ $word ]->ignored ) ? 1 : 0),
          'weight'        => 0,
          'weight_raw'    => 0 // weight without adjustments for feed priorities,
                               // i.e. the raw weight of this word as voted up by the user
        ]
      ];

      if ( ! isset( $scored_words[ $feed ][ $word ]->ignored ) || ! $scored_words[ $feed ][ $word ]->ignored ) {
        if ( $rate === 1 ) {
          $updateFields['$inc']['upvoted_times'] = 1;

          // if this is a number, only increase weight if this number has been upwoted in more than 60% of all weightings,
          // otherwise our sorting would depend too much on numbers
          // ... do this only if we did not priorize numbers in this feed, in which case we actually want them to count
          //     as we set them up
          if ( is_numeric( $word ) && $scoring_adjustments['number'] == 0 ) {
            $record = $mongo->bayesian->{'words-' . $user_id}->findOne( [ 'feed' => $feed_object, 'word' => $word ] );
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
          // negative ratings no longer count
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
              $record = $mongo->bayesian->{'words-' . $user_id}->findOne( [ 'feed' => $feed_object, 'word' => $word ] );
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
      $word_data = $mongo->bayesian->{'words-' . $user_id}->findOneAndUpdate( $updateQuery, $updateFields, [
        'upsert'         => true,
        'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
      ] );

      if ( $word_data === null ) {
        $word_data = $mongo->bayesian->{'words-' . $user_id}->findOne( $updateQuery );
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
              $mongo->bayesian->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => - 1 ] ] );
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
              $mongo->bayesian->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => 1 ] ] );
            }
          }
        }

        // if we're un-training, decrease count by 1 if word was weighted for the 1st time before,
        // otherwise increase count by 1 if the word is currently weighted at 1 now
        $count_increment = ($rate_previous !== false ? -($word_data->weightings + 1 == 1 ? 1 : 0) : ($updateFields['$inc']['weightings'] > 0 && $word_data->weightings == 1 ? 1 : 0));

        if ($words_interest_average_percent_new != $words_interest_average_percent_old || $count_increment != 0) {
          $mongo->bayesian->{'training-' . $user_id}->updateMany(
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
                  'words_interest_count'           => [
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
        $mongo->bayesian->{'training-' . $user_id}->updateOne(
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
        $mongo->bayesian->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ],
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
        $mongo->bayesian->{'training-' . $user_id}->updateMany( [ 'words' => $word_data->_id, 'archived' => [ '$ne' => 1 ] ], $processedUpdateQuery4Words );
        $recalculate_averages = true;
      }

      // recalculate IAPT and conformed score, if needed
      if ($recalculate_averages) {
        adjust_percentages_and_score([ 'words' => $word_data->_id ]);
      }

      // update the cached scored word weight, so we don't use the old, potentially negative value
      $scored_words[ $feed ][ $word ] = $word_data;
    }

    if ( isset( $scored_words[ $feed ][ $word ] ) ) {

      if ( ! isset( $detailed[ $word ] ) ) {
        $detailed[ $word ] = [ 'score' => 0 ];
      } else if ( ! isset( $detailed[ $word ]['score'] ) ) {
        $detailed[ $word ]['score'] = 0;
      }

      if ( ! isset( $scored_words[ $feed ][ $word ]->ignored ) || ! $scored_words[ $feed ][ $word ]->ignored ) {
        $detailed[ $word ]['score'] = ( $scored_words[ $feed ][ $word ]->weight + $score_increment_from_ngrams );
      }

      $detailed[ $word ]['weight']     = $scored_words[ $feed ][ $word ]->weight;
      $detailed[ $word ]['weight_raw'] = $scored_words[ $feed ][ $word ]->weight_raw;
      $detailed[ $word ]['weightings'] = $scored_words[ $feed ][ $word ]->weightings;
      $detailed[ $word ]['ignored']    = ( isset( $scored_words[ $feed ][ $word ]->ignored ) ? $scored_words[ $feed ][ $word ]->ignored : 0 );
      $detailed[ $word ]['_id']        = (string) $scored_words[ $feed ][ $word ]->_id;
      $detailed[ $word ]['in_labels']  = ( ! empty( $scored_words[ $feed ][ $word ]->in_labels ) ? $scored_words[ $feed ][ $word ]->in_labels : [] );
      if (isset($scored_words[ $feed ][ $word ]->rated)) {
        $detailed[ $word ]['rated']    = $scored_words[ $feed ][ $word ]->rated;
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
    $mongo->bayesian->{'training-' . $user_id}->updateOne(
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

    if ( isset( $scored_words[ $feed ][ $word ] ) ) {
      if ( ! isset( $scored_words[ $feed ][ $word ]->ignored ) || ! $scored_words[ $feed ][ $word ]->ignored ) {

        if (!isset($detailed[ $word ]['score'])) {
          $detailed[ $word ]['score'] = 0;
        }

        $score += $detailed[ $word ]['score'];

        // we'll be returning the number of 0-scored words for this link, so let's count this one in
        if ( $scored_words[ $feed ][ $word ]->weight == 0 ) {
          $zero_scored_words ++;
          // increment the number of weighted words with a score of 0
          if ( $scored_words[ $feed ][ $word ]->weightings > 0 ) {
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

// updates total interest percentage value in DB based on the filter given
// ... used when adjusting and ignoring words, authors, categories and adjustment phrases
function update_total_interest_change_percentage($filter) {
  global $mongo;

  // only perform calculations on unarchived items
  $filter['archived'] = [ '$ne' => 1 ];

  // update total interest change percentage
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
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

// recalculates conformed score for all the records determined by the given filter
function recalculate_conformed_score($filter) {
  global $mongo;

  // only perform calculations on unarchived items
  $filter['archived'] = [ '$ne' => 1 ];

  // update total interest change percentage
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
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

// calls recalculate_conformed_score and update_total_interest_change_percentage with the same filter
// ... a shortcut for places where these 2 are called togethr (which is a lot)
function adjust_percentages_and_score($filter) {
  update_total_interest_change_percentage( $filter );
  recalculate_conformed_score( $filter );
}

function train_link( $link, $rate, $rate_previous = false ) {
  global $feeds, $feed, $processed, $label_given, $scoring_adjustments, $mongo, $feed_object;

  $l                                                         = $feeds[ $feed ]['lang'];
  $words                                                     = parse_words( $processed[ $link ]['title'], $l );
  $word_ids                                                  = [];
  $score                                                     = calculate_score( USER_HASH, $words, $link, $rate, true, $rate_previous );
  $words_count                                               = count( $score['words_details'] );
  $processed[ $link ]['score']                               = $score['score']; // the actual final score for this link
  $processed[ $link ]['score_increment_from_ngrams']         = $score['score_increment_from_ngrams'];
  $processed[ $link ]['score_increment_from_ngrams_percent'] = ($score['score_increment_from_ngrams'] ? (($score['score_increment_from_ngrams'] / $score['score']) * 100) : 0);
  $processed[ $link ]['zero_scored_words']                   = $score['zero_scored_words'];
  $processed[ $link ]['zero_scored_words_rated']             = $score['zero_scored_words_rated'];
  $processed[ $link ]['zero_rated_scored_words_percentage']  = ($words_count ? ( ( $processed[ $link ]['zero_scored_words_rated'] / $words_count ) * 100 ) : 0);
  $average_calculation_items_counted                         = 0;  // number of all items (words, authors, categories, adjustment phrases)
                                                                   // that we have a valid average calculated for, i.e. an average that would not be
                                                                   // solely calculated from non-rated words/authors/categories

  // calculate average user interest for words in percent
  $processed[ $link ]['words_interest_average_percent'] = 0;
  $processed[ $link ]['words_interest_total_percent']   = 0;
  $processed[ $link ]['words_rated_above_50_percent']   = 0;
  $processed_words                                      = 0; // contains number of words that were actually rated at least once,
  // so our percentage average gets calculated correctly
  foreach ( $score['words_details'] as $word => $word_data ) {
    if ( ! isset( $word_data['ignored'] ) || ! $word_data['ignored'] ) {
      $is_valid_average_word = ( isset( $word_data['weightings'] ) && $word_data['weightings'] > 0 );
      $word_percentage       = ( $is_valid_average_word ? ( ( $word_data['weight_raw'] / $word_data['weightings'] ) * 100 ) : 0 );

      $processed[ $link ]['words_interest_average_percent'] += ($word_percentage * $word_data['count']);
      $processed[ $link ]['words_interest_total_percent']   += ($word_percentage * $word_data['count']);

      if ( $is_valid_average_word ) {
        $word_ids[] = new MongoDB\BSON\ObjectId( $word_data['_id'] );
        $processed_words += $word_data['count'];

        if ($word_percentage >= 50 && $word_data['weightings'] > 2 && (!is_numeric( $word ) || $scoring_adjustments['number'])) {
          $processed[ $link ]['words_rated_above_50_percent'] += $word_data['count'];
        }
      } else if ( $rate_previous !== false ) {
        $word_ids[] = new MongoDB\BSON\ObjectId( $word_data['_id'] );
      }
    }
  }

  if ( $processed_words ) {
    $processed[ $link ]['words_interest_average_percent'] /= $processed_words;
    $average_calculation_items_counted ++;
  }

  // update this calculation if we have any valid processed words
  // or if we're un-training
  if ( $processed_words || $rate_previous !== false ) {
    update_total_interest_change_percentage( [ 'words' => [ '$in' => $word_ids ] ] );
  }

  $processed[ $link ]['words_interest_count'] = $processed_words;

  // unset any label predictions we may have for this link, as it's now being trained
  // and its label will be assigned, if so desired
  $unsets['label_predictions'] = '';

  // if we've given label to this link and we've rated positively, process that here
  // ... only positive rating is counted towards our label's words, since we want to be
  //     guessing labels only for items that the user actually wants to see, as they are
  //     visually well spotted - which makes it counterproductive to point attention to unwanted content
  if ( $label_given != 0 && $rate === 1 ) {
    $label_id   = new MongoDB\BSON\ObjectId( $label_given );

    // add it to labels for this link
    if ( ! isset( $processed[ $link ]['labels'] ) ) {
      $processed[ $link ]['labels'] = [];
    }

    $label_present = false;
    foreach ( $processed[ $link ]['labels'] as $stored_label ) {
      if ( (string) $stored_label == $label_given ) {
        $label_present = true;
      }
    }

    if ( ! $label_present ) {
      $processed[ $link ]['labels'][] = $label_id;
    }

    // update our words to include this label, if not present yet
    foreach ( $score['words_details'] as $word => $word_data ) {
      // initialize array of labels
      if (empty($word_data['in_labels'])) {
        $word_data['in_labels'] = [];
      }

      // check for label existence
      $label_is_present = false;
      foreach ($word_data['in_labels'] as $existing_label_id) {
        if ((string) $existing_label_id == (string) $label_id) {
          $label_is_present = true;
          break;
        }
      }

      // add this label into our word
      if (!$label_is_present) {
        $word_data['in_labels'][] = $label_id;
        $mongo->bayesian->{'words-' . USER_HASH}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $word_data['_id'] ) ],
          [
            '$set' => [
              'in_labels' => $word_data['in_labels']
            ]
          ]
        );
      }
    }

  }

  // adjust score for any phrases manually input by the user
  $lowercase_title          = mb_strtolower( $processed[ $link ]['title'] );
  $adjustment_phrases_found = 0;
  if ( isset( $feeds[ $feed ]->adjustment_phrases ) && count( $feeds[ $feed ]->adjustment_phrases ) ) {
    foreach ( $feeds[ $feed ]->adjustment_phrases as $phrase => $phrase_weight ) {
      $lowercase_phrase = mb_strtolower( $phrase );
      if ( mb_strpos( $lowercase_title, $lowercase_phrase ) !== false ) {
        $processed[ $link ]['score'] += ($rate_previous === false ? $phrase_weight : -$phrase_weight);
        $adjustment_phrases_found ++;
      }
    }
  }

  if ( $adjustment_phrases_found ) {
    $average_calculation_items_counted ++;
  }

  // adjust score for potential weight from rated authors
  if ($rate_previous === false) {
    $processed[ $link ]['author_interest_average_percent'] = 0;
    $processed[ $link ]['author_interest_average_count']   = 0;
  }

  if ( isset( $processed[ $link ]['author'] ) ) {
    // update and add this author's rating into the final link score
    $author_rating_increase_value = ( ($rate === 1 || $rate === -1) ? 0.1 : 0 );
    $weight_increase              = ($rate_previous === 1 ? -$author_rating_increase_value : ($rate_previous === false ? $author_rating_increase_value : 0));

    try {
      $author_rating = $mongo->bayesian->{'authors-' . USER_HASH}->findOneAndUpdate( [
        'author'  => $processed[ $link ]['author'],
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
          $author_rating = $mongo->bayesian->{'authors-' . USER_HASH}->findOne( [
            'author'  => $processed[ $link ]['author'],
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
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'author' => $processed[ $link ]['author'], 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $weight_increase ] ] );

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

    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'author' => $processed[ $link ]['author'], 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        '$set' => [
          'author_interest_average_percent' => $author_interest_percentage,
          'author_interest_average_count'   => ($rate_previous === false ? 1 : 0)
        ]
      ]
    );

    $processed[ $link ]['author_interest_average_percent'] = $author_interest_percentage;
    $processed[ $link ]['author_interest_average_count']   = ($rate_previous === false ? 1 : 0);

    update_total_interest_change_percentage( [ 'author' => $processed[ $link ]['author'], 'feed' => $feed_object ] );

    // add to the actual score of this link
    $processed[ $link ]['score']                           += ( $author_rating ? $author_rating->weight : $weight_increase );
    $processed[ $link ]['author_interest_average_percent'] = ( $author_rating ? $author_interest_percentage : 100 );
    $average_calculation_items_counted ++;
  }

  // adjust score for any rated categories of this link
  $processed[ $link ]['categories_interest_average_percent'] = 0;
  $processed[ $link ]['categories_interest_total_percent']   = 0;

  if ( isset( $processed[ $link ]['categories'] ) ) {
    // update and add all categories' rating into the final link score
    $category_rate_update_value = ( ($rate === 1 || $rate === -1) ? 0.01 : 0 );
    $weight_increase            = ($rate_previous === 1 ? -$category_rate_update_value : ($rate_previous === false ? $category_rate_update_value : 0));

    foreach ( $processed[ $link ]['categories'] as $category ) {
      try {
        $mongo->bayesian->{'categories-' . USER_HASH}->updateOne(
          [
            'feed'     => $feed_object,
            'category' => $category,
            'ignored'  => [ '$ne' => 1 ]
          ],
          [
            '$inc' => [
              'weight'     => $weight_increase,
              'weightings' => ( $rate_previous === false ? 1 : - 1 ),
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
        $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'categories' => $category, 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $weight_increase ] ] );
      }
    }

    // re-select all categories in the DB, so we can use their current weight to adjust the final score
    $categories_score_increase_value = 0;
    $categories_processed            = 0;
    $categories_weighted_above_zero     = 0;
    $processed_category_names        = [];
    $categories_percentage_changed   = false;
    foreach (
      $mongo->bayesian->{'categories-' . USER_HASH}->find( [
        'feed'       => $feed_object,
        'category'   => [ '$in' => $processed[ $link ]['categories'] ],
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
      $category_percentage                                       = ( $category->weight ? ( ( ( $category->weight / 0.01 ) / $category->weightings ) * 100 ) : 0 );
      $category_interest_percentage_new                          = $category_percentage;
      $processed[ $link ]['categories_interest_average_percent'] += $category_percentage;
      $processed[ $link ]['categories_interest_total_percent']   += $category_percentage;

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
        $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
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
    $processed[ $link ]['score'] += $categories_score_increase_value;

    if ( $categories_processed ) {
      $processed[ $link ]['categories_interest_average_percent'] /= $categories_processed;
      $average_calculation_items_counted ++;
    }

    // if we've upvoted and categories percentage did not change,
    // there is no need to update total interest percentage
    if ( $categories_percentage_changed ) {
      update_total_interest_change_percentage( [ 'categories' => [ '$in' => $processed_category_names ], 'feed' => $feed_object ] );
    }

    $processed[ $link ]['categories_interest_count'] = $categories_weighted_above_zero;

  }

  // adjust words interest average percentage by any n-grams found for this link
  if ($processed[ $link ]['score_increment_from_ngrams_percent'] && $processed_words) {
    $processed[ $link ]['words_interest_average_percent'] += ($processed[ $link ]['score_increment_from_ngrams_percent'] / $processed_words);
  }

  // get total average of all interest percentages, so we can filter by them (i.e. filter by tiers)
  if ( $average_calculation_items_counted ) {
    $processed[ $link ]['interest_average_percent_total'] =
      ( ( $processed[ $link ]['categories_interest_average_percent'] +
          $processed[ $link ]['author_interest_average_percent'] +
          $processed[ $link ]['words_interest_average_percent'] +
          ( isset( $processed[ $link ]['score_increment_from_adjustments'] ) ? ( ( $processed[ $link ]['score_increment_from_adjustments'] / $processed[ $link ]['score'] ) * 100 ) : 0 ) ) / $average_calculation_items_counted );
  } else {
    $processed[ $link ]['interest_average_percent_total'] = 0;
  }

  // calculate conformed score
  $processed[ $link ]['score_conformed'] = (abs($processed[ $link ]['interest_average_percent_total']) * $processed[ $link ]['score']);
  $processed[ $link ]['read'] = 1;

  // we're training this link
  if ($rate_previous === false) {
    $processed[ $link ]['trained'] = 1;
    $processed[ $link ]['rated']   = $rate;
  } else {
    // we're un-training this link
    $processed[ $link ]['trained'] = 0;
    // set this to empty string, as our other queries
    // that update zero scored words will count with its existence
    $processed[ $link ]['rated']   = '';
  }

  $mongo->bayesian->{'training-' . USER_HASH}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $link ) ], [ '$set' => $processed[ $link ] ] );

  if ( count( $unsets ) ) {
    $mongo->bayesian->{'training-' . USER_HASH}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $link ) ], [ '$unset' => $unsets ] );
  }
}

// training
if ( isset( $_GET['link'] ) && isset( $_GET['rate'] ) ) {
  $links = [ $_GET['link'] ];
  $rate  = (int) $_GET['rate'];
  $label_given = 0; // no labels via GET
} else if ( isset( $_POST['links'] ) && isset( $_POST['rate'] ) ) {
  $links = $_POST['links'];
  if ($_POST['rate'] == 'Multi-OK') {
    $rate = 1;
  } else if ($_POST['rate'] == 'Multi-KO') {
    $rate = 0;
  } else if ($_POST['rate'] == 'Multi-READ') {
    $rate = 9;
  }
  $label_given = $_POST['give_label'];
} else {
  $links = $rate = false;
  $label_given = 0;
}

if ( $links && $rate !== false ) {
  $training_time_start = microtime(true);
  foreach ( $links as $link ) {
    $unsets = []; // things to unset, if needed

    if ( isset( $processed[ $link ] ) ) {
      // if we're only making this link read, update and bail out
      if ($rate == 9) {
        $processed[ $link ]['read'] = 1;
        $unsets['label_predictions'] = '';

        if ( $label_given != 0 ) {
          $label_id = new MongoDB\BSON\ObjectId( $label_given );

          // add it to labels for this link
          if ( ! isset( $processed[ $link ]['labels'] ) ) {
            $processed[ $link ]['labels'] = [];
          }

          $label_present = false;
          foreach ( $processed[ $link ]['labels'] as $stored_label ) {
            if ( (string) $stored_label == $label_given ) {
              $label_present = true;
            }
          }

          if ( ! $label_present ) {
            $processed[ $link ]['labels'][] = $label_id;
          }
        }

        $mongo->bayesian->{'training-' . USER_HASH}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $link ) ], [ '$set' => $processed[ $link ] ] );

        if ( count( $unsets ) ) {
          $mongo->bayesian->{'training-' . USER_HASH}->updateOne( [ '_id' => new MongoDB\BSON\ObjectId( $link ) ], [ '$unset' => $unsets ] );
        }
      } else {
        // if this link has already been trained, we need to un-train it first
        if (isset($processed[ $link ]['trained']) && $processed[ $link ]['trained'] == 1) {
          // if we're trying to train the same link the same way, untrain the link
          if ($rate == $processed[ $link ]['rated']) {
            train_link( $link, -1, $processed[ $link ]['rated'] );
            continue;
          }

          train_link( $link, -1, $processed[ $link ]['rated'] );
        }

        // now train the link properly
        train_link( $link, $rate );
      }
    }
  }
  $training_time_end = microtime(true);
  /*echo 'Trained in ' . (round($training_time_end - $training_time_start,3) * 1000).'ms (<span id="reloadTime">2</span>)
  <script>
    setInterval(function() {
      var left = parseInt(document.getElementById(\'reloadTime\').innerHTML);
      document.getElementById(\'reloadTime\').innerHTML = (left - (left > 0 ? 1 : 0));
    }, 1000);

    setTimeout(function() {
      document.location.href = "'.( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ).'";
    }, 2000);
  </script>';*/

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}


// phrase promotion/demotion
if ( isset( $_GET['adjustment'] ) && isset( $_GET['feed'] ) && isset( $_GET['phrase-adjust'] ) ) {
  $adjustment = $_GET['adjustment'];
  switch ( $_GET['phrase-adjust'] ) {
    case 'neg_max' :
      $adjustment_amount = - 10000;
      break;

    case 'neg_3' :
      $adjustment_amount = - 1000;
      break;

    case 'neg_2' :
      $adjustment_amount = - 100;
      break;

    case 'neg_1' :
      $adjustment_amount = - 10;
      break;

    case 'pos_1' :
      $adjustment_amount = 10;
      break;

    case 'pos_2' :
      $adjustment_amount = 100;
      break;

    case 'pos_3' :
      $adjustment_amount = 1000;
      break;

    case 'pos_max' :
      $adjustment_amount = 10000;
      break;

    default:
      $adjustment_amount = 0;
  }
} else if ( isset( $_POST['adjustment'] ) && isset( $_POST['feed'] ) && isset( $_POST['phrase-adjust'] ) ) {
  $adjustment        = $_POST['adjustment'];
  $feed              = $_POST['feed'];
  $feed_object       = new MongoDB\BSON\ObjectId( $feed );
  $adjustment_amount = ( ( $_POST['phrase-adjust'] == 'Promote This Phrase' ) ? 1000 : - 1000 );
}

if ( isset( $adjustment ) ) {
  $adjustment = mb_strtolower( trim( $adjustment ) );

  if ( ! isset( $feeds[ $feed ]->adjustment_phrases ) ) {
    $feeds[ $feed ]->adjustment_phrases = [];
  }

  if ( ! isset( $feeds[ $feed ]->adjustment_phrases[ $adjustment ] ) ) {
    $feeds[ $feed ]->adjustment_phrases[ $adjustment ] = $adjustment_amount;
  } else {
    $feeds[ $feed ]->adjustment_phrases[ $adjustment ] += $adjustment_amount;
  }

  if ( $adjustment_amount ) {
    // update score of all links that this phrase is present in
    // first, select IDs of all such links from the global processed collection
    $ids = [];
    foreach ($mongo->bayesian->processed->find([
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
            '$search' => $adjustment,
          ]
        ],
        // then use regex to search for the exact phrase
        [
          '$or' => [
            [
              'title' => [
                '$regex'   => $adjustment,
                '$options' => 'im'
              ]
            ],
            [
              'description' => [
                '$regex'   => $adjustment,
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
      $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ '_id' => [ '$in' => $ids ] ], [
        '$inc' => [
          'score' => $adjustment_amount,
          'score_increment_from_adjustments' => $adjustment_amount
        ]
      ] );

      // update total interest change percentage
      adjust_percentages_and_score([ '_id' => [ '$in' => $ids ] ]);
    }

    // update the actual phrase
    $mongo->bayesian->{'feeds-' . USER_HASH}->updateOne( [ '_id' => $feed_object ], [ '$set' => [ 'adjustment_phrases' => $feeds[ $feed ]->adjustment_phrases ] ] );
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}


// phrase adjustment removal
if ( isset( $_GET['remove_phrase'] ) ) {
  $update_links_by = 0;
  if ( isset( $feeds[ $feed ]->adjustment_phrases ) && isset( $feeds[ $feed ]->adjustment_phrases[ $_GET['remove_phrase'] ] ) ) {
    // update all links that have this phrase present
    $update_links_by = $feeds[ $feed ]->adjustment_phrases[ $_GET['remove_phrase'] ];
    if ( $update_links_by != 0 ) {
      // first, select IDs of all such links from the global processed collection
      $ids = [];
      foreach ($mongo->bayesian->processed->find([
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
              '$search' => $_GET['remove_phrase'],
            ]
          ],
          // then use regex to search for the exact phrase
          [
            '$or' => [
              [
                'title' => [
                  '$regex'   => $_GET['remove_phrase'],
                  '$options' => 'im'
                ]
              ],
              [
                'description' => [
                  '$regex'   => $_GET['remove_phrase'],
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
        $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ '_id' => [ '$in' => $ids ] ], [
          '$inc' => [
            'score' => - $update_links_by,
            'score_increment_from_adjustments' => - $update_links_by
          ]
        ] );

        // update total interest change percentage
        adjust_percentages_and_score([ '_id' => [ '$in' => $ids ] ]);
      }
    }

    // remove the actual phrase from the feed
    unset( $feeds[ $feed ]->adjustment_phrases[ $_GET['remove_phrase'] ] );
    $mongo->bayesian->{'feeds-' . USER_HASH}->updateOne( [ '_id' => $feed_object ], [ '$set' => [ 'adjustment_phrases' => $feeds[ $feed ]->adjustment_phrases ] ] );
  }

  header( 'Location: ' . $_GET['return'] );
  exit;
}


// words score adjustment
if ( isset( $_GET['adjust'] ) && isset( $_GET['amount'] ) ) {
  switch ( $_GET['amount'] ) {
    case 'neg_max' :
      $amount = - 10000;
      break;

    case 'neg_3' :
      $amount = - 1000;
      break;

    case 'neg_2' :
      $amount = - 100;
      break;

    case 'neg_1' :
      $amount = - 10;
      break;

    case 'pos_1' :
      $amount = 10;
      break;

    case 'pos_2' :
      $amount = 100;
      break;

    case 'pos_3' :
      $amount = 1000;
      break;

    case 'pos_max' :
      $amount = 10000;
      break;

    default:
      $amount = 0;
  }

  $word_id    = new MongoDB\BSON\ObjectId( $_GET['adjust'] );
  $old_record = $mongo->bayesian->{'words-' . USER_HASH}->findOne( [ '_id' => $word_id ], [ 'weight' => 1 ] );

  // don't allow adjusting unrated words, as we cannot calculate percentage for them
  if ( $amount && $old_record->weightings ) {
    $word_data = $mongo->bayesian->{'words-' . USER_HASH}->findOneAndUpdate( [ '_id' => $word_id ], [
      '$inc' => [
        'weight'     => $amount,
        'weight_raw' => $amount
      ]
    ], [ 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER ] );

    // update score for all links with this word scored in them
    // as well as percentage of score adjustments from ngrams
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ],
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
        $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => - 1 ] ] );
      } else if ( $words_interest_average_percent_old < 50 && $words_interest_average_percent_new >= 50 && $word_data->weightings > 2 ) {
        // do the same update as above in reverse, if applicable
        $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => 1 ] ] );
      }
    }

    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ],
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
    adjust_percentages_and_score([ 'words' => $word_id ]);
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}


// words ignoring
if ( isset( $_GET['ignore'] ) ) {
  // update the word itself
  $word_id    = new MongoDB\BSON\ObjectId( $_GET['ignore'] );
  $new_record = $mongo->bayesian->{'words-' . USER_HASH}->findOneAndUpdate( [ '_id' => $word_id ], [ '$set' => [ 'ignored' => 1 ] ] );

  // update all links with this word scored
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => - $new_record->weight ] ] );

  // update all ngrams with this word present
  $ngram_ids_to_update = [];
  $ngrams_to_look_for = $mongo->bayesian->{'ngrams-' . USER_HASH}->find(
    [
      '$and' =>
      [
        [
          'feed' => $feed_object,
        ],
        // first, narrow down results to those that contain our search word
        [
          '$text' => [
            '$search' => $new_record->word,
          ]
        ],
        // then use regex to search for the exact word position
        [
          'ngram' => [ '$regex' => '( ' . $new_record->word . '|' . $new_record->word . ' )' ],
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

  // update all links with the affected n-grams
  foreach ( $ngrams_to_look_for as $ngram ) {
    $ngram_ids_to_update[] = $ngram->_id;
    if (($ngram->weight > 25 && $ngram->weightings > 1)) {
      $ngram_words_count = count( explode(' ', $ngram->ngram) );
      $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'ngrams' => $ngram->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => - ($ngram->weight * $ngram_words_count), 'score_increment_from_ngrams' => - ($ngram->weight * $ngram_words_count) ] ] );
    }
  }

  // set all n-grams found as ignored
  if (count($ngram_ids_to_update)) {
    // recalculate n-grams score increment total percentage
    recalculate_ngrams_total_percentage( $ngram_ids_to_update, USER_HASH );

    // set n-grams as ignored
    $mongo->bayesian->{'ngrams-' . USER_HASH}->updateMany( [ '_id' => [ '$in' => $ngram_ids_to_update ] ], [ '$set' => [ 'ignored' => 1 ] ] );
  }

  // update words interest percentage value for all links where this word exists
  if ($new_record->weight_raw) {
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ],
      [
        [
          '$set' => [
            'words_interest_total_percent' => [
              '$add' => [
                '$words_interest_total_percent',
                - ( ( $new_record->weight_raw / $new_record->weightings ) * 100 )
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
                            - ( ( $new_record->weight_raw / $new_record->weightings ) * 100 )
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
    adjust_percentages_and_score([ 'words' => $word_id ]);

    // update count of words rated above 50% in links where this word is present if needed
    if ($new_record->weightings) {
      $words_interest_average_percent = ( ( $new_record->weight_raw / $new_record->weightings ) * 100 );

      if ( $words_interest_average_percent >= 50 && $new_record->weightings > 2 && (!is_numeric( $new_record->word ) || $scoring_adjustments['number']) ) {
        $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => - 1 ] ] );
      }
    }
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}

// words unignoring
if ( isset( $_GET['unignore'] ) ) {
  // update the word itself
  $word_id    = new MongoDB\BSON\ObjectId( $_GET['unignore'] );
  $new_record = $mongo->bayesian->{'words-' . USER_HASH}->findOneAndUpdate( [ '_id' => $word_id ], [ '$unset' => [ 'ignored' => '' ] ] );

  // update all links with this word scored
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $new_record->weight ] ] );

  // update all ngrams with this word present
  $ngram_ids_to_update = [];
  $ngrams_to_look_for = $mongo->bayesian->{'ngrams-' . USER_HASH}->find(
    [
      '$and' =>
        [
          [
            'feed' => $feed_object,
          ],
          // first, narrow down results to those that contain our search word
          [
            '$text' => [
              '$search' => $new_record->word,
            ]
          ],
          // then use regex to search for the exact word position
          [
            'ngram' => [ '$regex' => '( ' . $new_record->word . '|' . $new_record->word . ' )' ],
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
      $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'ngrams' => $ngram->_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => ($ngram->weight * $ngram_words_count), 'score_increment_from_ngrams' => ($ngram->weight * $ngram_words_count) ] ] );
    }
  }

  // set all ngrams found as ignored
  if (count($ngram_ids_to_update)) {
    // recalculate n-grams score increment total percentage
    recalculate_ngrams_total_percentage( $ngram_ids_to_update, USER_HASH );

    // set n-grams as ignored
    $mongo->bayesian->{'ngrams-' . USER_HASH}->updateMany( [ '_id' => [ '$in' => $ngram_ids_to_update ] ], [ '$unset' => [ 'ignored' => '' ] ] );
  }

  // update words interest percentage value for all links where this word exists
  if ($new_record->weight_raw) {
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ],
      [
        [
          '$set' => [
            'words_interest_total_percent' => [
              '$add' => [
                '$words_interest_total_percent',
                ( ( $new_record->weight_raw / $new_record->weightings ) * 100 )
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
                        ( ( $new_record->weight_raw / $new_record->weightings ) * 100 )
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
    adjust_percentages_and_score([ 'words' => $word_id ]);

    // update count of words rated above 50% in links where this word is present if needed
    if ($new_record->weightings) {
      $words_interest_average_percent = ( ( $new_record->weight_raw / $new_record->weightings ) * 100 );

      if ( $words_interest_average_percent >= 50 && $new_record->weightings > 2 && (!is_numeric( $new_record->word ) || $scoring_adjustments['number']) ) {
        $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'words' => $word_id, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'words_rated_above_50_percent' => 1 ] ] );
      }
    }
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}


// author score adjustments
if ( isset( $_GET['author'] ) && isset( $_GET['adjust'] ) ) {
  switch ( $_GET['adjust'] ) {
    case 'neg_max' :
      $amount = - 00000;
      break;

    case 'neg_3' :
      $amount = - 1000;
      break;

    case 'neg_2' :
      $amount = - 100;
      break;

    case 'neg_1' :
      $amount = - 10;
      break;

    case 'pos_1' :
      $amount = 10;
      break;

    case 'pos_2' :
      $amount = 100;
      break;

    case 'pos_3' :
      $amount = 1000;
      break;

    case 'pos_max' :
      $amount = 10000;
      break;

    default:
      $amount = 0;
  }

  $old_record = $mongo->bayesian->{'authors-' . USER_HASH}->findOne( [ 'author' => urldecode( $_GET['author'] ), 'feed' => $feed_object ], [ 'projection' => [ 'weight' => 1, 'weightings' => 1 ] ] );

  // don't allow adjusting unrated authors, as we cannot calculate percentage for them
  if ( $amount && $old_record->weightings ) {
    $mongo->bayesian->{'authors-' . USER_HASH}->updateOne( [ 'author' => urldecode( $_GET['author'] ), 'feed' => $feed_object ], [ '$inc' => [ 'weight' => $amount ] ] );

    // update all links with this author
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'author' => urldecode( $_GET['author'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $amount ] ] );

    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'author' => urldecode( $_GET['author'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        '$set' => [ 'author_interest_average_percent' => (( (($old_record->weight + $amount) / 0.1) / $old_record->weightings ) * 100)]
      ]
    );

    // update total interest change percentage
    adjust_percentages_and_score([ 'author' => urldecode( $_GET['author'] ), 'feed' => $feed_object ]);
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}

// author ignoring
if ( isset( $_GET['ignore_author'] ) ) {
  $new_record = $mongo->bayesian->{'authors-' . USER_HASH}->findOneAndUpdate( [ 'author' => urldecode( $_GET['ignore_author'] ), 'feed' => $feed_object ], [ '$set' => [ 'ignored' => 1 ] ], [ 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER ] );

  // update all links with this author
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'author' => urldecode( $_GET['ignore_author'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
    [
      '$set' => [
        'author_interest_average_percent' => 0
      ] ,
      '$inc' => [
        'score' => - $new_record->weight
      ]
    ]
  );

  // decrease interest average count for this author in all links where they're present
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'author' => urldecode( $_GET['ignore_author'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
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
  adjust_percentages_and_score([ 'author' => urldecode( $_GET['ignore_author'] ), 'feed' => $feed_object ]);

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}

// author unignoring
if ( isset( $_GET['unignore_author'] ) ) {
  $new_record = $mongo->bayesian->{'authors-' . USER_HASH}->findOneAndUpdate( [ 'author' => urldecode( $_GET['unignore_author'] ), 'feed' => $feed_object ], [ '$unset' => [ 'ignored' => '' ] ], [ 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER ] );

  // update all links with this author
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'author' => urldecode( $_GET['unignore_author'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
    [
      '$set' => [
        'author_interest_average_percent' => (( ($new_record->weight / 0.1) / $new_record->weightings ) * 100)
      ],
      '$inc' => [
        'score' => $new_record->weight
      ]
    ]
  );

  // increase interest average count for this author in all links where they're present
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'author' => urldecode( $_GET['unignore_author'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
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
  adjust_percentages_and_score([ 'author' => urldecode( $_GET['unignore_author'] ), 'feed' => $feed_object ]);

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}


// category score adjustments
if ( isset( $_GET['category'] ) && isset( $_GET['adjust'] ) ) {
  switch ( $_GET['adjust'] ) {
    case 'neg_max' :
      $amount = - 10000;
      break;

    case 'neg_3' :
      $amount = - 1000;
      break;

    case 'neg_2' :
      $amount = - 100;
      break;

    case 'neg_1' :
      $amount = - 10;
      break;

    case 'pos_1' :
      $amount = 10;
      break;

    case 'pos_2' :
      $amount = 100;
      break;

    case 'pos_3' :
      $amount = 1000;
      break;

    case 'pos_max' :
      $amount = 10000;
      break;

    default:
      $amount = 0;
  }

  $old_record = $mongo->bayesian->{'categories-' . USER_HASH}->findOne( [ 'category' => urldecode( $_GET['category'] ), 'feed' => $feed_object ], [ 'projection' => [ 'weight' => 1, 'weightings' => 1 ] ] );

  // don't allow adjusting unrated categories, as we cannot calculate percentage for them
  if ( $amount && $old_record->weightings ) {
    $mongo->bayesian->{'categories-' . USER_HASH}->updateOne( [ 'category' => urldecode( $_GET['category'] ), 'feed' => $feed_object ], [ '$inc' => [ 'weight' => $amount ] ] );

    // update all links with this category
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'categories' => urldecode( $_GET['category'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $amount ] ] );

    $category_interest_percentage_new    = ( ( ( ( ($old_record->weight + $amount) / 0.01) ) / $old_record->weightings ) * 100 );
    $category_interest_percentage_old    = ( ( ( $old_record->weight / 0.01 ) / $old_record->weightings ) * 100 );

    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'categories' => urldecode( $_GET['category'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
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
    adjust_percentages_and_score([ 'categories' => urldecode( $_GET['category'] ), 'feed' => $feed_object ]);
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}

// category ignoring
if ( isset( $_GET['ignore_category'] ) ) {
  $new_record = $mongo->bayesian->{'categories-' . USER_HASH}->findOneAndUpdate( [ 'category' => urldecode( $_GET['ignore_category'] ), 'feed' => $feed_object ], [ '$set' => [ 'ignored' => 1 ] ] );

  // update all links with this category
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'categories' => urldecode( $_GET['ignore_category'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => - $new_record->weight ] ] );

  // update categories interest percentage value for all links where this category exists
  if ($new_record->weight) {
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'categories' => urldecode( $_GET['ignore_category'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        [
          '$set' => [
            'categories_interest_total_percent' => [
              '$add' => [
                '$categories_interest_total_percent',
                - ( ( ( ( $new_record->weight / 0.01) ) / $new_record->weightings ) * 100 )
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
                        - ( ( ( ( $new_record->weight / 0.01) ) / $new_record->weightings ) * 100 )
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
    adjust_percentages_and_score([ 'categories' => urldecode( $_GET['ignore_category'] ), 'feed' => $feed_object ]);
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}

// category unignoring
if ( isset( $_GET['unignore_category'] ) ) {
  $new_record = $mongo->bayesian->{'categories-' . USER_HASH}->findOneAndUpdate( [ 'category' => urldecode( $_GET['unignore_category'] ), 'feed' => $feed_object ], [ '$unset' => [ 'ignored' => '' ] ] );

  // update all links with this category
  $mongo->bayesian->{'training-' . USER_HASH}->updateMany( [ 'categories' => urldecode( $_GET['unignore_category'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ], [ '$inc' => [ 'score' => $new_record->weight ] ] );

  // update categories interest percentage value for all links where this category exists
  if ($new_record->weight) {
    $mongo->bayesian->{'training-' . USER_HASH}->updateMany(
      [ 'categories' => urldecode( $_GET['unignore_category'] ), 'feed' => $feed_object, 'archived' => [ '$ne' => 1 ] ],
      [
        [
          '$set' => [
            'categories_interest_total_percent' => [
              '$add' => [
                '$categories_interest_total_percent',
                ( ( ( ( $new_record->weight / 0.01) ) / $new_record->weightings ) * 100 )
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
                    ( ( ( ( $new_record->weight / 0.01) ) / $new_record->weightings ) * 100 )
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
    adjust_percentages_and_score([ 'categories' => urldecode( $_GET['unignore_category'] ), 'feed' => $feed_object ]);
  }

  header( 'Location: ' . ( isset( $_GET['return'] ) ? $_GET['return'] : urldecode( $_POST['return'] ) ) );
  exit;
}


if ( ! ini_get( 'date.timezone' ) ) {
  date_default_timezone_set( 'Europe/Prague' );
}

$display_time_start = microtime(true);

// depending on how we decided to sort our links, create the sorted array
if ($order == 'tiers') {
  $sorted = [
    0 => [],
    1 => [],
	2 => [],
	3 => [],
	4 => []
  ];
} else {
  $sorted = [
    0 => [],
    1 => []
  ];
}

foreach ( $processed as $index => $key ) {
  // assign tiers to each link for advanced hiding purposes to this link
  // tier 5 = scoring percentage above 50%
  // tier 4 = scoring percentage above 30%
  // tier 3 = scoring percentage above 10%
  // tier 2 = scoring percentage above 0%
  // tier 1 = scoring percentage at 0%
  if ( $key['interest_average_percent_total'] <= 5 ) {
    $processed[$index]['tier'] = 1;
  } else if ( $key['interest_average_percent_total'] > 5 && $key['interest_average_percent_total'] < 10 ) {
    $processed[$index]['tier'] = 2;
  } else if ( $key['interest_average_percent_total'] >= 10 && $key['interest_average_percent_total'] < 30 ) {
    $processed[$index]['tier'] = 3;
  } else if ( $key['interest_average_percent_total'] >= 30 && $key['interest_average_percent_total'] < 50 ) {
    $processed[$index]['tier'] = 4;
  } else if ( $key['interest_average_percent_total'] >= 50 ) {
    $processed[$index]['tier'] = 5;
  }

  // if this link has at least a single word with 50+% rating score and it's been rated below tier 4,
  // make this link's tier automatically 4, as we want to show links where we have words upvoted that high
  // more prominently
  // ... just don't do this if a word, phrase, author or category for this link has been manually
  //     adjusted to negative values, as that's an indication that the user really don't want to see that
  //     in their listings
  // ... note: this will not change the sorting for this link, it will only make sure
  //           that we don't miss-out any potential high profile links, albeit for a cost
  //           of a potential false positive (which is much better than hiding a false negative!)
  if ($key['words_rated_above_50_percent'] > 0 && $processed[$index]['tier'] < 4 && $key['interest_average_percent_total'] >= 0) {
    $processed[$index]['tier'] = 4;
  }
}

$detailed_cache = [];
foreach ( $processed as $index => $key ) {
  // check if we wanted to divide the sorting by zero-rated-words appearance or not
  if ($order == 'date-zero_words' || $order == 'score-zero_words' ) {
    if ( $key['zero_scored_words'] > 0 || $key['score'] < 0 ) {
      $sorted[ 0 ][] = $key;
    } else {
      $sorted[ 1 ][] = $key;
    }
  } else {
    // no dividing, simply sort as they come from the DB
  if ($order == 'tiers') {
    $sorted[ $processed[$index]['tier'] - 1 ][] = $key;
  } else {
    $sorted[ 1 ][] = $key;
    }
  }
}

if ( count( $sorted[ 0 ] ) || count( $sorted[ 1 ] ) || (isset( $sorted[ 2 ] ) && count( $sorted[ 2 ] ) ) || (isset( $sorted[ 3 ] ) && count( $sorted[ 3 ] ) ) || (isset( $sorted[ 4 ] ) && count( $sorted[ 4 ] ) ) ) {
  echo '
        <ul>';
  for ( $i = count(array_keys($sorted)) - 1; $i >= 0; $i -- ) {
    foreach ( $sorted[ $i ] as $item ) {
      if ( $display_details ) {
        $words                   = parse_words( $item['title'], $feeds[ $feed ]['lang'] );
        $detailed_score          = calculate_score( USER_HASH, $words, (string) $item['_id'], false, false );
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

        // surround found words with spans
        $title     = explode( ' ', str_replace( ' ', ' ', $item['title'] ) ); // replace non-breaking spaces by normal ones
        $skip_next = false;

        foreach ( $title as $word_index => $word ) {
          if ( $skip_next ) {
            // end the highlight span which goes over 2 words
            $title[ $word_index ] .= '</span>';
            $skip_next            = false;
            continue;
          }

          $word      = trim( sanitize_title_or_word( $word, $feeds[ $feed ]['lang'], true ) );
          $next_word = ( isset( $title[ $word_index + 1 ] ) ? mb_strtolower( $title[ $word_index + 1 ] ) : '' );

          // check if we don't have a measurement unit number as word
          if ( is_numeric( $word ) ) {
            if ( $next_word && in_array( $next_word, $measurement_units_array ) ) {
              $skip_next = true;
              $word      = $word . $next_word;
            }
          }

          // find this word in scores
          $skip_next_compound_word = false;
          foreach ( $detailed_score['words_details'] as $detail_word => $detail ) {
            // hex-convert the previously-alpha background
            if ($detail['ignored']) {
              $color = '#F4F4F4';
            } else {
              $rgb = [ $detail['color']['red'], $detail['color']['green'], 0 ];
              $color = adjustBrightness( rgb2hex( $rgb ), 0.7);
            }

            $span_html = '<span style="background-color:' . $color . '" title="' . ( $detail['ignored'] ? 'word is ignored from scorings' : $detail['score'] . ' (' . $detail['percentage'] . '% of ' . $detail['weight'] . '(weight/'.$detail['weightings'].' weightings/)' . ( $detail['scoring_adjustment'] ? '+' . $detail['scoring_adjustment'] . '(adjust)' : '' ) . ( isset( $detail['ngram_adjustments'] ) && $detail['ngram_adjustments'] > 25 ? '+' . $detail['ngram_adjustments'] . '(ngram)' : '' ) . ')' ) . '">' . $title[ $word_index ] . ( ! $skip_next ? '</span>' : '' );
            // if we have a space in this word, is means it's something like ABC.COM,
            // which we'll need to split here and compare
            if ( strpos( $word, ' ' ) !== false ) {
              if ( $skip_next_compound_word ) {
                $skip_next_compound_word = false;
                continue;
              }

              $w = explode( ' ', $word );
              if ( $w[0] == $detail_word || $w[1] == $detail_word ) {
                if ( $w[1] == $detail_word ) {
                  $skip_next_compound_word = true;
                }
                // surround the word with a span
                $title[ $word_index ] = $span_html;
              }
            } else {
              if ( $word == $detail_word ) {
                // surround the word with a span
                $title[ $word_index ] = $span_html;
              }
            }
          }
        }

        $title = implode( ' ', $title );
      } else {
        $title = $item['title'];
      }

      // normalize saved date
      if ( isset( $item['date'] ) && ! is_string( $item['date'] ) ) {
        $item['date'] = ( (array) $item['date'] )[0];
      }

      // calculate labels and main label
      $label           = '';
      $label_text      = '';
      $label_top_score = 0;
      $guessed_labels  = [];

      if ( $display_details && isset( $item['label_predictions'] ) && ! isset( $item['labels'] ) ) {
        foreach ( $item['label_predictions'] as $label_prediction ) {
          $guessed_labels[] = $label_prediction['label'] . ' (' . $label_prediction['probability'] . '%)';

          if ( $label_prediction['probability'] > $label_top_score ) {
            $label_top_score = $label_prediction['probability'];
            if ( $label_top_score > 80 ) {
              $label      = '<span style="background-color:#'.($label_top_score == 110 ? '000000' : '5E5E5E').'; color:#fff" title="label for this link guessed at ' . $label_top_score . '%" onclick="(function(event) { choose_label_action(event, \'' . $label_prediction['label'] . '\'); })(event)">&nbsp;' . $label_prediction['label'] . '&nbsp;</span> ';
              $label_text = $label_prediction['label'];
            }
          }
        }
      } else if ( isset( $item['labels'] ) ) {
        $labels_to_get = [];
        $l_array       = [];

        foreach ( $item['labels'] as $l ) {
          if ( ! isset( $cached_labels[ $feed_object . $l ] ) ) {
            $labels_to_get[] = $l;
          } else {
            $l_array[] = '<span style="background-color:#000; color:#fff" title="label">&nbsp;' . $cached_labels[ $feed_object . $l ]->label . '&nbsp;</span> ';
          }
        }

        if ( count( $labels_to_get ) ) {
          foreach (
            $mongo->bayesian->{'labels-' . USER_HASH}->find( [
              '_id' => [ '$in' => $labels_to_get ]
            ], [ 'projection' => [  '_id' => 1, 'label' => 1 ] ] ) as $l
          ) {
            $l_array[]                               = '<span style="background-color:#000; color:#fff" title="label">&nbsp;' . $l->label . '&nbsp;</span> ';
            $cached_labels[ $feed_object . $l->_id ] = $l;
          }
        }

        $label = implode( ' ', $l_array );
      }

      // adjust all T2 tiers to T2+ if we're showing only tier 3 links and this is a link scored at T2
      // but with score above this feed's average
      if ($item['tier'] == 2 && $item['score'] >= $feed_average_score) {
        $item['tier'] = '2+';
      }

      echo '
              <li onmouseup="passSelection(this)" id="link-' . $item['_id'] . '">
                  <input type="checkbox" name="links[]" id="' . $item['_id'] . '" class="cbox'.($label_text ? ' label-'.$label_text : '').'" value="' . $item['_id'] . '" />
                  <label for="' . $item['_id'] . '" onclick="(function(event) { if (event.ctrlKey) { if (document.getElementById(\'details-' . $item['_id'] . '\').style.display == \'none\') { document.getElementById(\'details-' . $item['_id'] . '\').style.display = \'block\'; } else { document.getElementById(\'details-' . $item['_id'] . '\').style.display = \'none\'; } } })(event)">
                  ' . ( isset( $item['date'] ) ? '<span title="published: ' . date( 'j.m.Y H:i', ( is_string( $item['date'] ) ? strtotime( $item['date'] ) : $item['date'] ) ) . '">' . date( 'H:i', ( is_string( $item['date'] ) ? strtotime( $item['date'] ) : $item['date'] ) ) . '</span>, ' : '' ) .
           ( isset( $item['fetched'] ) ? '<span title="fetched: ' . date( 'j.m.Y H:i', $item['fetched'] ) . '">' . date( 'H:i', $item['fetched'] ) . '</span>, ' : '' ) .
           '<strong>(' . round($item['score_conformed'], 2) . '</strong>) (' . $item['score'] . ') (' . round( $item['interest_average_percent_total'], 2 ) . ') (' . $item['zero_scored_words'] . ') (r'.(isset($item['rated']) && $item['rated'] !== '' ? $item['rated'] : 'X').') (T' . $item['tier'] . ') ' . $label . $title . '</label>
            &raquo; <a href="index-new.php?link=' . $item['_id'] . '&amp;rate=1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '">OK</a> / <a href="index-new.php?link=' . $item['_id'] . '&amp;rate=0&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '">KO</a>'.(! $item['read'] ? ' / <a href="index-new.php?link=' . $item['_id'] . '&amp;rate=9&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '">READ</a>' : '');

      if ( $display_details ) {
        echo '
                  <ul class="word-details" id="details-' . $item['_id'] . '" style="display:none">
                      <li><a href="' . $item['link'] . '" target="_blank">' . $item['link'] . '</a> ['.$item['_id'].']</li>';

        if (isset($item['label_predictions']) && count($guessed_labels)) {
            echo '
                      <li>labels: '.implode(' | ', $guessed_labels).'</li>';
          }

        foreach ( $detailed_score['words_details'] as $detail_word => $detail ) {
          // hex-convert the previously-alpha background
          if ($detail['ignored']) {
            $color = '#F4F4F4';
          } else {
            $rgb = [ $detail['color']['red'], $detail['color']['green'], 0 ];
            $color = adjustBrightness( rgb2hex( $rgb ), 0.7);
          }

          echo '
                      <li>
                          <span style="background-color:' . $color . '">' . $detail_word . '</span> = ';

          if ( $detail['ignored'] ) {
            echo 'word is ignored from scoring ... 
                          <a href="index-new.php?unignore=' . $detail['_id'] . '&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="return word to be scored again">&laquo; score again &raquo;</a>';
          } else {
            echo ($detail['score'] * $detail['count']) . ($detail['count'] > 1 ? ' [' . $detail['score'] . 'x' . $detail['count'] . '][score*count]' : '') . ' 
                          <small>(' . $detail['percentage'] . '% of ' . $detail['weight'] . ' (weight/'.$detail['weightings'].' weightings/)' .
                 ( $detail['scoring_adjustment'] ? '+' . $detail['scoring_adjustment'] . '(adjust)' : '' ) .
                 ( isset( $detail['ngram_adjustments'] ) && $detail['ngram_adjustments'] > 25 ? '+' . $detail['ngram_adjustments'] . '(ngrams)' : '' ) . ')
                          </small>'.($detail['weightings'] > 0 ? '
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=neg_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower word weight by 10 00">&lt;&lt;&lt;&lt;</a> | 
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=neg_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower word weight by 1000">&lt;&lt;&lt;</a> |
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=neg_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower word weight by 100">&lt;&lt;</a> |
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=neg_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower word weight by 10">&lt;</a> |
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=pos_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise word weight by 10">&gt;</a> |
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=pos_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise word weight by 100">&gt;&gt;</a> |
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=pos_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise word weight by 1000">&gt;&gt;&gt;</a> |
                          <a href="index-new.php?adjust=' . $detail['_id'] . '&amp;amount=pos_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise word weight by 10 000">&gt;&gt;&gt;&gt;</a> |
                          <a href="index-new.php?ignore=' . $detail['_id'] . '&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="ignore word from scoring">X</a>' : ' ... rate link to enable per-word adjustments');
          }

          echo '
                      </li>';
        }

        // list all adjustment phrases for this link
        $lowercase_title = mb_strtolower( $item['title'] );
        if ( isset( $feeds[ $feed ]->adjustment_phrases ) && count( $feeds[ $feed ]->adjustment_phrases ) ) {
          foreach ( $feeds[ $feed ]->adjustment_phrases as $phrase => $phrase_weight ) {
            if ( strpos( $lowercase_title, $phrase ) !== false ) {
              echo '
                      <li>
                          <span style="background-color:' . ( $phrase_weight >= 0 ? '#00FF00' : '#FF0000' ) . '">' . $phrase . '</span> = ' . $phrase_weight . ' 
                          <small>(user-defined adjustment phrase)</small>
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=neg_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower phrase weight by 10 00">&lt;&lt;&lt;&lt;</a> | 
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=neg_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower phrase weight by 1000">&lt;&lt;&lt;</a> |
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=neg_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower phrase weight by 100">&lt;&lt;</a> |
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=neg_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower phrase weight by 10">&lt;</a> |
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=pos_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise phrase weight by 10">&gt;</a> |
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=pos_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise phrase weight by 100">&gt;&gt;</a> |
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=pos_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise phrase weight by 1000">&gt;&gt;&gt;</a> |
                          <a href="index-new.php?adjustment=' . $phrase . '&amp;feed=' . $feed . '&amp;phrase-adjust=pos_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise phrase weight by 10 000">&gt;&gt;&gt;&gt;</a> |
                          <a href="index-new.php?remove_phrase=' . $phrase . '&amp;feed=' . $feed . '&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="remove this adjustment phrase from this feed">X</a>
                      </li>';
            }
          }
        }

        // list a scored author for this link
        if ( isset( $item['author'] ) ) {
          foreach ( $scored_authors as $author => $author_record ) {
            if ( $item['author'] == $author ) {
              if ( isset( $author_record->ignored ) && $author_record->ignored ) {
                echo 'author (' . $author . ') is ignored from scoring ... 
                          <a href="index-new.php?unignore_author=' . urlencode( $author ) . '&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="return author to be scored again">&laquo; score again &raquo;</a>';
              } else {
                echo '
                      <li>
                          <span style="background-color:' . ( $author_record->weight >= 0 ? '#00FF00' : '#FF0000' ) . '">' . $author . '</span> = ' . $author_record->weight . ' 
                          <small>(article\'s author)</small>'.($author_record['weightings'] ? '
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=neg_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower author\'s scoring by 10 000">&lt;&lt;&lt;&lt;</a> | 
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=neg_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower author\'s scoring by 1000">&lt;&lt;&lt;</a> |
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=neg_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower author\'s scoring by 100">&lt;&lt;</a> |
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=neg_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower author\'s scoring by 10">&lt;</a> |
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=pos_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise author\'s scoring by 10">&gt;</a> |
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=pos_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise author\'s scoring by 100">&gt;&gt;</a> |
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=pos_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise author\'s scoring by 1000">&gt;&gt;&gt;</a> |
                          <a href="index-new.php?author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;adjust=pos_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise author\'s scoring by 10 000">&gt;&gt;&gt;&gt;</a> |
                          <a href="index-new.php?ignore_author=' . urlencode( $author ) . '&amp;feed=' . $feed . '&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="ignore this author from all scoring">X</a>' : ' ... rate link to enable per-author adjustments').'
                      </li>';
              }
            }
          }
        }

        // list all scored categories for this link
        if ( isset( $item->categories ) ) {
          foreach ( $scored_categories as $category => $category_record ) {
            foreach ( $item->categories as $item_category ) {
              if ( $item_category == $category ) {
                if ( isset( $category_record->ignored ) && $category_record->ignored ) {
                  echo 'category (' . $category . ') is ignored from scoring ... 
                          <a href="index-new.php?unignore_category=' . urlencode( $category ) . '&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="return category to be scored again">&laquo; score again &raquo;</a>';
                } else {
                  echo '
                      <li>
                          <span style="background-color:' . ( $category_record->weight >= 0 ? '#00FF00' : '#FF0000' ) . '">' . $category . '</span> = ' . $category_record->weight . ' 
                          <small>(scored article\'s category)</small>'.($category_record->weightings ? '
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=neg_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower category\'s scoring by 10 000">&lt;&lt;&lt;&lt;</a> | 
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=neg_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower category\'s scoring by 1000">&lt;&lt;&lt;</a> |
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=neg_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower category\'s scoring by 100">&lt;&lt;</a> |
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=neg_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="lower category\'s scoring by 10">&lt;</a> |
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=pos_1&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise category\'s scoring by 10">&gt;</a> |
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=pos_2&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise category\'s scoring by 100">&gt;&gt;</a> |
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=pos_3&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise category\'s scoring by 1000">&gt;&gt;&gt;</a> |
                          <a href="index-new.php?category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;adjust=pos_max&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="raise category\'s scoring by 10 000">&gt;&gt;&gt;&gt;</a> |
                          <a href="index-new.php?ignore_category=' . urlencode( $category ) . '&amp;feed=' . $feed . '&amp;return=' . urlencode( $_SERVER['REQUEST_URI'] ) . '" title="ignore this category from all scoring">X</a>' : ' ... rate link to enable per-category adjustments').'
                      </li>';
                }
              }
            }
          }
        }

        // TODO: fix, it's form in form now, which breaks the main multi-OK/KO form
        /*echo '
                      <li>
                          <form action="index-new.php" method="post">
                              <input type="hidden" name="return" value="'.urlencode($_SERVER['REQUEST_URI']).'" />
                              <input type="hidden" name="feed" value="'.$_GET['feed'].'" />
                              <input type="text" id="phrase-adjust-'.$item['_id'].'" placeholder="type of select text" name="adjustment" size="40" value="" /> <input type="submit" name="phrase-adjust" value="Promote This Phrase" /> | <input type="submit" name="phrase-adjust" value="Demote This Phrase" />
                          </form>
                      </li>';*/

        echo '
                  </ul>';
      }
      echo '
              </li>';
      //if ($item['title'] == 'SoftvÃ©r, hry Äi sluÅ¾by operÃ¡torov zadarmo: VeÄ¾kÃ½ prehÄ¾ad akciÃ­ (aktualizovanÃ© v utorok)') exit;
    }
  }
  echo '
        </ul>
        <input type="checkbox" name="ignored" id="ignored" onclick="check_uncheck_all(this)" /> <label for="ignored">Un/Check All</label>
        <br><br>
        Give Label: <select id="give_label" name="give_label">
          <option value="0"' . ( (! isset( $_GET['label']  ) || $_GET['label'] == '0') ? ' selected' : '' ) . '>- none -</option>';

        foreach ( $labels as $label_data ) {
          echo '
          <option value="' . $label_data->_id . '"' . ( ((!empty($_GET['label']) && $_GET['label'] == $label_data->_id )) ? ' selected' : '' ) . '>' . $label_data->label . '</option>';
        }
        echo '
        </select>
        <br>
        <br>
        <input type="submit" name="rate" id="multi_ok" value="Multi-OK" /> | <input type="submit" name="rate" id="multi_ko" value="Multi-KO" /> | <input type="submit" name="rate" id="multi_read" value="Multi-READ" />' . ( $display_details ? ' | <input type="button" value="Expand All" onclick="for (let ul of document.getElementsByClassName(\'word-details\')) { ul.style.display = \'block\'; }" /> | <input type="button" value="Collapse All" onclick="for (let ul of document.getElementsByClassName(\'word-details\')) { ul.style.display = \'none\'; }" />' : '' ) . '
    </form>';
} else {
  echo 'nothing to display';
}

echo '
	<script>
        document.onkeydown = function(e){
          if(e.ctrlKey && e.keyCode == \'A\'.charCodeAt(0)){
            // don\'t act if there\'s an input active
            if (! (document.activeElement.tagName == \'INPUT\' && document.activeElement.type == \'text\') ) {
              e.preventDefault();
              check_uncheck_all();
            }
          } else if (e.ctrlKey && !e.shiftKey && e.keyCode == 37) {
              // left arrow key
              if (document.getElementById(\'feeds_list\').selectedIndex > 0) {
                document.getElementById(\'prev_rss\').click();
              }
          } else if (e.ctrlKey && e.shiftKey && e.keyCode == 37) {
              // left arrow key -> switch to previous feed in the dropdown
              if (document.getElementById(\'feeds_list\').selectedIndex > 0) {
                document.getElementById(\'feeds_list\').selectedIndex = document.getElementById(\'feeds_list\').selectedIndex - 1;
              }
          } else if (e.ctrlKey && !e.shiftKey && e.keyCode == 39) {
              // right arrow key
			  if (document.getElementById(\'feeds_list\').selectedIndex < document.getElementById(\'feeds_list\').options.length - 1) {
                document.getElementById(\'next_rss\').click();
              }
          } else if (e.ctrlKey && e.shiftKey && e.keyCode == 39) {
              // right arrow key -> switch to next feed in the dropdown
              if (document.getElementById(\'feeds_list\').selectedIndex < document.getElementById(\'feeds_list\').options.length - 1) {
                document.getElementById(\'feeds_list\').selectedIndex = document.getElementById(\'feeds_list\').selectedIndex + 1;
              }
          } else if (e.keyCode == 109) {
              // minus key on numerical keypad
              document.getElementById(\'multi_ko\').click();
          } else if (e.keyCode == 107) {
              // plus key on numerical keypad
              document.getElementById(\'multi_ok\').click();
          } else if (e.keyCode == 106) {
              // star key on numerical keypad
              document.getElementById(\'multi_read\').click();
          } else if (e.shiftKey && e.keyCode == 82) {
              // shift + R -> RSS feed
              document.getElementById(\'rss\').selectedIndex = (document.getElementById(\'rss\').selectedIndex == 1 ? 0 : 1);
          } else if (e.ctrlKey && e.keyCode == 13) {
              // ctrl + Enter -> filters form OK button
              document.getElementById(\'filters_submit\').click();
          }
          //console.log(e.keyCode);
        }

        function check_uncheck_all(element) {
            var multi_cbox = document.getElementById(\'ignored\');

            if (multi_cbox) {
              // update checkbox state if coming from a shortcut
              if (typeof(element) == \'undefined\') {
                multi_cbox.checked = !multi_cbox.checked;
              }

              for (let box of document.getElementsByClassName(\'cbox\')) {
                  box.checked = multi_cbox.checked;
              }
            }
        }

        function passSelection(li) {
            var
                id = li.id.replace(\'link-\', \'\'),
                t = \'\';

            if (window.getSelection) {
                t = window.getSelection();
            } else if (document.getSelection) {
                t = document.getSelection();
            } else if (document.selection) {
                t = document.selection.createRange().text;
            }

            if (\'\' + t) {
                document.getElementById(\'phrase-adjust-\' + id).value = t;
            }
        }

        function choose_label_action(event, txt) {
          if (event.shiftKey) {
            // select all links with this label as main
            for (let cbox of document.getElementsByClassName(\'label-\' + txt)) {
              cbox.checked = true;
            }
		    } 

        // select this label in the Set Label dropdown
        let dropdown = document.getElementById(\'give_label\'); 
        for (let i = 0; i < dropdown.options.length; i++) {
          if( dropdown.options[i].text == txt) {
            dropdown.options[i].selected = true;
          }
        }
        }';

if (isset($_GET['refresh'])) {
  echo '

        setInterval(function() {
          // check if there is nothing being trained
          var training_in_progress = false;

          for (let box of document.getElementsByClassName(\'cbox\')) {
              if (box.checked) {
                training_in_progress = true;
                break;
              }
          }

          if (!training_in_progress) {
            document.location.href = document.location.href;
          }
        }, '.($_GET['refresh'] * 1000).');';
}    

echo '
    </script>';

$display_time_end = microtime(true);
ob_end_flush();

echo '<hr /><strong>Timings:</strong> '
     . (round($select_time_end - $select_time_start,3) * 1000) . 'ms select, '
     . (round($display_time_end - $display_time_start,3) * 1000) . 'ms display, ';
?>