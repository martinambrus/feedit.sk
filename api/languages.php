<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  // load all languages data from the stopwords collection
  $langs = [];
  foreach ($mongo->{MONGO_DB_NAME}->stopwords->find([], [ 'sort' => [ 'name_en' => 1 ], 'projection' => [ 'name_en' => 1, 'name' => 1, 'lang' => 1 ] ]) as $db_lang) {
    // update identifiers to become fit for the API
    $langs[] = [
      'id' => (string) $db_lang->_id,
      'code' => $db_lang->lang,
      'name' => $db_lang->name,
      'name_en' => $db_lang->name_en,
    ];
  }

  // add "Other" language
  $langs[] = [
    'id' => 'other',
    'code' => 'other',
    'name_en' => $lang['Other'],
  ];

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( array_values($langs), \JSON_UNESCAPED_UNICODE );