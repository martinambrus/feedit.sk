<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  $data = [];

  foreach ($locales as $key => $locale) {
    $data[] = [
      'id' => $key,
      'name' => $locale['name'],
    ];
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode( $data, \JSON_UNESCAPED_UNICODE );