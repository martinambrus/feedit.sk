<?php
  function get_existing_user( $email ) {
    global $mongo;

    // get all users and check their e-mails one by one
    foreach ( $mongo->{MONGO_DB_NAME}->accounts->find([]) as $record ) {
      $salts = ( array_slice(permute( $record->short_id ), 0, 366) );
      $day_created = date('z', $record->created);
      $db_email = openssl_decrypt( $record->email, 'AES-256-CBC', $salts[ $day_created ], 0, substr($record->ehash, 0, 16)  );

      if ($db_email == $email) {
        return $record;
      }
    }

    return false;
  }

  function decode_user_email( $user ) {
    $salts = ( array_slice(permute( $user->short_id ), 0, 366) );
    $day_created = date('z', $user->created);
    return openssl_decrypt( $user->email, 'AES-256-CBC', $salts[ $day_created ], 0, substr($user->ehash, 0, 16)  );
  }