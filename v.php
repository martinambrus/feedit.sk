<?php
require_once "api/bootstrap.php";

if (empty($_GET['id'])) {
  header('Content-Type: text/html; charset=utf-8');
  die($lang['The ID parameter is empty. Please make sure you have copied the full address from your verification E-Mail.'] . '<br><br><a href="vreg.php?lang=' . LANGUAGE .'">&lt;&lt; ' . $lang['Back'] . '</a>');
}

// check the ID
$existing = $mongo->{MONGO_DB_NAME}->accounts->findOne([ 'short_id' => (string) $_GET['id'] ]);

if (!$existing) {
  header('Content-Type: text/html; charset=utf-8');
  die($lang['We could not find this account ID. Please make sure you have copied the full address from your verification E-Mail.'] . '<br><br><a href="vreg.php?lang=' . LANGUAGE .'">&lt;&lt; ' . $lang['Back'] . '</a>');
}

// if already active, simply redirect
if ($existing->confirmed) {
  header('Location: app.php?lang=' . LANGUAGE);
  exit;
}

// create new set of collections for this user
try {
  // authors
  $new_collection = $mongo->{MONGO_DB_NAME}->createCollection('authors-' . $existing->short_id);

  if (!$new_collection || !$new_collection->ok) {
    throw new \Exception('Failed to create collection authors:' . print_r($new_collection, true));
  }

  $mongo->{MONGO_DB_NAME}->{'authors-' . $existing->short_id}->createIndexes([
    [
      'key' => [
        'author' => 1
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'author' => 1
      ],
      'unique' => true
    ]
  ]);

  // categories
  $new_collection = $mongo->{MONGO_DB_NAME}->createCollection('categories-' . $existing->short_id);

  if (!$new_collection || !$new_collection->ok) {
    throw new \Exception('Failed to create collection categories:' . print_r($new_collection, true));
  }

  $mongo->{MONGO_DB_NAME}->{'categories-' . $existing->short_id}->createIndexes([
    [
      'key' => [
        'category' => 1
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'category' => 1
      ],
      'unique' => true
    ]
  ]);

  // feeds - no special indexes
  $new_collection = $mongo->{MONGO_DB_NAME}->createCollection('feeds-' . $existing->short_id);

  if (!$new_collection || !$new_collection->ok) {
    throw new \Exception('Failed to create collection feeds:' . print_r($new_collection, true));
  }

  // labels
  $new_collection = $mongo->{MONGO_DB_NAME}->createCollection('labels-' . $existing->short_id);

  if (!$new_collection || !$new_collection->ok) {
    throw new \Exception('Failed to create collection labels:' . print_r($new_collection, true));
  }

  $mongo->{MONGO_DB_NAME}->{'labels-' . $existing->short_id}->createIndexes([
    [
      'key' => [
        'label' => 1
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'label' => 1
      ],
      'unique' => true
    ]
  ]);

  // n-grams
  $new_collection = $mongo->{MONGO_DB_NAME}->createCollection('ngrams-' . $existing->short_id);

  if (!$new_collection || !$new_collection->ok) {
    throw new \Exception('Failed to create collection ngrams:' . print_r($new_collection, true));
  }

  $mongo->{MONGO_DB_NAME}->{'ngrams-' . $existing->short_id}->createIndexes([
    [
      'key' => [
        'feed' => 1,
        'ngram' => 'text',
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'ngram' => 1
      ],
      'unique' => true
    ]
  ]);

  // training
  $new_collection = $mongo->{MONGO_DB_NAME}->createCollection('training-' . $existing->short_id);

  if (!$new_collection || !$new_collection->ok) {
    throw new \Exception('Failed to create collection training:' . print_r($new_collection, true));
  }

  $mongo->{MONGO_DB_NAME}->{'training-' . $existing->short_id}->createIndexes([
    [
      'key' => [
        'archived' => 1
      ],
      'partialFilterExpression' => [
        'archived' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'author' => 1
      ],
      'partialFilterExpression' => [
        'author' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'bookmarked' => 1
      ]
    ],
    [
      'key' => [
        'categories' => 1
      ],
      'partialFilterExpression' => [
        'categories' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'link' => 1
      ],
      'unique' => true,
    ],
    [
      'key' => [
        'labels' => 1
      ],
      'partialFilterExpression' => [
        'labels' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'label_predictions.$**' => 1
      ],
      'partialFilterExpression' => [
        'label_predictions' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'ngrams' => 1
      ],
      'partialFilterExpression' => [
        'ngrams' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'score' => -1
      ],
      'partialFilterExpression' => [
        'score' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'rated' => 1
      ],
      'partialFilterExpression' => [
        'rated' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'tier' => 1
      ],
      'partialFilterExpression' => [
        'tier' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'title' => 1
      ]
    ],
    [
      'key' => [
        'archived' => -1,
        'words' => 1,
      ],
      'partialFilterExpression' => [
        'words' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'date' => -1,
        '_id' => -1,
        'zero_scored_words' => -1
      ],
      'partialFilterExpression' => [
        'zero_scored_words' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'date' => 1,
        '_id' => 1,
        'zero_scored_words' => 1
      ],
      'partialFilterExpression' => [
        'zero_scored_words' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'score' => 1
      ],
    ],
    [
      'key' => [
        'feed' => 1,
        'score_conformed' => -1
      ],
      'partialFilterExpression' => [
        'score_conformed' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'score_conformed' => 1
      ],
      'partialFilterExpression' => [
        'score_conformed' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'zero_rated_scored_words_percentage' => 1
      ],
      'partialFilterExpression' => [
        'zero_rated_scored_words_percentage' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'fetched' => 1,
        'zero_scored_words' => 1
      ],
      'partialFilterExpression' => [
        'zero_scored_words' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'words' => 1,
        'rated' => 1
      ],
      'partialFilterExpression' => [
        'words' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'score' => 1,
        'fetched' => 1
      ],
      'partialFilterExpression' => [
        'score' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'read' => 1,
        'interest_average_percent_total' => 1,
        'zero_rated_scored_words_percentage' => 1,
      ],
    ],
    [
      'key' => [
        'feed' => 1,
        'read' => 1,
        'zero_rated_scored_words_percentage' => 1,
      ],
      'partialFilterExpression' => [
        'zero_rated_scored_words_percentage' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'feed' => 1,
        'trained' => 1,
        'rated' => 1,
      ],
      'partialFilterExpression' => [
        'rated' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'trained' => -1,
        'bookmarked' => 1,
        'rated' => 1,
        'fetched' => 1,
      ],
      'partialFilterExpression' => [
        'rated' => [ '$exists' => true ]
      ]
    ],
    [
      'key' => [
        'score_conformed' => -1,
        'score' => -1,
        'interest_average_percent_total' => -1,
        'zero_scored_words' => 1,
        'date' => -1,
        '_id' => -1,
      ],
      'partialFilterExpression' => [
        'score' => [ '$exists' => true ]
      ]
    ],
  ]);

  // words
  $new_collection = $mongo->{MONGO_DB_NAME}->createCollection('words-' . $existing->short_id);

  if (!$new_collection || !$new_collection->ok) {
    throw new \Exception('Failed to create collection words:' . print_r($new_collection, true));
  }

  $mongo->{MONGO_DB_NAME}->{'words-' . $existing->short_id}->createIndexes([
    [
      'key' => [
        'feed' => 1,
        'word' => 1
      ],
      'unique' => true
    ]
  ]);

} catch (\Exception $ex) {
  header('Content-Type: text/html; charset=utf-8');

  if (defined('DEBUG') && DEBUG === true) {
    var_dump($ex);
  }

  die($lang['There was an error trying to set up the application for your account. Please try to reload this page in a short while.'] . '<br><br><a href="vreg.php?lang=' . LANGUAGE .'">&lt;&lt; ' . $lang['Back'] . '</a>');
}

