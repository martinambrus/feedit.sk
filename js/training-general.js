(function($) {
  var
    $doc = $(document),
    training_opening = false,
    ignored_badge_bg_color = '#F4F4F4',
    ajax_url = 'api/training-modal.php',
    ajax_url_mark_read = 'api/training-mark-read.php',
    ajax_url_train_simple = 'api/training-simple.php',
    ajax_url_train_detailed = 'api/training-detailed.php',
    ajax_in_progress = false,
    $labels_select_badge,
    $filter_status,
    shift_key_down = false,
    num_ajaxes = 0; // current number of multi-training API calls to wait for

  $doc.on('app-init', function() {
    $labels_select_badge = $('#labels-select-badge');
    $filter_status = $('#filter_status');
    initModule();
  });

  function get_selected_items_training_data( train_way ) {
    // iterate over all selected items and collect their training data,
    // then trigger the amount of appropriate training events
    // (i.e. at least 1 for training up/down and possibly another one
    //  if any of the items are being un-trained)
    var final_data = {};

    for (var item_id of Object.keys( feedit.getSelectedItemsCache() )) {
      // if this item is still showing on page, select its badge,
      // so we can update the UI during the training
      var $badge = $('ion-item[data-id="' + item_id + '"] ion-badge.train_' + train_way);

      if (!$badge.length) {
        $badge = null;
      }

      var item_data = trainItem( $badge, train_way, true, feedit.getSelectedItemsCache()[ item_id ] );

      if (!final_data[ item_data.rating ]) {
        final_data[ item_data.rating ] = [];
      }

      final_data[ item_data.rating ].push( item_data.items[0] );
    }

    return final_data;
  };

  function trainSimpleAndWait( train_way, shift_was_pressed ) {
    var final_data = get_selected_items_training_data( train_way );

    // reset, just in case
    num_ajaxes = 0;

    for (var rating in final_data) {
      $doc.trigger('item-training', [ { 'rating' : rating, 'items' : final_data[ rating ] }, null, function() {
        num_ajaxes--;
      } ]);

      num_ajaxes++;
    }

    // fire up a check every 1s that will hide loading and refresh content (if needed)
    // once all of our above AJAX calls went through
    var timer = setInterval(function() {
      if (num_ajaxes == 0) {
        clearInterval(timer);
        timer = -1;

        if (typeof(backup_timeout) != 'undefined') {
          clearTimeout( backup_timeout );
        }

        // feedit.presentToast({'txt': window.lang.training_successful });

        // if there are no items left on page, refresh content after a timeout
        if ( !$('#main ion-item-sliding').not('.ion-hide').length ) {
          $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
        }
      }
    }, 1000);

    // set timeout function to cancel the above check after 5 seconds, as that would be a time something went wrong
    // and the num_ajaxes variable might never get to 0
    var backup_timeout = setTimeout(function() {
      if (timer > -1 && num_ajaxes > 0) {
        clearInterval(timer);
        timer = -1;
        num_ajaxes = 0;
        // feedit.presentToast({'txt': window.lang.training_successful });

        // if there are no items left on page, refresh content
        if ( !$('#main ion-item-sliding').not('.ion-hide').length ) {
          $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
        }
      }
    }, 120000);

    // only hide items if we've pressed SHIFT
    if ( shift_was_pressed ) {
      feedit.hide_all_selected_items( shift_was_pressed );
    }
  };

  // returns the full HTML for the article training slider
  // displayed in a modal
  function get_training_slider_html(items) {
    var html = [];

    for (var i in items) {
      var
        item = items[ i ],
        current_feed_id = feedit.getActiveFeedItem().data('id');

      html.push(`
      <ion-slide class="ion-text-start" data-id="${item.id}" data-sc="${item.score}" data-was-trained="` + (typeof(item.rated) != 'undefined' ? 1 : 0) + `"` + (typeof(item.rated) != 'undefined' ? ' data-original-training="' + item.rated + '"' : '') + `>
        <ion-text data-id="${item.id}">
          <h2>
            <a href="${item.link}" target="_blank" class="link_anchor">${item.title} &raquo;</a>
          </h2>
          <p class="link_content">`);

      if (item.img) {
        html.push(`<img src="${item.img}" class="ion-float-left right10px">`);
      }

      html.push(`
            <ion-badge color="light" title="${item.date_long}">
              <small>${item.date}</small>
            </ion-badge>
            <br>
            ${item.description}
          </p>

          <p class="actions">
            <ion-badge color="success" class="` + (typeof(item.rated) != 'undefined' ? (item.rated == 1 && item.rated !== '' ? 'icon_only' : 'ion-hide') + ' ' : '') + `train_up right5px">
              <ion-icon name="checkmark-sharp"` + (typeof(item.rated) != 'undefined' && item.rated !== '' ? '' : ' class="ion-float-left right5px"') + `></ion-icon>
              <ion-label` + (typeof(item.rated) != 'undefined' && item.rated !== '' ? ' class="ion-hide"' : '') + `><small>${window.lang.like}</small></ion-label>
            </ion-badge>
            <ion-badge color="danger" class="train_down right5px` + (typeof(item.rated) != 'undefined' ? ' ' + (item.rated == 0 && item.rated !== '' ? 'icon_only' : 'ion-hide') : '') + `">
              <ion-icon name="close-sharp"` + (typeof(item.rated) != 'undefined' && item.rated !== '' ? '' : ' class="ion-float-left right5px"') + `></ion-icon>
              <ion-label` + (typeof(item.rated) != 'undefined' && item.rated !== '' ? ' class="ion-hide"' : '') + `><small>${window.lang.dislike}</small></ion-label>
            </ion-badge>`);

      if (current_feed_id != 'bookmarks' && current_feed_id != 'all') {
        html.push(`
            <ion-badge class="labels right5px` + ($labels_select_badge.hasClass('ion-hide') ? ' ion-hide' : '') + `" color="dark">
              <ion-icon name="pricetag-outline" class="ion-float-left right5px"></ion-icon>
              <ion-label><small>${window.lang.labels}</small></ion-label>
            </ion-badge>`);
      }

      if (item.label_predictions) {
        for (var label_prediction of item.label_predictions) {
          html.push(`
            <ion-badge ` + (label_prediction.trust ? 'color="dark"' : 'style="background-color: #5E5E5E"') + ` class="label_badge" data-id="${label_prediction.id}">
              <small>${label_prediction.label}</small>
            </ion-badge>`);
        }
      }

      if (item.labels) {
        for (var label of item.labels) {
          html.push(`
            <ion-badge color="dark" class="label_badge" data-id="${label.id}">
              <small>${label.label}</small>
            </ion-badge>`);
        }
      }

      html.push(`
            </p>
            <ion-grid class="ion-no-padding training_switchbox">
              <ion-row class="ion-text-center">
                <ion-col data-id="words" class="ion-no-padding training_switchbox_column">
                  <ion-tab-button>
                    <ion-icon name="reader-outline" color="primary"></ion-icon>
                    <ion-label color="primary">${window.lang.words}</ion-label>
                  </ion-tab-button>
                </ion-col>
                <ion-col data-id="author" class="ion-no-padding training_switchbox_column">
                  <ion-tab-button>
                    <ion-icon name="person-outline"></ion-icon>
                    <ion-label>${window.lang.author}</ion-label>
                  </ion-tab-button>
                </ion-col>
                <ion-col data-id="categories" class="ion-no-padding training_switchbox_column">
                  <ion-tab-button>
                    <ion-icon name="grid-outline"></ion-icon>
                    <ion-label>${window.lang.categories}</ion-label>
                  </ion-tab-button>
                </ion-col>`);

      // trigger event for other modules to add their own columns
      $doc.trigger('training-modal-add-columns', [ html ]);

      // close the grid
      html.push(`
              </ion-row>
            </ion-grid>`);

      // trigger event which will add author HTML for the modal
      $doc.trigger('training-modal-add-html', [ {
        'type' : 'author',
        'item' : item,
        'html_array' : html,
        'ignored_badge_bg_color' : ignored_badge_bg_color
      } ]);

      // trigger event which will add words HTML for the modal
      $doc.trigger('training-modal-add-html', [ {
        'type' : 'words',
        'item' : item,
        'html_array' : html,
        'ignored_badge_bg_color' : ignored_badge_bg_color
      } ]);

      // trigger event which will add categories HTML for the modal
      $doc.trigger('training-modal-add-html', [ {
        'type' : 'categories',
        'item' : item,
        'html_array' : html,
        'ignored_badge_bg_color' : ignored_badge_bg_color
      } ]);

      // trigger event which will add categories HTML for the modal
      $doc.trigger('training-modal-add-html', [ {
        'type' : 'phrases',
        'item' : item,
        'html_array' : html
      } ]);

      html.push(`
        </ion-text>
      </ion-slide>`);
    }

    return html.join('');
  };

  // gathers basic training data, such as up/down vote for the whole article or per-word ratings
  function gatherBasicTrainingData($slide, data) {
    // iterate over all items selected for training
    // and add them as data
    $slide.find('ion-icon[color="primary"], ion-icon[color="purple"]').each(function() {
      var
        $e = $(this),
        $badge = $e.parent();

      if ($e.attr('name') == 'close-sharp' || $e.attr('name') == 'checkmark-sharp') {
        var new_rating = (($e.attr('name') == 'checkmark-sharp') ? 1 : 0);
        // up/down vote - check if it's different than its previous rating, if previously trained
        if (typeof($slide.data('was-trained')) == 'undefined' || $slide.data('original-training') != new_rating) {
          data['rate'] = new_rating;
        }
      } else if ($e.attr('name') == 'thumbs-down-sharp' || $e.attr('name') == 'thumbs-up-sharp') {
        // default intensity to positive/negative 300
        var intensity = (($e.attr('name') == 'thumbs-up-sharp') ? 300 : -300);

        // increase intensity
        if ($e.attr('color') == 'purple') {
          intensity = (($e.attr('name') == 'thumbs-up-sharp') ? 3000 : -3000);
        }

        // trigger an event that will call all training modules
        // to add their respective data
        $doc.trigger('training-data-gather', [ {
          'gather_type' : 'basic',
          'badge_type' : $badge.data('type'),
          '$icon' : $e,
          'intensity' : intensity,
          'data' : data
        } ]);
      }
    });
  };

  // gather data for a previously trained slide
  function gatherPreviouslyTrainedSlideData($slide, data) {
    if (
      // the item was previously trained
      $slide.data('was-trained') == 1 &&
      // both rating labels are showing, i.e. item is ready for a new rating
      $slide.find('ion-badge.train_up, ion-badge.train_down').not('.ion-hide').length == 2 &&
      // there is no rating selected for this slide
      !$slide.find('ion-icon[name="checkmark-sharp"].ion-color-primary, ion-icon[name="close-sharp"].ion-color-primary').length
    ) {
      data['rate'] = '-1';
    }
  };

  // gathers data for all newly ignored items
  function gatherNewlyIgnoredItemsData($slide, data) {
    $slide.find('ion-badge.ignored').each(function() {
      var $e = $(this);

      // don't send ignored items that were ignored before
      if ($e.data('was-ignored') == 1) {
        return;
      }

      // trigger an event that will call all training modules
      // to add their respective data
      $doc.trigger('training-data-gather', [ {
        'gather_type' : 'new_ignored',
        '$badge' : $e,
        'badge_type' : $e.data('type'),
        'data' : data
      } ]);

    });
  };

  // gather data for all previously ignored items
  function gatherPreviouslyIgnoredItemsData($slide, data) {
    $slide.find('ion-badge[data-was-ignored="1"]').each(function() {
      var $e = $(this);

      // check if the item is still ignored and if not and it's not been voted in either direction,
      // add it to the list of items
      if (!$e.hasClass('ignored') && !$e.find('ion-icon[color="primary"], ion-icon[color="purple"]').length) {
        // trigger an event that will call all training modules
        // to add their respective data
        $doc.trigger('training-data-gather', [ {
          'gather_type' : 'prev_ignored',
          '$badge' : $e,
          'badge_type' : $e.data('type'),
          'data' : data
        } ]);
      }
    });
  };

  // gathers data for all items to be removed
  function gatherItemsRemovalData($slide, data) {
    $slide.find('ion-badge.removal').each(function() {
      var $e = $(this);

      // trigger an event that will call all training modules
      // to add their respective data
      $doc.trigger('training-data-gather', [ {
        'gather_type' : 'removal',
        '$badge' : $e,
        'badge_type' : $e.data('type'),
        'data' : data
      } ]);

    });
  };

  // retrieves all data that is to be trained
  function getTrainingData() {
    var data = [];

    $('#training_slider ion-slide').each(function() {
      var
        $slide = $(this),
        slide_data = {
          'id' : $slide.data('id')
        };

      // all of the following methods update the data variable directly
      gatherBasicTrainingData($slide, slide_data);
      gatherPreviouslyTrainedSlideData($slide, slide_data);
      gatherNewlyIgnoredItemsData($slide, slide_data);
      gatherPreviouslyIgnoredItemsData($slide, slide_data);
      gatherItemsRemovalData($slide, slide_data);

      if (Object.keys(slide_data).length > 1) {
        data.push(slide_data);
      }
    });

    if (data.length) {
      return data;
    } else {
      return false;
    }
  };

  function presentTrainingModal( data ) {
    ajax_in_progress = true;

    var ajax_data = {
      'ids' : []
    };

    // if a single item training is being requested, add it into data,
    // otherwise add all items currently selected
    if (typeof(data) != 'undefined' && typeof(data.$element) != 'undefined') {
      ajax_data['ids'].push( data.$element.find('ion-item').data('id') );
    } else {
      // get IDs of all of the items selected and train them
      ajax_data['ids'] = Object.keys( feedit.getSelectedItemsCache() ).slice(0, 25);
    }

    // very unlikely situation - possibly a bug
    if (!ajax_data['ids'].length) {
      console.log('Error: no items to train, data:', data, ', selected:', feedit.getSelectedItemsCache());
      ajax_in_progress = false;
      training_opening = false;
      return;
    }

    feedit.callAPI(
      ajax_url,
      ajax_data,
      function( response ) {
        if (typeof(response) == 'object') {
          (async function() {
            // create the modal with the `modal-page` component
            $doc.trigger('open-modal', [
              await modalController.create({
                component: 'modal-training',
                componentProps: {
                  'items': response
                },
              })]);

          })();
          training_opening = false;
        } else {
          $doc.trigger('hide-loading');
          feedit.presentToast({ 'txt' : window.lang.ajax_error_maintenance, 'duration' : 20000, });
        }
      },
      null,
      function() {
        ajax_in_progress = false;
        training_opening = false;
      })
    ;
  };

  // used when either a single up/down training badge or the like tab button is clicked on
  // ... will either directly send a training event (if a badge is clicked) or return training data
  //     for the currently trained item, so it can be added to an array of all items to be trained
  //     via back-end
  function trainItem($badge, way, collect_only, training_data) {
    // if this item has already been trained down, remove all hidden classes
    // and send relevant training info
    var
      opposite_way = (way == 'up' ? 'down' : 'up'),
      rating_value = (way == 'up' ? 1 : 0);

    // this item has already been trained and we're un-training it now
    if (
      (typeof(training_data) != 'undefined' && training_data.trained == rating_value) ||
      (
        $badge !== null &&
        (
          (
            // in simple mode, check for the shown-outside-simple-mode class
            // when the opposite icon would not be shown in normal mode, this icon was previously trained using our rating
            feedit.isSimpleMode() &&
            !$badge.siblings('ion-badge.train_' + opposite_way).hasClass('shown-outside-simple-mode')
          )
          ||
          (
            // in normal mode, check for the ion-hide class
            // when the opposite icon is not being shown, this icon was previously trained using our rating
            !feedit.isSimpleMode() &&
            $badge.siblings('ion-badge.train_' + opposite_way).hasClass('ion-hide')
          )
        )

      )
    ) {
      // work with the badge only if it's still on page
      // and we're not getting data from a cached list of selected items
      if ($badge !== null) {
        // we're in simple mode, toggle shown-outside-simple-mode classes
        if (feedit.isSimpleMode()) {
          $badge
            .siblings('ion-badge.train_' + opposite_way + '')
            .addClass('shown-outside-simple-mode') // show both TRAIN icons
            .siblings('ion-badge.trained_up, ion-badge.trained_down')
            .addClass('ion-hide'); // hide both TRAINED icons
        } else {
          // we're in normal mode, toggle ion-hide classes
          $badge
            .siblings('ion-badge.train_' + opposite_way)
            .removeClass('ion-hide') // show both TRAIN icons
            .siblings('ion-badge.trained_up, ion-badge.trained_down')
            .addClass('ion-hide'); // hide both TRAINED icons
        }
      }

      var data = {
        'rating': -1,
        'items': [ (typeof(training_data) != 'undefined' ? training_data.id : $badge.closest('ion-item').data('id')) ]
      };

      // update rating of this item in cache
      if (typeof(training_data) != 'undefined') {
        training_data.trained = -1;
      }

      if (typeof(collect_only) == 'undefined') {
        // rating of -1 means we should reverse this item's training
        $doc.trigger('item-training', [ data ]);
      } else {
        return data;
      }
    } else {
      // training this item for the first time
      // ... work with the badge only if it's still on page
      //     and we're not getting data from a cached list of selected items
      if ($badge !== null) {
        // we're in simple mode, toggle shown-outside-simple-mode classes
        if (feedit.isSimpleMode()) {
          $badge
            .siblings('ion-badge.train_' + opposite_way)
            .removeClass('shown-outside-simple-mode') // hide opposite TRAIN icon
            .siblings('ion-badge.trained_' + opposite_way)
            .addClass('ion-hide') // hide opposite TRAINED icon
            .siblings('ion-badge.train_' + way)
            .addClass('shown-outside-simple-mode') // show current TRAIN icon
            .siblings('ion-badge.trained_' + way)
            .removeClass('ion-hide'); // show current TRAINED icon
        } else {
          // we're in normal mode, toggle ion-hide classes
          $badge
            .siblings('ion-badge.train_' + opposite_way + ', ion-badge.trained_' + opposite_way)
            .addClass('ion-hide')
            .siblings('ion-badge.train_' + way + ', ion-badge.trained_' + way)
            .removeClass('ion-hide');
        }
      }

      var
        rating = (way == 'up' ? 1 : 0),
        data = {
        'rating': rating,
        'items': [ (typeof(training_data) != 'undefined' ? training_data.id : $badge.closest('ion-item').data('id')) ]
      };

      // update rating of this item in cache
      if (typeof(training_data) != 'undefined') {
        training_data.trained = rating;
      }

      if (typeof(collect_only) == 'undefined') {
        $doc.trigger('item-training', [ data ]);
      } else {
        return data;
      }
    }
  };

  function initModule() {

    // prepare the training modal template
    customElements.define('modal-training', class extends HTMLElement {
      connectedCallback() {
        this.innerHTML = `
  <ion-header>
    <ion-toolbar>
      <ion-title>${window.lang.article_training}</ion-title>
      <ion-buttons slot="primary">
        <ion-button id="training_confirm">
          <ion-icon slot="icon-only" name="checkmark-outline" color="primary"></ion-icon>
        </ion-button>
        <ion-button onClick="feedit.showHelp($(this).find('ion-icon')[0], 'train-link')">
          <ion-icon slot="icon-only" name="information-circle-outline" title="${window.lang.how_to_train_article}"></ion-icon>
        </ion-button>
        <ion-button onClick="feedit.dismissModal()">
          <ion-icon slot="icon-only" name="close"></ion-icon>
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content class="ion-padding">
    <ion-slides id="training_slider" pager="true">`
          + get_training_slider_html( feedit.getTopModal().componentProps.items );

        $('ion-slides:first')[0].options = {
          threshold : 10,
          shortSwipes: false,
          longSwipesMs : 100,
          longSwipesRatio: 0.1,
          keyboard: {
            enabled: true,
            onlyInViewport: false,
          },
        };

        // mobile firefox seems to freeze on the loading slide when slider is created
        // and the modal is opened more than once... an update eliminates that problem
        setTimeout(function() {
          $('ion-slides:first')[0].update();
        }, 1000);
      }
    });


    // when user clicks on a training button to switch tab, highlight the selected tab
    // and show relevant content
    $doc.on('click', '.training_switchbox_column', function() {
      var $e = $(this);

      $e.find('ion-icon, ion-label').attr('color', 'primary');
      $e.siblings('ion-col').find('ion-icon, ion-label').removeAttr('color');

      // hide all previously showing paragraphs for this segment and show the relevant one
      $e.closest('ion-text').find('.training_content_' + $e.data('id'))
        .removeClass('ion-hide')
        .siblings('.added_actions')
        .addClass('ion-hide');
    });

    // click on a pagination bullet on a training slider will paginate to that article
    // for training
    $doc.on('click', '.swiper-pagination-bullet', function() {
      $('ion-slides:first')[0].slideTo( $(this).index() );
    });

    // train item on double-click when the "training" badge is not visible,
    // i.e. on mobile resolutions
    $doc.on('doubleclick',  'ion-item', function() {
      // we're only interested in double-clicks on links and icons
      if (this.className.indexOf('link') == -1) {
        return;
      }

      // only train on double-click if the "training" badge is not visible
      if (!$('ion-badge.train:visible').length && (feedit.isSimpleMode() || (!feedit.isSimpleMode() && !feedit.isDesktopSized())) && $(this).data('id')) {
        $doc.trigger('item-training-detailed', { '$element' : $(this).closest('ion-item-sliding') } );
      }
    });

    // double-click on a up/down rating icon in the training modal
    // will make it purple, thus outlining an increased rate weight
    $doc.on('doubleclick',  '#training_slider ion-badge ion-icon', function(event) {
      // only handle up/down rating icons
      if (event.target.name == 'thumbs-down-sharp' || event.target.name == 'thumbs-up-sharp') {
        var $e = $(event.target);

        $e
          .addClass('longpressed') // this prevents the click handler to also vote up/down this item
          .attr('color', 'purple')
          .siblings()
          .removeAttr('color');

        // remove click handler prevention class after a timeout
        setTimeout(function() {
          $(event.target).removeClass('longpressed');
        }, 250);
      }
    });

    // long press on a training icon - make it purple
    $doc.on('longpress', function(event) {
      if ( event.target.tagName == 'ION-ICON' && (event.target.name == 'thumbs-down-sharp' || event.target.name == 'thumbs-up-sharp') ) {
        var $e = $(event.target);

        $e.addClass('longpressed')
          .attr('color', 'purple')
          .siblings()
          .removeAttr('color')
          .one('mouseleave', function() {
            // when we mouseout from this element and release the button there,
            // no click event will be called and our longpressed class will
            // fail to get removed... so we set a backup event here
            $e.removeClass('longpressed');
          });
      }
    });

    // up/down thumb training
    $doc.on('click', 'ion-slide p.actions ion-icon, ion-slide p.added_actions ion-icon', function() {
      var $e = $(this);

      // this needs to be on timeout, as long-press will come before click,
      // thus this click would rewrite the long-press training coloring
      // and will not remove longpressed class
      setTimeout(function() {
        var ignored_icons = [
          'checkmark-sharp',
          'close-sharp',
          'eye-off-sharp',
          'pricetag-outline'
        ];

        // fire up event that will gather any additional icon names for which to ignore up/down training
        // from each of the training modules
        $doc.trigger('training-get-excepted-icon-names', [ ignored_icons ]);

        // don't train vote up/down badges, hidden words or additional icons from other training modules
        if ($e.attr('name').match(new RegExp( ignored_icons.join('|') ))) {
          return;
        }

        if (($e.attr('color') == 'primary' || $e.attr('color') == 'purple') && !$e.hasClass('longpressed')) {
          // fire an event for all modules to check whether this training can be undone
          // ... used for example for newly added phrases, which need an up/down training
          //     in order for them to be sent to backend at all
          var
            can_be_deselected = [ true ], // value in array to allow changing it when passed over an event
            $badge = $e.parent('ion-badge');

          $doc.trigger('training-check-can-be-deselected', [ $badge, can_be_deselected ]);

          // de-select, if we can
          if (can_be_deselected[0]) {
            $e.removeAttr('color');
            return;
          } else {
            // add a "this had its color changed" class, if we were purple before
            if ($e.attr('color') == 'purple') {
              $badge.addClass('was_purple');
              // remove that class after 1s, after all modules can see that it was purple :)
              setTimeout(function() {
                $badge.removeClass('was_purple');
              }, 1000);
            }
          }
        }

        $e.siblings().removeAttr('color');

        // if this click comes from a long-press, bail out
        // but remove the longpressed class
        if ($e.hasClass('longpressed')) {
          $e.removeClass('longpressed');
          return;
        }

        $e.attr('color', 'primary');
      }, 1);
    });

    // like/unlike link training
    $doc.on('click', 'ion-slide p.actions ion-badge', function() {
      var
        $e = $(this),
        $icon = $e.find('ion-icon'),
        name = $icon.attr('name'),
        // this variable is in an array, as arrays are mutable
        // when passed over events, while booleans are not
        can_train = [(typeof(name) != 'undefined' && (name.match(/checkmark-sharp|close-sharp/) !== null))];

      // fire up an event that will be picked up by all training modules
      // and set can_train to false if the icon or badge are used for a separate module action
      $doc.trigger('training-can-like-unlike', [ {
        '$badge' : $e,
        '$icon' : $icon,
        'can_train' : can_train
      } ] );

      if (!can_train[0]) {
        return;
      }

      // first of all, check whether this item has not been trained yet
      // and if it was, unhide training badges, as we're about to re-train it
      if (!$icon.siblings('ion-label:visible').length) {
        // trained links found, unhiding badges
        $icon.parent()
          .removeClass('icon_only') // parent badge class
          .parent() // parent p.actions element
          .find('.train_up, .train_down')
          .removeClass('ion-hide') // unhide hidden badges - which are the training ones
          .find('ion-icon')
          .addClass('ion-float-left right5px') // add float and margin to icons
          .siblings('ion-label')
          .removeClass('ion-hide'); // unhide icon labels
      }

      // de-select
      if ($icon.attr('color') == 'primary') {
        $icon.removeAttr('color');
        return;
      }

      $icon.attr('color', 'primary');

      if (name == 'checkmark-sharp') {
        $e.parent().find('ion-icon[name="close-sharp"]').removeAttr('color');
      } else {
        $e.parent().find('ion-icon[name="checkmark-sharp"]').removeAttr('color');
      }
    });

    // item training - ignoring
    $doc.on('click', 'ion-slide p.actions ion-label, ion-slide p.added_actions ion-label', function() {
      var
        $parent_badge = $(this).parent(),
        ignored_classes = [
        'train_up',
        'train_down',
        'labels',
      ];

      // fire up event that will gather any additional class names for which to ignore up/down training
      // from each of the training modules
      $doc.trigger('training-get-ignoring-excepted-class-names', [ ignored_classes ]);

      // don't train action badges or labels or badge classes from other training modules
      if ($parent_badge.attr('class').match(new RegExp( ignored_classes.join('|') ))) {
        return;
      }

      $parent_badge
        // add class to current word badge
        .toggleClass('ignored')
        // change BG color of the badge
        .css('background-color', ($parent_badge.hasClass('ignored') ? ignored_badge_bg_color : $parent_badge.data('unignored-color')))
        .attr('title', ($parent_badge.hasClass('ignored') ? window.lang.word_ignored_from_scoring : $parent_badge.find('ion-label').text()));

      // show/hide all icons that were hidden/showing before
      var $icon = $parent_badge.find('ion-icon').toggleClass('ion-hide');

      // store or restore last training as data
      $icon.each(function() {
        var $e = $(this);

        // store color info
        if ($e.hasClass('ion-hide')) {
          $e
            .data('trained-color', $e.attr('color')) // store training data
            .removeAttr('color');                    // remove any training
        } else {
          // restore color info
          if (typeof($e.data('trained-color')) != 'undefined') {
            $e
              .attr('color', $e.data('trained-color')) // re-add trained color
              .removeData('trained-color');            // remove training data
          }
        }
      });
    });

    // item training - removal (for supported modules)
    $doc.on('click', 'ion-slide p.added_actions ion-label', function() {
      var
        $parent_badge = $(this).parent(),
        included_classes = [];

      // fire up event that will gather all removal-supported badge classes
      // from all of the training modules
      $doc.trigger('training-get-removal-supported-class-names', [ included_classes ]);

      // don't remove action badges or labels or badge classes from other training modules
      if (!$parent_badge.attr('class').match(new RegExp( included_classes.join('|') ))) {
        return;
      }

      $parent_badge
        // add class to current word badge
        .toggleClass('removal')
        .css('background-color', ($parent_badge.hasClass('removal') ? '' : $parent_badge.data('unremoved-color')))
        .attr('color', ($parent_badge.hasClass('removal') ? 'danger' : ''))
        .attr('title', ($parent_badge.hasClass('removal') ? window.lang.item_will_be_removed : $parent_badge.find('ion-label').text()));

      // show/hide all icons that were hidden/showing before
      var $icon = $parent_badge.find('ion-icon').toggleClass('ion-hide');

      // store or restore last training as data
      $icon.each(function() {
        var $e = $(this);

        // store color info
        if ($e.hasClass('ion-hide')) {
          $e
            .data('trained-color', $e.attr('color')) // store training data
            .removeAttr('color');                    // remove any training
        } else {
          // restore color info
          if (typeof($e.data('trained-color')) != 'undefined') {
            $e
              .attr('color', $e.data('trained-color')) // re-add trained color
              .removeData('trained-color');            // remove training data
          }
        }
      });
    });

    // react to either a multiple-selection-training event, originating from a tab button
    // or a simple click on the training badge on a single item
    $doc.on('item-training-detailed', function(event, data) {
      if (training_opening || ajax_in_progress) {
        return;
      }

      training_opening = true;
      $doc.trigger('show-loading');
      presentTrainingModal( data );
    });

    // this is used in keyboard shortcuts to fire up training event
    // with the appropriate waiting time and everything
    $doc.on('train-simple-multi', function(event, data) {
      trainSimpleAndWait( data.way, (typeof(data.dont_refresh_content) !== 'undefined' ? data.dont_refresh_content : false) );
    });

    // click on tab button for multiple selected links upvote
    $doc.on('click', '#multi_vote_up', function ( event ) {
      // if we've got a longpressed class, bail out
      var $e = $(this);

      if ($e.hasClass('longpressed')) {
        $e.removeClass('longpressed');
        return true;
      }

      trainSimpleAndWait( 'up', (event.shiftKey ? true : false) );
    });

    // train selected items down AND hide them
    $doc.on('longpress', '#multi_vote_up', function () {
      // add a longpressed class, so the click event won't trigger this again
      var $e = $(this);
      $e.addClass('longpressed');

      trainSimpleAndWait( 'up', true );
    });

    // click on tab button for multiple selected links downvote
    $doc.on('click', '#multi_vote_down', function ( event ) {
      // if we've got a longpressed class, bail out
      var $e = $(this);

      if ($e.hasClass('longpressed')) {
        $e.removeClass('longpressed');
        return true;
      }

      trainSimpleAndWait( 'down', (event.shiftKey ? true : false) );
    });

    // train selected items down AND hide them
    $doc.on('longpress', '#multi_vote_down', function () {
      // add a longpressed class, so the click event won't trigger this again
      var $e = $(this);
      $e.addClass('longpressed');

      trainSimpleAndWait( 'down', true );
    });

    // click on tab button for training multiple selected links
    $doc.on('click', '#multi_train', function () {
      $doc.trigger('item-training-detailed');
    });

    // click on tab button for making multiple selected links read
    $doc.on('click', '#multi_mark_read', function (event) {
      // if we've got a longpressed class, bail out
      var $e = $(this);

      if ($e.hasClass('longpressed')) {
        $e.removeClass('longpressed');
        return true;
      }

      var data = {
        'items' : Object.keys( feedit.getSelectedItemsCache() )
      };

      // check that we're not actually making these items un-read
      var making_unread = true;
      for (var item_id of data.items) {
        if ($('#main ion-item-sliding ion-item[data-id="' + item_id + '"] h2.unread').length) {
          making_unread = false;
          break;
        }
      }

      $doc.trigger('item-read', [ data, {
        'successCallback' : function() {
          // only hide items if we're not makin the item un-read and we've pressed the shift key
          if (!making_unread && shift_key_down) {
            feedit.hide_all_selected_items();
          }
        }
      } ]);
    });

    // mark all items read AND hide them
    $doc.on('longpress', '#multi_mark_read', function () {
      // add a longpressed class, so the click event won't trigger this again
      var $e = $(this);
      $e.addClass('longpressed');

      $doc.trigger('item-read', [ {'items' : Object.keys( feedit.getSelectedItemsCache() )} ]);

      feedit.hide_all_selected_items();
    });

    // click on a single link's upvote badge
    $doc.on('click', '#main ion-badge.train_up', function () {
      var $e = $(this);

      // if the item is selected, pass its cached data to the training method, so they can be updated
      if (feedit.getSelectedItemsCache()[ $e.closest('ion-item').data('id') ]) {
        trainItem($e, 'up', false, feedit.getSelectedItemsCache()[ $e.closest('ion-item').data('id') ]);
      } else {
        trainItem($e, 'up');
      }
    });

    // click on a single link's downvote badge
    $doc.on('click', '#main ion-badge.train_down', function () {
      var $e = $(this);
      // if the item is selected, pass its cached data to the training method, so they can be updated
      if (feedit.getSelectedItemsCache()[ $e.closest('ion-item').data('id') ]) {
        trainItem($e, 'down', false, feedit.getSelectedItemsCache()[ $e.closest('ion-item').data('id') ]);
      } else {
        trainItem($e, 'down');
      }
    });

    // click on the train badge on desktop resolutions
    $doc.on('click', 'ion-badge.train', function () {
      $doc.trigger('item-training-detailed', { '$element' : $(this).closest('ion-item-sliding') });
    });

    // shift key held down detection
    $doc.on('keydown', function(event) {
      shift_key_down = (event.shiftKey ? 1 : 0);
    });

    // shift key held down detection
    $doc.on('keyup', function(event) {
      shift_key_down = (event.shiftKey ? 1 : 0);
    });

    // simple up/down training of item(s)
    $doc.on('item-training', function(event, data, successCallback, alwaysCallback) {
      feedit.showMainTitleLoadingIndicator();
      feedit.change_pagination_to_reload();

      // show loading if we're training a full feed
      if (typeof(data.items) == 'undefined') {
        $doc.trigger('show-loading');
      } else {
        // mark all trained items as read
        for (var item_id of data.items) {
          // mark item read
          var $item = $('#main ion-item-sliding ion-item[data-id="' + item_id + '"]');
          $item.find('h2.unread').removeClass('unread');

          // mark item trained/untrained
          if (data.rating != -1) {
            $item.addClass('item_is_trained');
          } else {
            $item.removeClass('item_is_trained');
          }
        }
      }

      feedit.callAPI(
        ajax_url_train_simple,
        data,
        function( response ) {
          if (response == '') {
            // remove label predictions from all items that's been trained
            if (typeof(data.items) != 'undefined') {
              for (var item_id of data.items) {
                var $item = $('#main ion-item-sliding ion-item[data-id="' + item_id + '"]');
                $item.find('ion-badge.prediction').remove();
              }

              feedit.show_hide_cleanup_icon();
            } else {
              // refresh content if we trained all articles at once
              $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);

              // hide the cleanup icon
              $('#feed_cleanup_icon').addClass('ion-hide');
            }

            // our unread counts would have changed after training
            $doc.trigger('refresh-link-counts', [ {'what' : 'all'}, feedit.refreshMainTitle ]);

            if (typeof(successCallback) == 'function') {
              successCallback();
            }
          } else {
            feedit.defaultErrorNotification();
          }
        },
        null,
        function() {
          $doc.trigger('hide-loading');
          feedit.hideMainTitleLoadingIndicator();

          if (typeof(alwaysCallback) == 'function') {
            alwaysCallback();
          }
        },
      );
    });

    // simple making item(s) read
    $doc.on('item-read', function(event, data, options) {
      // we're marking the whole feed read
      if (data.items == 'all') {
        $doc.trigger('show-loading');
      } else {
        // marking read will remove the unread H2 class
        for (var i in data.items) {
          var
            item_id = data.items[i],
            is_read = $('ion-item[data-id="' + item_id + '"] h2.unread').length;

          if (is_read) {
            $('ion-item[data-id="' + item_id + '"] h2.unread').removeClass('unread');
            data.items[i] = {
              'id': item_id,
              'read': 1,
            };
          } else {
            $('ion-item[data-id="' + item_id + '"] h2:first').addClass('unread');
            data.items[i] = {
              'id': item_id,
              'read': 0,
            };
          }
        }

        feedit.show_hide_cleanup_icon();
      }

      feedit.callAPI(
        ajax_url_mark_read,
        data,
        function( response ) {
          if (response == '') {
            if (typeof(options) != 'undefined' && typeof(options.successCallback) == 'function') {
              options.successCallback();
            }

            if (data.items == 'all') {
              // refresh content if we marked all articles at once
              $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);

              // hide the cleanup icon
              $('#feed_cleanup_icon').addClass('ion-hide');
            }

            $doc.trigger('refresh-link-counts', [ {'what' : 'all'}, feedit.refreshMainTitle ]);
          } else {
            feedit.defaultErrorNotification();
          }
        },
        null,
        function() {
          $doc.trigger('hide-loading');

          if (typeof(alwaysCallback) == 'function') {
            alwaysCallback();
          }
        },
      );
    });

    // clicking on the training badge will show the full training UI
    $doc.on('click', '.training_unhide', function() {
      $(this)
        .addClass('ion-hide')
        .next('p')
        .removeClass('ion-hide');
    });

    // clicking on the tick icon on top-right of the training modal
    $doc.on('click', '#training_confirm', function() {
      var data;
      if (data = getTrainingData()) {
        feedit.change_pagination_to_reload();

        $doc.trigger('show-loading');

        feedit.callAPI(
          ajax_url_train_detailed,
          { 'data' : data },
          function( response ) {
            if (response == '' || response.auto_rated_items) {
              for (var i in data) {
                var $item = $('#main ion-item-sliding ion-item[data-id="' + data[ i ].id + '"]');

                // remove label predictions from all items that's been trained
                $item.find('ion-badge.prediction').remove();

                // show/hide appropriate training badges
                if (typeof(data[ i ].rate) != 'undefined' || (typeof(response.auto_rated_items) != 'undefined' && typeof(response.auto_rated_items[ data[ i ].id ]) != 'undefined')) {
                  var rating = (typeof(data[ i ].rate) != 'undefined' ? data[ i ].rate : response.auto_rated_items[ data[ i ].id ]);
                  switch (parseInt( rating )) {
                    case 0 :
                      if (feedit.isSimpleMode()) {
                        // add shown-outside-simple-mode class to the valid badge
                        $item.find('.train_up, .trained_up').removeClass('shown-outside-simple-mode').addClass('ion-hide');
                        $item.find('.train_down, .trained_down').addClass('shown-outside-simple-mode').not('.train_down').removeClass('ion-hide');
                      } else {
                        // add and remove ion-hide classes from valid badges
                        $item.find('.train_up, .trained_up').addClass('ion-hide');
                        $item.find('.train_down, .trained_down').removeClass('ion-hide');
                      }

                      $item.addClass('item_is_trained');
                      break;

                    case 1 :
                      if (feedit.isSimpleMode()) {
                        // add shown-outside-simple-mode class to the valid badge
                        $item.find('.train_up, .trained_up').addClass('shown-outside-simple-mode').not('.train_up').removeClass('ion-hide');
                        $item.find('.train_down, .trained_down').removeClass('shown-outside-simple-mode').addClass('ion-hide');
                      } else {
                        // add and remove ion-hide classes from valid badges
                        $item.find('.train_up, .trained_up').removeClass('ion-hide');
                        $item.find('.train_down, .trained_down').addClass('ion-hide');
                      }

                      $item.addClass('item_is_trained');
                      break;

                    case -1 :
                      if (feedit.isSimpleMode()) {
                        // show all badges, as the link is being untrained
                        $item.find('.train_up, .trained_up, .train_down, .trained_down').addClass('shown-outside-simple-mode');
                      } else {
                        // show all badges, as the link is being untrained
                        $item.find('.train_up, .trained_up, .train_down, .trained_down').removeClass('ion-hide');
                      }

                      $item.removeClass('item_is_trained');
                      break;
                  }
                }
              }

              // our unread counts would have changed after training
              $doc.trigger('refresh-link-counts', [ {'what' : 'all'}, feedit.refreshMainTitle ]);

              feedit.presentToast({ 'txt' : window.lang.training_successful });

              if (typeof(successCallback) == 'function') {
                successCallback();
              }

              // hide items if we've held the shift key
              if ( shift_key_down ) {
                // we've trained a selection
                if (data.length > 1) {
                  feedit.hide_all_selected_items();
                } else {
                  // we've trained a single item - we'll still need a for loop
                  // because the first item index could be something else than 0
                  for (var i in data) {
                    feedit.hide_single_item($('#main ion-item-sliding ion-item[data-id="' + data[ i ].id + '"]'));
                  }
                }
              }

              feedit.show_hide_cleanup_icon();
            } else {
              feedit.defaultErrorNotification();
            }
          },
          null,
          function() {
            $doc.trigger('hide-loading');
          }
        );
      }

      $doc.trigger('dismiss-modal');
    });

    $('ion-content').on('click', '.item_link', function ( event ) {
      // if there are any items currently being highlighted,
      // we need to cancel the click to not open links while highlighting items
      // unless SHIFT has been held down
      if (!event.shiftKey && $('.selected_item').length) {
        // use preventDefault() instead of return false, as we need this event to bubble
        event.preventDefault();
      }
    });

    // clicking the H2's link will train that link positively
    $('ion-content').on('mouseup', '.item_link', function ( event ) {
      // only react to left and middle clicks
      if (event.which === 3) {
        return true;
      }

      var $item = $(this).closest('ion-item');

      // bail out if we were highlighting this item or if it's read already
      if ($item.hasClass('highlight_action_occured') || !$item.find('h2.unread').length || $('.selected_item').length) {
        return;
      }

      $doc.trigger('item-read', [ {
        'items' : [ $item.data('id') ]
      }]);

      /*
      // bail out if we were highlighting this item of if the item is already marked read
      if ($item.hasClass('highlight_action_occured') || $item.hasClass('item_is_trained')) {
        return;
      }

      // train the item positively, if SHIFT was not pressed
      trainItem($item.find('ion-badge.train_up'), 'up');
      */
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      markFeedRead( feed_id ) {
        $doc.trigger('item-read', [ {
          'feed' : feed_id,
          'items' : 'all',
        } ] );
      },

      trainAllUp( feed_id ) {
        $doc.trigger('item-training', [ {
          'feed' : feed_id,
          'rating' : 1,
        } ] );
      },

      trainAllDown( feed_id ) {
        $doc.trigger('item-training', [ {
          'feed' : feed_id,
          'rating' : 0,
        } ] );
      },

      // changes pagination to reload, if we're in order-by-score mode, if not changed already
      // ... used when training items to prevent next / prev page giving out wrong results
      change_pagination_to_reload() {
        if ($('#filter_sort').val() == 'score') {
          var $prev_and_next = $('#prev_page, #next_page').not('.force_reload');

          if ( $prev_and_next.length ) {
            $prev_and_next
              .addClass('force_reload')
              .find('ion-label')
              .html('<ion-icon name="sync-outline" class="right5px"></ion-icon> ' + window.lang.reload_content_changed);
          }
        }
      },

      // shows or hides the cleanup icon, based on trained and read items presence on page
      show_hide_cleanup_icon() {
        // check if there are any read or trained items on page and show the cleanup icon, if there are
        if ( $('#main ion-item-sliding:not(.ion-hide) ion-item h2').not('.feed_body, .unread').length || $('.item_is_trained:visible').length ) {
          // don't duplicate the icon, if already present
          if (!$('#feed_cleanup_icon').length) {
            $('#active_feed_title_header > span').prepend('<ion-icon id="feed_cleanup_icon" title="' + window.lang.hide_all_trained_articles + '" name="checkmark-done-outline" class="right5px ion-float-left"></ion-icon>');
          } else {
            // just in case it's hidden, unhide it
            $('#feed_cleanup_icon').removeClass('ion-hide');
          }
        } else {
          // no unread items, check if we have any trained items to hide
          if ( !$('.item_is_trained:visible').length ) {
            // nothing trained/read to clean up, hide the cleanup icon
            $('#feed_cleanup_icon').addClass('ion-hide').removeClass('re-show-after-load');
          }
        }
      },
    });
  };
})(jQuery);