<?php
/* RUNS EVERY 6 HOURS */

set_time_limit( 600 );
session_write_close();

$time_start = microtime(true);
$exceptions_counter = 0;

require_once "../api/bootstrap.php";

// don't start a new job if the last one is still running and hasn't timed-out yet
if ($mongo->{MONGO_DB_NAME}->jobs->findOne([
    'type' => 'auto-archive-processed',
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
  'type' => 'auto-archive-processed',
  'lambdas' => 1,
  'start' => time(),
  'expires' => time() + (60 * 5), // 5 minutes job expiration
]);

try {
  // mark articles older than 1 month archived in the processed collection, unless they've been bookmarked at least 1 time
  $mongo->{MONGO_DB_NAME}->processed->updateMany( [ 'bookmarked_times' => 0, 'fetched' => [ '$lt' => ( time() - (60 * 60 * 24 * 31) ), ] ],
    [
      '$set' => [
        'archived' => 1,
      ]
    ]);
} catch ( \Exception $ex ) {
  // TODO: something went wrong during program execution, log this properly
  // throw new \Exception( $ex );
  file_put_contents( 'logs/err-' . date('j.m.Y H:i:s') . '-auto-archive-processed', $ex->getTraceAsString() . "\n", FILE_APPEND );
  $exceptions_counter++;
}

$time_end = microtime(true);
echo '<br><br>[' . date('j.m.Y, H:i:s') . ']' . (round($time_end - $time_start,3) * 1000) . 'ms marking old processed items archived<br><br>';

// insert data into log
$mongo->{MONGO_DB_NAME}->logs->insertOne([
  'type' => 'auto-archive-processed',
  'start' => $time_start,
  'end' => $time_end,
  'duration' => (round($time_end - $time_start,3) * 1000),
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
