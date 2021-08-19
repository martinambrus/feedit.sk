(function($) {
  var
    $doc = $(document),
    double_click_last_start = -1,
    double_click_last_id = null,
    content_was_scrolled = false;

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // double-click handling on main content items and training modal icons
    // ... we only use mousedown here, as browsers will still fire up mouse events after touch events
    $doc.on('mousedown', 'ion-item, #training_slider ion-badge ion-icon', function () {
      var
        $e = $(this),
        item_id = ((this.tagName == 'ION-ITEM') ? $(this).data('id') : $e.parent('ion-badge').data('id'));

      // do we have a click that happened before the current one?
      if (double_click_last_start > -1) {
        // if our ID is different to the one before,
        // let's reset it to ours, as well as the start time
        if (double_click_last_id != item_id) {
          double_click_last_id = item_id;
          double_click_last_start = Date.now();
        } else {
          // check if the last click occurred within the double-click time period
          if (Date.now() - double_click_last_start <= feedit.getDoubleClickDelayTime()) {
            // double-click confirmed, fire the event
            $(this).trigger('doubleclick');
          } else {
            // reset the last time variable,
            // so we can potentially double-click on this one again
            double_click_last_start = Date.now();
          }
        }
      } else {
        // no previous touch start event, set the initial one here
        double_click_last_start = Date.now();
        double_click_last_id = item_id;
      }
    });

    // long-press on:
    // - up/down icons in training modal
    // - left menu feed item
    // - tab button in footer for batch actions
    // - "select all" button
    // - label badge in main content
    $doc.on('mousedown touchstart', '#training_slider ion-badge ion-icon, .feed_item, #multi_mark_read, #multi_vote_down, #multi_vote_up, #multi_labels, #select_all, #main .label_badge', function (event) {
      var $e = $(this);

      // set this to false, so we'll know whether we've scrolled the view if it's true after the long press
      content_was_scrolled = false;

      // add a mousedown cancellation class if we're on a touch-enabled interface,
      // as that would double-click this item, resulting in the long press firing twice
      if (event.type == 'touchstart') {
        $e.addClass('touchstarted');
      } else {
        // check for the mouse cancellation class
        if ($e.hasClass('touchstarted')) {
          $e.removeClass('touchstarted');
          return;
        }
      }

      // add classes and data to the element for our mouse-held checks
      $e
        .addClass('mouseheld_timer')
        .data({
          'mouseheld_timer': setTimeout(function () {
            // if we scrolled the content, bail out as to not select items while scrolling
            if (!content_was_scrolled) {
              $e.trigger('longpress');
            }
          }, feedit.getLongPressDelayTime())
        });
    })
    .on('mouseup touchend', '#training_slider ion-badge ion-icon, .feed_item, #multi_mark_read, #multi_vote_down, #multi_vote_up, #multi_labels, #select_all, #main .label_badge', function (event) {
      var $e = $(this);

      // add a mouseup cancellation class if we're on a touch-enabled interface,
      // as that would double-click this item, resulting in the long press firing twice
      if (event.type == 'touchend') {
        $e.addClass('touchended');
      } else {
        // check for the mouse cancellation class
        if ($e.hasClass('touchended')) {
          $e.removeClass('touchended');
          return;
        }
      }

      // check that our element has a mouseheld timer set
      if (!$e.hasClass('mouseheld_timer')) {
        return;
      }

      feedit.checkAndRemoveLongPressTimer($e, event);
    });

    $('#main ion-content')[0].getScrollElement().then(function(element) {
      $(element).on('scroll', function() {
        content_was_scrolled = true;
      });
    });

    $('#main_menu ion-content')[0].getScrollElement().then(function(element) {
      $(element).on('scroll', function() {
        content_was_scrolled = true;
      });
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      checkAndRemoveLongPressTimer($e, event) {
        if ($e.data('mouseheld_timer')) {
          clearTimeout($e.data('mouseheld_timer'));

          $e
            .removeData(['mouseheld_timer'])
            .removeClass('mouseheld_timer');
        } else {
          console.log('Unexpected state: no timer exists for a timer-classed element', $e);
          content_was_scrolled = false;
        }
      }
    });

  };
})(jQuery);