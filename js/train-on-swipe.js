(function($) {
  var
    $doc = $(document),
    swipe_training_check_task = -1;

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // enables/disabled swipe-to-train functionality
    // based on current device resolution
    function toggleSwipableTrainingItems() {
      if ($('ion-badge.ion-hide-xl-down:visible').length > 1) {
        $('ion-item-sliding').attr('disabled', 'disabled');
      } else {
        $('ion-item-sliding').removeAttr('disabled');
      }

      // get the UI the chance to update and verify whether we're on Desktop or not then
      setTimeout(function() {
        if ($('#menu_show:visible').length || $('#menu_hide:visible').length) {
          $doc.trigger('desktop-sized-change', [ true ]);
        } else {
          $doc.trigger('desktop-sized-change', [ false ]);
        }
      }, 100);
    };

    // checks whether we've swipe-trained an item and triggers a relevant training event
    function trainOnSwipe(event) {
      // if the mouse/finger is still down but a swipe event has already came in,
      // wait until we've released the mouse as to not make the item swipe back
      // under the user's mouse / finger
      if (!feedit.isMouseDown()) {
        // do we have an active slide uncovered?
        if ($('.item-sliding-active-slide').length) {
          var
            $e = $(event.target), // the actual ion-item-sliding element
            $item = $e.find('ion-item').first(), // the ion-item element
            // the actual option we uncovered using the drag
            $option = $e.find('ion-item-options[side="' + (event.originalEvent.detail.ratio < 0 ? 'start' : 'end') + '"]').find('ion-item-option:first'),
            should_train = false;

          // we've got an item that got trained, let's close it and animate the item container's color
          // to provide visual feedback
          if (event.originalEvent.detail.ratio < 0 && event.originalEvent.detail.ratio < -0.35) {
            // trained negatively
            // ... click the item's train up badge, even if it's hidden
            //     to update the UI and trigger the appropriate event
            $item.find('ion-badge.train_down').click();
            should_train = true;
          } else if (event.originalEvent.detail.ratio > 0.35) {
            // trained positively
            // ... click the item's train up badge, even if it's hidden
            //     to update the UI and trigger the appropriate event
            $item.find('ion-badge.train_up').click();
            should_train = true;

          }

          // close the opened item
          event.target.close();

          if (!should_train) {
            return;
          }

          // animate the background of this item for a visual feedback
          setTimeout(function() {
              $item
                .css({
                  'transition': '0.25s',
                  'background-color': $option.css('background-color')
                });

              setTimeout(function () {
                $item
                  .css({
                    'transition': '0.22s',
                    'background-color': ''
                  })

                setTimeout(function() {
                  $doc.trigger('restore-highlight', [ $item ]);
                }, 220);
              }, 250);
            }, 250);
        }
      } else {
        // mouse button is still down, try again in a second
        clearTimeout(swipe_training_check_task);
        swipe_training_check_task = setTimeout(function() { trainOnSwipe(event); }, 1000);
      }
    };

    // reacts to drag event of the item and sets a delayed task, calling the trainOnSwipe() function
    // to determine whether we have at least 1 option uncovered and should train this item
    $doc.on('ionDrag', function (e) {
      if (swipe_training_check_task > -1) {
        clearTimeout(swipe_training_check_task);
        swipe_training_check_task = -1;
      }

      // if this item is highlighted, we need to remove the highlight class,
      // as it would prevent the closing animation on this item to animate
      $doc.trigger('hide-highlight', [ e.target ] );

      // wait a little while and then try to determine whether we have still at least 1 option active
      swipe_training_check_task = setTimeout(function () {
        trainOnSwipe(e);
      }, 600);
    });

    // react to this event to make swipe-to-train items enabled or disabled
    $doc.on('toggle-swipable-training-items', toggleSwipableTrainingItems);

  };
})(jQuery);