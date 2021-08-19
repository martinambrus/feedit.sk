<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";
  require_once "../functions/functions-feeds.php";
  use LanguageDetection\Language;
  use JDecool\JsonFeed\Reader\ReaderBuilder;

  if (empty($_POST['url'])) {
    send_error($lang['Missing parameter'], $lang['Feed URL Empty'], 400, 'validation', ['api' => 'feeds', 'field' => 'url']);
  }

  // if our URL doesn't have a HTTP or HTTPS prefix, add one
  $_POST['url'] = (string) $_POST['url'];
  if (!preg_match('/http(s)?:\/\//i', $_POST['url'])) {
    $_POST['url'] = 'https://' . $_POST['url'];
  }

  $data = [];
  $lang_detected = 'other';
  $langs_detected = [];
  $long_rss = false;
  $is_json_feed = false;
  $ld = new Language;

  // try a discovery of feeds using SimplePie
  $url_data = fetch_url( (string) $_POST['url'] );

  if (!is_array($url_data)) {
    // check that this is not a JSON feed
    $json = json_decode( $url_data );
    if ($json !== null) {
      $is_json_feed = true;

      // we have a JSON feed - it can still be set to false
      if ( $json !== false ) {
        $builder = new ReaderBuilder();
        $reader = $builder->build();
        $feed = $reader->createFromJson( $url_data );

        $l = $ld->detect( $feed->getTitle() . '. ' . $feed->getDescription() )->bestResults()->__toString();
        if ( ! isset( $langs_detected[ $l ] ) ) {
          $langs_detected[ $l ] = 0;
        }

        $langs_detected[ $l ] ++;

        $data = [
          ($feed->getFeedUrl() ? $feed->getFeedUrl() : $_POST['url']) =>
            [
              'title' => ($feed->getTitle() ? $feed->getTitle() : $_POST['url']),
              'url'   => ($feed->getFeedUrl() ? $feed->getFeedUrl() : $_POST['url']),
            ],
        ];

        // run through at least 3 items to make the language detection actually work a little,
        // as blogs like "jwl.org" would otherwise come up as Polish (although it's an English blog)
        $counter = 0;
        if ( $feed->getItems() && count( $feed->getItems() ) > 100 ) {
          $long_rss = true;
        }

        foreach ( $feed->getItems() as $item ) {
          if ( ++ $counter > 3 ) {
            break;
          }

          $l = $ld->detect( untagize( $item->getTitle() ) )->bestResults()->__toString();
          if ( ! isset( $langs_detected[ $l ] ) ) {
            $langs_detected[ $l ] = 0;
          }

          $langs_detected[ $l ] ++;
        }
      }
    } else {
      // no errors, feed the data to SimplePie
      $feed = get_feed( null, $url_data );
      if ( ! $feed->error() ) {

        $l = $ld->detect( $feed->get_title() . '. ' . $feed->get_description() )->bestResults()->__toString();
        if ( ! isset( $langs_detected[ $l ] ) ) {
          $langs_detected[ $l ] = 0;
        }

        $langs_detected[ $l ] ++;

        $data = [
          $feed->feed_url =>
            [
              'title' => ($feed->get_title() ? $feed->get_title() : $_POST['url']),
              'url'   => ($feed->feed_url ? $feed->feed_url : $_POST['url']),
            ],
        ];

        // run through at least 3 items to make the language detection actually work a little,
        // as blogs like "jwl.org" would otherwise come up as Polish (although it's an English blog)
        try {
          $counter = 0;
          if ( get_class( $feed ) == 'SimplePie' ) {
            if ( count( $feed->get_items() ) > 100 ) {
              $long_rss = true;
            }

            foreach ( $feed->get_items() as $item ) {
              if ( ++ $counter > 3 ) {
                break;
              }

              $l = $ld->detect( untagize( $item->get_title() ) )->bestResults()->__toString();
              if ( ! isset( $langs_detected[ $l ] ) ) {
                $langs_detected[ $l ] = 0;
              }

              $langs_detected[ $l ] ++;
            }
          }
        } catch ( \Exception $ex ) {
          // if we couldn't get any items, just bail out
        }

        // don't try to discover other feeds, if this feed is a long one,
        // otherwise we could find the same feed and stall the script for a long time
        if ( ! $long_rss ) {
          $discovered = $feed->get_all_discovered_feeds();
          if ( $discovered && count( $discovered ) ) {
            foreach ( $discovered as $new_feed ) {
              if ( ! $long_rss ) {
                $new_feed_parsed = get_feed( null, $new_feed->body );

                $l = $ld->detect( $new_feed_parsed->get_title() . '. ' . $new_feed_parsed->get_description() )->bestResults()->__toString();
                if ( ! isset( $langs_detected[ $l ] ) ) {
                  $langs_detected[ $l ] = 0;
                }

                $langs_detected[ $l ] ++;

                $data[ $new_feed->url ] = [
                  'title' => ($new_feed_parsed->get_title() ? $new_feed_parsed->get_title() : $new_feed->url),
                  'url'   => $new_feed->url,
                ];

                // run through at least 3 items to make the language detection actually work a little,
                // as blogs like "jwl.org" would otherwise come up as Polish (although it's an English blog)
                try {
                  $counter = 0;
                  if ( get_class( $new_feed_parsed ) == 'SimplePie' ) {
                    if ( count( $new_feed_parsed->get_items() ) > 100 ) {
                      $long_rss = true;
                    }

                    foreach ( $new_feed_parsed->get_items() as $new_item ) {
                      if ( ++ $counter > 3 ) {
                        break;
                      }

                      $l = $ld->detect( untagize( $new_item->get_title() ) )->bestResults()->__toString();
                      if ( ! isset( $langs_detected[ $l ] ) ) {
                        $langs_detected[ $l ] = 0;
                      }

                      $langs_detected[ $l ] ++;
                    }
                  }
                } catch ( \Exception $ex ) {
                  // if we couldn't get any items, just bail out
                }
              }
            }
          }
        }
      }
    }
  }

  // try matching on website's base URL
  preg_match('/https?:\/\/[^\/]+/m', $_POST['url'], $matches);

  if ($matches) {
    if (!$long_rss && !$is_json_feed) {
      $feed       = get_feed( $matches[0] );
      $discovered = $feed->get_all_discovered_feeds();
      if ( $discovered && count( $discovered ) ) {
        foreach ( $discovered as $new_feed ) {
          if ( ! $long_rss ) {
            $new_feed_parsed = get_feed( null, $new_feed->body );

            $l = $ld->detect( $new_feed_parsed->get_title() . '. ' . $new_feed_parsed->get_description() )->bestResults()->__toString();
            if ( ! isset( $langs_detected[ $l ] ) ) {
              $langs_detected[ $l ] = 0;
            }

            $langs_detected[ $l ] ++;

            $data[ $new_feed->url ] = [
              'title' => ($new_feed_parsed->get_title() ? $new_feed_parsed->get_title() : $new_feed->url),
              'url'   => $new_feed->url,
            ];

            // run through at least 3 items to make the language detection actually work a little,
            // as blogs like "jwl.org" would otherwise come up as Polish (although it's an English blog)
            try {
              $counter = 0;
              if ( get_class( $new_feed ) == 'SimplePie' ) {
                if ( count( $new_feed->get_items() ) > 100 ) {
                  $long_rss = true;
                }

                foreach ( $new_feed->get_items() as $new_item ) {
                  if ( ++ $counter > 3 ) {
                    break;
                  }

                  $l = $ld->detect( untagize( $new_item->get_title() ) )->bestResults()->__toString();
                  if ( ! isset( $langs_detected[ $l ] ) ) {
                    $langs_detected[ $l ] = 0;
                  }

                  $langs_detected[ $l ] ++;
                }
              }
            } catch ( \Exception $ex ) {
              // if we couldn't get any items, just bail out
            }
          }
        }
      }
    }

    // add feed's icon to the response
    $file_headers = @get_headers( $matches[0] . '/favicon.ico');
    if ( !$file_headers || strpos($file_headers[0], '404 Not Found') !== false ) {
      // no favicon for this website, use ours
      $icon = 'img/logo114.png';
    } else {
      $icon = $matches[0] . '/favicon.ico';
    }
  } else {
    // strange, the URL for the feed is not HTTP(S) based... use our icon
    $icon = 'img/logo114.png';
  }

  // determine feed language
  $last_detection_score = 0;
  foreach ($langs_detected as $lang_id => $detection_score) {
    if ($detection_score > $last_detection_score) {
      $last_detection_score = $detection_score;
      $lang_detected = $lang_id;
    }
  }

  // if the language is a complex identifier (such as en-GB, pt-BR etc.),
  // use just the first part of it - i.e. the actual language identifier without the country
  if (strpos($lang_detected, '-') !== false) {
    $lang_detected = explode('-', $lang_detected)[0];
  }

  $out = [
    'icon' => $icon,
    'lang' => $lang_detected,
    'items' => $data,
    'detect' => $langs_detected,
  ];

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( $out, \JSON_UNESCAPED_UNICODE );