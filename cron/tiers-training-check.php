<?php
session_write_close();
$time_start = microtime(true);

require_once "../api/bootstrap.php";

// don't start a new job if the last one is still running and hasn't timed-out yet
if ($mongo->bayesian->jobs->findOne([
    'type' => 'well-trained-check',
    'lambdas' => [
      '$gt' => 0,
    ],
    'expires' => [
      '$gte' => time()
    ],
  ]) !== null) {
  exit;
}

// add current job into the collection of active jobs
$job = $mongo->bayesian->jobs->insertOne([
  'type' => 'well-trained-check',
  'lambdas' => 1,
  'start' => time(),
  'expires' => time() + (60 * 5), // 5 minutes job expiration
]);

$user_counter = 0;
$feeds_count = 0;
$exceptions_counter = 0;

// get active users
// TODO: this would just fire up events with up to 100 account IDs to train links for to SNS for worker lambdas to process
foreach ($mongo->bayesian->accounts->find([ 'active' => 1 ], [
  'limit' => 100,
  'sort' => [ 'feed' => 1 ],
  'projection' => [
    'short_id' => 1,
    'feeds' => 1,
  ]
]) as $user) {
  $user_counter++;

  // user has not yet subscribed to any feeds
  if (empty($user->feeds)) {
    continue;
  }

  // iterate over feeds this user is subscribed to and which don't have had a tiers-training-check done yet
  foreach ($mongo->bayesian->{'feeds-' . $user->short_id}->find([ 'tiers_training_check' => [ '$exists' => false ] ], [ 'projection' => ['_id' => 1] ]) as $feed_data) {
    $feeds_count++;
    try {
      // get number of trained links and all links
      $all_docs = $mongo->bayesian->{'training-' . $user->short_id}->countDocuments( [ 'feed' => $feed_data->_id ] );

      // check that we have at least 200 trained links
      if ($all_docs >= 200) {
        $trained_docs    = $mongo->bayesian->{'training-' . $user->short_id}->countDocuments( [
          'feed'    => $feed_data->_id,
          'trained' => 1
        ] );

        // check that we have at least 32% of all links trained
        if (($trained_docs / $all_docs) * 100 >= 32) {
          $trained_up_docs = $mongo->bayesian->{'training-' . $user->short_id}->countDocuments( [ 'feed' => $feed_data->_id, 'trained' => 1, 'rated' => 1 ] );

          // check that we have 4+% links trained positively
          if (($trained_up_docs / $trained_docs) * 100 >= 4) {

            // get average score for untrained links with >0 scored for this feed
            $feed_average_score = $mongo->bayesian->{'training-' . $user->short_id}->aggregate([
              [
                '$match' => [
                  'feed' => $feed_data->_id,
                  'trained' => 0,
                  'score_conformed' => [ '$gte' => 0 ],
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

            if ($feed_average_score) {
              foreach ( $feed_average_score as $record ) {
                $feed_average_score = $record->average;
                break;
              }
            } else {
              $feed_average_score = 0;
            }

            // this feed has higher conformed average trained score than 163, mark it as well-trained
            if ($feed_average_score > 163) {
              $mongo->bayesian->{'feeds-' . $user->short_id}->updateOne( [ '_id' => $feed_data->_id ], [
                '$set' => [
                  'tiers_training_check' => 1,
                ]
              ] );
            }
          }
        }
      }

    } catch ( \Exception $ex ) {
      // TODO: something went wrong during program execution, log this properly
      //throw new \Exception( $ex );
      file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-tiers-training-check', $ex->getTraceAsString() . "\n", FILE_APPEND );
      $exceptions_counter++;
    }
  }
}

$time_end = microtime(true);
echo '<br><br>' . (round($time_end - $time_start,3) * 1000) . 'ms for ' . $feeds_count . ' feeds of ' . $user_counter . ' users checked for being well-trained<br><br>';

// insert data into log
$mongo->bayesian->logs->insertOne([
  'type' => 'well-trained-check',
  'start' => $time_start,
  'end' => $time_end,
  'duration' => (round($time_end - $time_start,3) * 1000),
  'feeds_count' => $feeds_count,
  'users_count' => $user_counter,
  'exceptions_count' => $exceptions_counter,
]);

// mark job as finished
$mongo->bayesian->jobs->updateOne([ '_id' => $job->getInsertedId() ], [ '$set' => [ 'end' => time(), 'lambdas' => 0 ] ]);
?>
<script>
  // reload every 2 hours
  var reloadTime = (60000 * 60 * 2);
  setTimeout(function() {
    document.location.reload();
  }, reloadTime);

  document.write('Reload in: <span id="reloadTime">' + (reloadTime / 1000) + '</span>s');

  setInterval(function() {
    var currentTime = parseInt(document.getElementById('reloadTime').innerHTML);

    if (currentTime > 0) {
      document.getElementById('reloadTime').innerHTML = currentTime - 1;
    }
  }, 1000);
</script>
