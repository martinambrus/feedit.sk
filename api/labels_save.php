<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  // validate feed
  if (empty($_POST['feed'])) {
    send_error($lang['Missing parameter'], $lang['Feed ID empty'], 400, 'validation', ['api' => 'labels_save', 'field' => 'feed']);
  }

  try {
    $feed_object = new MongoDB\BSON\ObjectId( (string) $_POST['feed'] );
  } catch (\Exception $ex) {
    send_error($lang['System Error'], $lang['Feed ID not ObjectID'], 400, 'validation', [ 'api' => 'labels_save', 'feed_id' => $_POST['feed'] ]);
  }

  // validate labels
  if (empty($_POST['additions']) && empty($_POST['removals']) && empty($_POST['changed'])) {
    // nothing to do
    die();
  }

  // add new labels
  if (!empty($_POST['additions']) && is_array($_POST['additions'])) {
    // update the array to be insert-compatible
    foreach ($_POST['additions'] as $key => $value) {
      $_POST['additions'][$key] = [
        'label' => (string) $value,
        'feed' => $feed_object,
      ];
    }

    // insert the data
    $result = $mongo->bayesian->{'labels-' . $user->short_id}->insertMany( $_POST['additions'] );

    if (!$result->getInsertedCount()) {
      send_error($lang['Labels Update Error'], $lang['Labels database update failed.'], 500, 'database', [ 'additions' => $_POST['additions'] ]);
    }
  }

  // update existing labels
  if (!empty($_POST['changed']) && is_array($_POST['changed'])) {
    foreach ($_POST['changed'] as $key => $value) {
      try {
        $label_id = new MongoDB\BSON\ObjectId( (string) $value['id'] );
      } catch (\Exception $ex) {
        send_error($lang['System Error'], $lang['Label ID not ObjectID'], 400, 'validation', [ 'api' => 'labels_save', 'changed_label_id' => $value['id'] ]);
      }

      // update name in all label predictions containing this label
      $mongo->bayesian->{'training-' . $user->short_id}->updateMany(
      [
        'label_predictions' => [
          '$elemMatch' => [
            'id' => $label_id,
          ]
        ]
      ],
      [
        '$set' =>
          [
            'label_predictions.$.label' => (string) $value['val']
          ]
      ]);

      // update label data
      $mongo->bayesian->{'labels-' . $user->short_id}->updateOne([ '_id' => $label_id ], [ '$set' => [ 'label' => (string) $value['val'] ] ] );
    }
  }

  // remove labels
  if (!empty($_POST['removals']) && is_array($_POST['removals'])) {
    // gather label IDs to remove
    $ids = [];
    foreach ($_POST['removals'] as $value) {
      try {
        // remove label predictions
        $label_id_object = new MongoDB\BSON\ObjectId( (string) $value );
        $ids[] = $label_id_object;

        // set label prediction for this label to 'to_be_pulled'
        $mongo->bayesian->{'training-' . $user->short_id}->updateMany(
          [
            'label_predictions' => [
              '$elemMatch' => [
                'id' => $label_id_object,
              ]
            ]
          ],
          [
            '$set' => [ 'label_predictions.$' => 'to_be_pulled' ],
          ]);
      } catch (\Exception $ex) {
        send_error($lang['System Error'], $lang['Label ID not ObjectID'], 400, 'validation', [ 'api' => 'labels_save', 'removal_label_id' => $value ]);
      }
    }

    // remove all label predictions with a "to_be_pulled" value after unsetting them above
    $mongo->bayesian->{'training-' . $user->short_id}->updateMany( [
      'label_predictions' => [
        '$elemMatch' => [
          '$in' => [ 'to_be_pulled' ],
          '$exists' => 1,
        ]
      ]
    ], [ '$pull' => [ 'label_predictions' => 'to_be_pulled' ] ] );

    // remove labels from trained items
    $mongo->bayesian->{'training-' . $user->short_id}->updateMany( [], [ '$pull' => ['labels' => [ '$in' => $ids ] ] ] );

    // remove label data
    $result = $mongo->bayesian->{'labels-' . $user->short_id}->deleteMany( [ '_id' => [ '$in' => $ids ] ] );

    if (!$result->getDeletedCount()) {
      send_error($lang['Labels Update Error'], $lang['Labels database update failed.'], 500, 'database', [ 'removals' => $_POST['removals'] ]);
    }
  }