// activate account
$mongo->{MONGO_DB_NAME}->accounts->updateOne(
  [ 'short_id' => $existing->short_id ],
  [
    '$set' => [
      'confirmed' => 1,
      // automatically authenticate for first use in this browser
      'last_login' => time(),
      'last_activity' => time(),
      'active' => 1,
    ]
  ]
);

// create a new session
$auth_hash = ($existing->_id . time());
$expiry = time() + (60*60*24*30); // 30 days default expiration, as browsers now keep sessions,
                                  // i.e. omitting expiration in cookie will very probably keep us
                                  // logged-in for a long time

$mongo->{MONGO_DB_NAME}->sessions->updateOne(
  [ 'hash' => $existing->hash ],
  [
    '$set' => [
      'hash' => $existing->hash,
      'auth_hash' => $auth_hash,
      'expires' => $expiry,
      'first_hash' => 1, // this is, so we can create sessi
    ]
  ],
  [
    'upsert' => true
  ]
);

// set authentication cookie
setcookie('feedit', $auth_hash, $expiry);

echo $lang['Your account was successfully activated. Redirecting you to: '] . '<a href="app.php?lang=' . LANGUAGE . '">http://' . FEEDIT_WEB_URL . '/app.php?lang=' . LANGUAGE . '</a>';
?>

<script>
  setTimeout(function() {
    document.location.href = 'app.php?lang=<?php echo LANGUAGE; ?>';
  }, 2500);
</script>
