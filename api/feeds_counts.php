<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";
  require_once "../functions/functions-content.php";
  require_once "../functions/functions-feeds.php";

  // remove labels from the POST data, or we wouldn't get correct data
  unset($_POST['labels']);

  if (isset($_POST['what']) && $_POST['what'] == 'bookmarks') {
    $data = get_all_feeds_data( true );
  } else if (isset($_POST['what']) && $_POST['what'] == 'all') {
    $data = get_all_feeds_data();
  } else {
    $data = [];
  }

  $counts = [];

  if (!isset($_POST['what']) || $_POST['what'] != 'bookmarks') {
    foreach ( $data as $feed_id => $feed_info ) {
      if ($feed_id != 'bookmarks_count' && $feed_id != 'all_count') {
        $counts[] = [
          'id'    => $feed_id,
          'count' => $feed_info['count']
        ];
      }
    }
  }

  $counts[] = [
    'id' => 'bookmarks',
    'count' => (isset($data['bookmarks_count']) ? $data['bookmarks_count'] : 0)
  ];

  if (isset( $data['all_count'] )) {
    $counts[] = [
      'id'    => 'all',
      'count' => $data['all_count']
    ];
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( $counts, \JSON_UNESCAPED_UNICODE );