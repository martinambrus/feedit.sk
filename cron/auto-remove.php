<?php
/* RUNS EVERY 6 HOURS */

set_time_limit( 600 );
session_write_close();

$time_start = microtime(true);

require_once "../api/bootstrap.php";

// don't start a new job if the last one is still running and hasn't timed-out yet
if ($mongo->{MONGO_DB_NAME}->jobs->findOne([
    'type' => 'auto-remove',
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
$job = $mongo->{MONGO_DB_NAME}->jobs->insertOne([
  'type' => 'auto-remove',
  'lambdas' => 1,
  'start' => time(),
  'expires' => time() + (60 * 5), // 5 minutes job expiration
]);

$user_counter = 0;
$exceptions_counter = 0;
$feed_fetch_times = [];

// get active users
// TODO: this would just fire up events with up to 100 account IDs to train links for to SNS for worker lambdas to process
foreach ($mongo->{MONGO_DB_NAME}->accounts->find([ 'active' => 1 ], [
  'limit' => 100,
  'sort' => [ 'feed' => 1 ],
  'projection' => [
    'feeds' => 1,
    'short_id' => 1,
  ]
]) as $user) {
  $user_counter++;

  // user has not yet subscribed to any feeds
  if (empty($user->feeds)) {
    continue;
  }

  // iterate over feeds this user is subscribed to and remove all links with negative score below 2900 fetched either more than 3 days ago
  // or in the event that the feed fetch interval is higher, then use that time difference
  foreach ($user->feeds as $feed_object) {
    try {
      if (!isset($feed_fetch_times[ (string) $feed_object ])) {
        $feed_fetch_times[ (string) $feed_object ] = $mongo->{MONGO_DB_NAME}->feeds->findOne([ '_id' => $feed_object ], [ 'projection' => [ 'fetch_interval_minutes' => 1 ] ])->fetch_interval_minutes;
      }

      if ( ($feed_fetch_times[ (string) $feed_object ] * 60) > (60 * 60 * 24 * 3) ) {
        $cleanup_time = ($feed_fetch_times[ (string) $feed_object ] * 60); // current fetch time to be used as cleanup interval, in seconds
      } else {
        $cleanup_time = (60 * 60 * 24 * 3); // 3 days cleanup default, in seconds
      }

      $mongo->{MONGO_DB_NAME}->{'training-' . $user->short_id}->deleteMany([
        'feed' => $feed_object,
        'score' => [ '$lt' => -2900 ],
        'fetched' => [
          '$lt' => ( time() - $cleanup_time ),
        ]
      ]);
    } catch ( \Exception $ex ) {
      // TODO: something went wrong during program execution, log this properly
      // throw new \Exception( $ex );
      file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-auto-remove', $ex->getTraceAsString() . "\n", FILE_APPEND );
      $exceptions_counter++;
    }
  }
}

$time_end = microtime(true);
echo '<br><br>[' . date('j.m.Y, H:i:s') . '] ' . (round($time_end - $time_start,3) * 1000) . 'ms for to remove heavily downscored items for ' . $user_counter . ' user(s)<br><br>';

// insert data into log
$mongo->{MONGO_DB_NAME}->logs->insertOne([
  'type' => 'auto-remove',
  'start' => $time_start,
  'end' => $time_end,
  'duration' => (round($time_end - $time_start,3) * 1000),
  'users_count' => $user_counter,
  'exceptions_count' => $exceptions_counter,
]);

// mark job as finished
$mongo->{MONGO_DB_NAME}->jobs->updateOne([ '_id' => $job->getInsertedId() ], [ '$set' => [ 'end' => time(), 'lambdas' => 0 ] ]);
?>
<script>
  // reload every 6 hours
  var reloadTime = (60000 * 60 * 6);
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
