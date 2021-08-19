(function($) {
  var
    $doc = $(document),
    $multiselect_actions,
    $select_all_checkbox,
    // this variable will hold information about all items that were multi-selected,
    // as these items may be removed from the view on pagination
    // ... this data will then be used to perform actions with these selected items
    selected_items_cache = {};

  $doc.on('app-init', function() {
    $multiselect_actions = $('#multiselect_actions');
    $select_all_checkbox = $('#select_all');
    initModule();
  });

  // adds the given item into the internal cache of all selected items
  function addSelectedItemToCache($item) {
    // add this item's info into cache
    selected_items_cache[ $item.data('id') ] = {
      'id' : $item.data('id'),
      'title' : $item.find('ion-label h2 a').text(),
      'labels' : $item.find('ion-badge.label_badge').map(function() {
                   var $e = $(this);
                   return {
                     'id' : $e.data('id'),
                     'txt' : $e.find('small').text()
                   };
                 }).toArray()
    };

    // add a trained flag, according to this item's current trained status
    if (
      (!feedit.isSimpleMode() && $item.find('.train_up').hasClass('ion-hide'))
      ||
      (feedit.isSimpleMode() && !$item.find('.train_up').hasClass('shown-outside-simple-mode'))
    ) {
      selected_items_cache[ $item.data('id') ].trained = 0;
    } else if (
      (!feedit.isSimpleMode() && $item.find('.train_down').hasClass('ion-hide'))
      ||
      (feedit.isSimpleMode() && !$item.find('.train_down').hasClass('shown-outside-simple-mode'))
    ) {
      selected_items_cache[ $item.data('id') ].trained = 1;
    } else {
      selected_items_cache[ $item.data('id') ].trained = -1;
    }
  }

  // removes the given item from the internal cache of all selected items
  function removeSelectedItemFromCache($item) {
    delete selected_items_cache[ $item.data('id') ];
  }

  function inverse_selection() {
    // go through all visible links on page and call highlightItem() on each of them,
    // letting the code to de/select them as needed
    $('#main ion-item-sliding').not('.ion-hide').each(function() {
      feedit.highlightItem( $(this) );
    });
  };

  function initModule() {

    // listen to event to invert current selection
    $doc.on('invert-selection', function() {
      $(this).addClass('changed_from_select_event switch_to_previous_state');
      inverse_selection();
    });

    // long press on the Select All button inverses selection
    $doc.on('longpress', '#select_all', function() {
      $(this).addClass('changed_from_select_event switch_to_previous_state');
      inverse_selection();
    });

    // shift+click on the Select All button inverses selection
    $doc.on('mousedown touchstart', '#select_all', function( event ) {
      if (event.shiftKey) {
        $(this).addClass('changed_from_select_event switch_to_previous_state');
        inverse_selection();
      }
    });

    // select all checkbox handling
    $doc.on('ionChange', '#select_all', function (event) {
      var $e = $(this);

      // don't act if the checkbox was programatically checked from another event
      if ($e.hasClass('changed_from_select_event')) {
        $e.removeClass('changed_from_select_event');

        // if we should also switch to previous state, do so here
        if ($e.hasClass('switch_to_previous_state')) {
          $e
            .removeClass('switch_to_previous_state')
            .addClass('changed_from_select_event')
            .prop('checked', !event.originalEvent.detail.checked);
        }
        return;
      }

      if (event.originalEvent.detail.checked) {
        $('ion-item-sliding').not('.child_highlighted, .ion-hide').each(function () {
          addSelectedItemToCache(
            $(this)
            .addClass('child_highlighted')
            .find('ion-item')
            .addClass('selected_item')
          );

          $doc.trigger('item-select', [ {'origin': 'select_all'} ]);
        });
      } else {
        $('ion-item-sliding.child_highlighted').not('.ion-hide').each(function () {
          $(this)
            .removeClass('child_highlighted')
            .find('ion-item')
            .removeClass('selected_item');

          // as we're de-selecting everything, clear the selection cache
          feedit.resetSelectedItemsCache();
          $doc.trigger('item-select', [ {'origin': 'select_all'} ]);
        });
      }
    });

    // select all checkbox state and tabs hide handling
    $doc.on('item-select', function (event, data) {
      // check if anything is still selected
      if (feedit.countSelectedItemsCachedObjects()) {
        if (typeof(data) == 'undefined' || typeof (data.origin) == 'undefined' || data.origin != 'content_refresh') {
          // show tabs - if we're not just refreshing the checkbox
          $multiselect_actions.removeClass('ion-hide');
        }

        if (typeof (data) == 'undefined' || typeof (data.origin) == 'undefined' || data.origin != 'select_all') {
          // check whether all items are selected
          // and appropriately update the Select All checkbox
          if ($('.selected_item').length == $('ion-item-sliding').not('.ion-hide').length && !$select_all_checkbox.prop('checked')) {
            // don't react to a click event on the checkbox afterwards, if we're just updating its state from a content update
            if (typeof (data) != 'undefined' && typeof (data.origin) != 'undefined' && data.origin == 'content_refresh') {
              $select_all_checkbox.addClass('changed_from_select_event');
            }

            $select_all_checkbox.prop('checked', true);
          } else if ($('.selected_item').length != $('ion-item-sliding').not('.ion-hide').length && $select_all_checkbox.prop('checked')) {
            $select_all_checkbox
              .addClass('changed_from_select_event')
              .prop('checked', false);
          }
        }
      } else {
        if (typeof (data) == 'undefined' || typeof (data.origin) == 'undefined' || data.origin != 'content_refresh') {
          // hide tabs - if we're not just refreshing the checkbox
          $multiselect_actions.addClass('ion-hide');
        } else if (typeof (data) != 'undefined' && typeof (data.origin) != 'undefined' && data.origin == 'content_refresh' && $select_all_checkbox.prop('checked')) {
          // update checkbox state, so it doesn't remain checked when we have no selection left
          $select_all_checkbox
            .addClass('changed_from_select_event')
            .prop('checked', false);
        }
      }
    });

    // shift+click item highlighting
    $doc.on('mousedown', 'ion-item-sliding', function (event) {
      // apply highlighting if we have used a modifier key while clicking
      if (event.shiftKey) {
        // highlight a single item
        var
          $e = $(this),
          $item = $e.find('ion-item');

        $e.toggleClass('child_highlighted');
        $item
          .toggleClass('selected_item')
          .addClass('highlight_action_occured');

        if ($item.hasClass('selected_item')) {
          addSelectedItemToCache( $item );
        } else {
          removeSelectedItemFromCache( $item );
        }

        // remove the highlight "color action occurred" helper class after a moment,
        // so if we didn't click on the item through the H2 heading, we can still
        // open/close it by clicking on it without having to do that twice
        setTimeout(function () {
          $item.removeClass('highlight_action_occured');
        }, 500);

        $doc.trigger('item-select');
      }
    });

    // item-highlighting on long-press handling
    // ... this is only available on mobile devices, so it reacts to touch events only
    $doc.on('touchstart click', 'ion-item-sliding', function (event) {
      // when coming from ION-ITEM or H2 elements,
      // select the actual parent ION-ITEM-SLIDING as the element to work with
      if (this.tagName != 'ION-ITEM-SLIDING') {
        var
          $e = $(this).closest('ion-item-sliding'),
          self = $e[0];
      } else {
        // coming from ION-ITEM-SLIDING directly
        var
          $e = $(this),
          self = this;
      }

      // if we already have at least a single item highlighted,
      // all other highlightings are handled by click event
      if ( feedit.countSelectedItemsCachedObjects() ) {
        // don't cancel the long-hold highlight that was used to highlight the first item
        if (event.type == 'click' && !$e.find('ion-item').first().hasClass('highlight_action_occured')) {
          feedit.highlightItem($e, event);
        }

        return true;
      }

      // nothing below is needed for a click event
      // ... in fact, if we don't bail out here, click event
      //     will set up another highlight timer
      if (event.type == 'click') {
        return;
      }

      // timer already exists, therefore we've bubbled up from item or h2 to item-sliding
      // ... or, it can be that we're dragging this item while having a pointer down, so bail out then as well
      // ... or, it can be that we've just highlighted this item via SHIFT+CLICK, in which case we'll have the highlight_action_occured present
      if ($e.data('mouseheld_timer') || $e.hasClass('item-sliding-active-slide') || $e.find('ion-item').first().hasClass('highlight_action_occured')) {
        return;
      }

      // add classes and data to ion-item-sliding for our mouse-held checks
      $e
        .addClass('mouseheld_timer')
        .data({
          'mouseheld_timer': setTimeout(function () {
            feedit.highlightItem($e, event);
          }, 500), // 500ms set as a default long press timeout
          'mPosX': event.pageX, // store X and Y finger positions, as we don't want the highlight
          'mPosY': event.pageY, // to cancel if our finger moves just a few pixels away
        });
    })
    .on('touchmove touchend touchcancel', 'ion-item-sliding', function (event) {
      // if we already have at least a single item highlighted,
      // other highlightings are handled by click event,
      // so we don't have to do anything here (no timers are set)
      if ( feedit.countSelectedItemsCachedObjects() ) {
        return;
      }

      // when coming from ION-ITEM or H2 elements,
      // select the actual parent ION-ITEM-SLIDING as the element to work with
      if (this.tagName != 'ION-ITEM-SLIDING') {
        var
          $e = $(this).closest('ion-item-sliding'),
          self = $e[0];
      } else {
        var
          $e = false,
          self = this;
      }

      // check that our element has a mouseheld timer set
      if (typeof (self) == 'undefined' || self.className.indexOf('mouseheld_timer') == -1) {
        return;
      }

      // only now convert the element to a jQuery object,
      // as it'd just be wasted if we did this sooner
      if ($e === false) {
        var $e = $(this);
      }

      feedit.checkAndRemoveLongPressTimer($e, event);
    });

    // re-adds the highlight to a previously highlighted but now dimmed item
    // ... this is needed for instance when we're swipe-training an item on a mobile device,
    //     which removes the highlight and creates a visual green/red cue on the background
    $doc.on('restore-highlight', function(event, $item) {
      if ($item.hasClass('was_selected')) {
        addSelectedItemToCache( $item.toggleClass('selected_item was_selected') );
        $item.closest('ion-item-sliding').addClass('child_highlighted');
      }
    });

    // removes a highlighting from ion-item-sliding's child
    // ... opposite to the above, this trigger is used when we start to swipe-train an item,
    //     so its highlight background is dimmed, so other training bg animations can take place
    $doc.on('hide-highlight', function(event, sliding_element) {
      if (sliding_element.className.indexOf('child_highlighted') > -1) {
        removeSelectedItemFromCache(
          $(sliding_element)
          .removeClass('child_highlighted')
          .find('.selected_item')
          .toggleClass('selected_item was_selected')
        );
      }
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      getSelectedItemsCache() {
        return selected_items_cache;
      },

      resetSelectedItemsCache() {
        selected_items_cache = {};
      },

      countSelectedItemsCachedObjects() {
        return Object.keys( selected_items_cache ).length;
      },

      getFirstSelectedItemCachedObject() {
        for (var ret in selected_items_cache) {
          return selected_items_cache[ ret ];
        }
      },

      untickSelectAllCheckbox() {
        $select_all_checkbox
          .addClass('changed_from_select_event')
          .prop('checked', false);

        // remove the changed_from_select_event class, as it sometimes sticks around for no reason :-))
        setTimeout(function() {
          $select_all_checkbox.removeClass('changed_from_select_event');
        }, 500);
      },

      // adds an ion-hide class to all of the selected items' parents (ion-item-sliding)
      hide_all_selected_items( dont_refresh_content, dont_show_loading_when_content_empty ) {
        for (var item_id of Object.keys( feedit.getSelectedItemsCache() ) ) {
          $('#main ion-item-sliding ion-item[data-id="' + item_id +'"]')
            .removeClass('selected_item')
            .closest('ion-item-sliding')
            .addClass('ion-hide')
            .removeClass('child_highlighted');
        }

        feedit.resetSelectedItemsCache();

        // hide bottom tabs bar if it can be hidden
        $multiselect_actions.addClass('ion-hide');

        // untick the "select all" checkbox, if checked
        feedit.untickSelectAllCheckbox();


        // if there are no items left on page, refresh content
        if ( !$('#main ion-item-sliding').not('.ion-hide').length ) {
          // if there are no visible items on page, also hide next/prev pagination button,
          // as we'll be auto-reloading content
          $('#next_page, #prev_page').addClass('ion-hide');

          if (typeof(dont_show_loading_when_content_empty) == undefined || dont_show_loading_when_content_empty !== true) {
            $doc.trigger('show-loading');
          }

          if (typeof(dont_refresh_content) == undefined || dont_refresh_content !== true) {
            $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
          }
        }
      },

      // adds an ion-hide class to the given items' parents (ion-item-sliding)
      hide_single_item( $item, dont_refresh_content, dont_show_loading_when_content_empty ) {
        $item
          .removeClass('selected_item')
          .closest('ion-item-sliding')
          .addClass('ion-hide')
          .removeClass('child_highlighted');

        removeSelectedItemFromCache($item);

        // hide bottom tabs bar if it can be hidden
        if (!feedit.countSelectedItemsCachedObjects()) {
          $multiselect_actions.addClass('ion-hide');

          // untick the "select all" checkbox, if checked
          feedit.untickSelectAllCheckbox();
        }

        // if there are no items left on page, refresh content
        if ( !$('#main ion-item-sliding').not('.ion-hide').length ) {
          if (typeof(dont_show_loading_when_content_empty) == undefined || dont_show_loading_when_content_empty !== true) {
            $doc.trigger('show-loading');
          }

          if (typeof(dont_refresh_content) == undefined || dont_refresh_content !== true) {
            $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
          }
        }
      },

      highlightItem($e, event) {
        if (typeof(event) != 'undefined' && event.target.tagName == 'ION-ITEM') {
          var
            $item_held = $(event.target),
            $item_parent = $item_held.parent('ion-item-sliding');
        } else {
          var
            $item_parent = $e,
            $item_held = $e.find('ion-item').first();
        }

        // bail out if we have a moving slide that's had a pointer down on it
        if ($item_parent.hasClass('item-sliding-active-slide')) {
          return;
        }

        // add highlight color
        $item_held
          .addClass('highlight_action_occured')
          .toggleClass('selected_item');

        if ($item_held.hasClass('selected_item')) {
          // add this item's info into cache
          addSelectedItemToCache( $item_held );
        } else {
          // remove this item's info from cache
          removeSelectedItemFromCache( $item_held );
        }

        // add informational class to the main ion-item-sliding parent node,
        // since the highlight class needs to be removed from the child when we're swipe-training
        // a link in order to keep the swiping animation (highlight class enforces no animation,
        // as to make the highlighting instant instead of animated)
        $e.toggleClass('child_highlighted');

        // remove the timer and the class
        $e
          .removeData('mouseheld_timer')
          .removeClass('mouseheld_timer');

        // remove the highlight "color action occurred" helper class after a moment,
        // so if we didn't click on the item through the H2 heading, we can still
        // open/close it by clicking on it without having to do that twice
        setTimeout(function () {
          $item_held.removeClass('highlight_action_occured');
        }, 500);

        // if this function was called programmatically, don't update the checkbox state
        // or we'd end up with highlighting what we don't want to highlight
        if (typeof(event) != 'undefined') {
          $doc.trigger('item-select', [$item_held]);
        } else {
          $doc.trigger('item-select', [ {'origin' : 'select_all'} ]);
        }
      },
    });
  };
})(jQuery);