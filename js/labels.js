(function($) {
  var
    $doc = $(document),
    // holds last selection of labels from the main labels dropdown in content,
    // so we're able to compare whether it's been changed when we confirm the labels dialog
    labels_selected,
    labels_cached = {},
    labels_manager_opening = false,
    labels_manager_update_data = {}, // used to hold information about labels that's been changed, added or removed
                                     // when the labels manager modal is open
    ajax_url_fetch_labels = 'api/labels_get.php',
    ajax_url_save_labels = 'api/labels_save.php',
    ajax_url_give_labels = 'api/labels_give.php',
    $filter_labels;

  function stabilizePredictedLabels() {
    // assemble IDs of all selected items
    var
      links = Object.keys( feedit.getSelectedItemsCache() ),
      label2link = {},
      links_with_multiple_predictions = [];

    // there is no other way but to fire up a single AJAX for each
    for (var item_id of links) {
      var $predictions = $('#main ion-item-sliding ion-item[data-id="' + item_id + '"] ion-badge.prediction');

      // assign this link to the single prediction label
      if ($predictions.length == 1) {
        var prediction_id = $predictions.first().data('id');

        if (typeof (label2link[ prediction_id ]) == 'undefined') {
          label2link[ prediction_id ] = [];
        }

        label2link[ prediction_id ].push( item_id );
      } else {
        // add this links with all of its labels into links_with_multiple_predictions
        // for the final processing
        $predictions.each(function () {
          var $e = $(this);

          if (typeof (links_with_multiple_predictions[ item_id ]) == 'undefined') {
            links_with_multiple_predictions[ item_id ] = [];
          }

          links_with_multiple_predictions[ item_id ].push( $e.data('id') );
        });
      }
    }

    // fire up API call for each of the single labels we found
    for ( var label_id in label2link ) {

      var
        label_text = $('ion-select-option[value="' + label_id + '"]').first().text(),
        new_cached_labels = [{
          'id': label_id,
          'txt': label_text,
        }];

      // rename this label for all the links, replacing the asterisk by a name without it
      // and remove the prediction class
      $('#main ion-item-sliding ion-item ion-badge.prediction[data-id="' + label_id + '"]')
        .removeClass('prediction')
        .find('small')
        .text( label_text );

      feedit.callAPI(
        ajax_url_give_labels,
        {
          'links': label2link[ label_id ],
          'labels': [ label_id ],
        },
        function (response) {
          if (response == '') {
            // update cache
            for (var link_id of label2link[ label_id ]) {
              for (var item_key of links) {
                if (link_id == item_key) {
                  feedit.getSelectedItemsCache()[ item_key ].labels = new_cached_labels;
                }
              }
            }

            feedit.presentToast({'txt': window.lang.labels_updated});
          } else {
            feedit.defaultErrorNotification();
          }
        },
      );
    }

    // fire up API call for each of the multi-label prediction links we found
    for ( var item_id in links_with_multiple_predictions ) {
      feedit.callAPI(
        ajax_url_give_labels,
        {
          'links': [ item_id ],
          'labels': links_with_multiple_predictions[ item_id ],
        },
        function (response) {
          if (response == '') {
            // for each of the labels, update the label and update cache
            var new_cached_labels = [];

            for (var label_id of links_with_multiple_predictions[ item_id ]) {
              var label_text = $('ion-select-option[value="' + label_id + '"]').first().text();

              new_cached_labels.push({
                'id': label_id,
                'txt': label_text,
              });

              // rename this label for all the links, replacing the asterisk by a name without it
              // and remove the prediction class
              $('#main ion-item-sliding ion-item ion-badge.prediction[data-id="' + label_id + '"]')
                .removeClass('prediction')
                .find('small')
                .text(label_text);
            }

            // update cache for this link
            feedit.getSelectedItemsCache()[ item_id ].labels = new_cached_labels;
          } else {
            feedit.defaultErrorNotification();
          }
        },
      );
    }
  };

  function cache_labels() {
    labels_cached = {};
    $filter_labels.find('ion-select-option').each(function() {
      var $e = $(this);
      labels_cached[ $e.val() ] = $e.text();
    });
  };

  $doc.on('app-init', function() {
    $filter_labels = $('#filter_labels');
    labels_selected = $filter_labels.val();
    initModule();
  });

  // opens the labels manager modal
  async function presentLabelsManagerModal( callback ) {
    // create the modal with the `modal-page` component
    $doc.trigger('open-modal', [
      await modalController.create({
        component: 'modal-labels-manage',
      }),
      callback,
    ]);

    labels_manager_opening = false;
  };

  // shows a modal with dropdown containing all labels for current feed,
  // allowing user to un/assign these labels to/from links
  function showLabelsTrainer( $e ) {
    $doc.trigger('show-loading');
    $('#labelsTrainer').remove();

    // assemble options
    var
      select_html = [ '<ion-select id="labelsTrainer" interface="alert" cancel-text="' + window.lang.cancel + '" ok-text="' + window.lang.confirm + '" multiple="true" class="ion-no-padding ion-hide" data-initialized="0"' ],
      selected_options = [],
      interfaceOptions = {},
      current_feed_id = feedit.getActiveFeedId();

    // add an appropriate data item into the ion-select tag
    if ($e[0].tagName == 'ION-BADGE') {
      var
        $parent_item = $e.closest('ion-item'),
        $link = $parent_item.find('ion-label h2 a'),
        $items = $e.closest('p.actions'),
        item_feed_name = $parent_item.find('.feed_name_badge small').text();

      // labels training opened from the training slides UI
      if (!$parent_item.length) {
        $parent_item = $e.closest('ion-text');
        $link = $parent_item.find('h2 a');
      }

      select_html[0] += 'data-id="' + $parent_item.data('id') + '"';
      interfaceOptions.header = window.lang.assign_labels + ' (' + $link.text().replace(' »', '') + ')'
    } else {
      select_html[0] += 'data-id="multiple"';
      interfaceOptions.header = window.lang.assign_labels + ' (' + ((feedit.countSelectedItemsCachedObjects() == 1) ? feedit.getFirstSelectedItemCachedObject().title : feedit.countSelectedItemsCachedObjects() + ' ' + window.lang.articles) + ')';
    }

    // close the ion-select tag
    select_html[0] += '>';

    // set all labels present in either our selection of this single link to true
    if (Object.keys(feedit.getSelectedItemsCache()).length) {
      for (var item_key of Object.keys(feedit.getSelectedItemsCache())) {
        for (var item_label of feedit.getSelectedItemsCache()[item_key].labels) {
          if (selected_options.indexOf(item_label.id) == -1) {
            selected_options.push(item_label.id);
          }
        }
      }
    } else {
      $items.find('ion-badge.label_badge').each(function() {
        selected_options.push($(this).data('id'));
      });
    }

    for (var i in labels_cached) {
      // only add labels for the feed of the item we're currently adjusting labels for
      // if we're showing Bookmarks or Everything
      if (current_feed_id == 'bookmarks' || current_feed_id == 'all') {
        if (labels_cached[i].indexOf('(' + item_feed_name + ')') == -1) {
          continue;
        }
      }

      select_html.push('<ion-select-option class="label_option" value="' + i + '">' + labels_cached[i] + '</ion-select-option>');
    }

    select_html.push('</ion-select>');
    $('body').append(select_html.join(''));

    // asynchronous call to let the browser create this select menu before clicking on it
    // as well as to let it set up the default selection
    setTimeout(function() {
      var select_element = $('#labelsTrainer')[0];
      select_element.value = selected_options;
      select_element.interfaceOptions = interfaceOptions;
      select_element.open().then(function() {
        $doc.trigger('hide-loading');
      });
    }, 100);
  };

  // generates HTML for new label, including any existing data that's passed to the function
  function getLabelHTML(data) {
    // just to make sure
    if (typeof(data) == 'undefined') {
      data = false;
    }

    return `
            <ion-icon ` + (data ? `data-id="${data.id}" ` : '') + `class="ion-float-right label_remove" name="close-sharp" color="danger" size="large"></ion-icon>
            <ion-label>
              <ion-input ` + (data ? `data-id="${data.id}" data-old-value="${data.name}" value="${data.name}" ` : '') + `class="label_name` + (!data ? ' newly_added' : '') + `"></ion-input>
            </ion-label>`;
  }

  function initModule() {

    // prepare the labels manager template
    customElements.define('modal-labels-manage', class extends HTMLElement {
      connectedCallback() {
        var html = [];
        html.push(`
  <ion-header>
    <ion-toolbar>
      <ion-title>${window.lang.manage_labels}</ion-title>
      <ion-buttons slot="primary">
        <ion-button onClick="feedit.showHelp($(this).find('ion-icon')[0], 'manage-labels')">
          <ion-icon slot="icon-only" name="information-circle-outline" title="${window.lang.manage_labels_help}"></ion-icon>
        </ion-button>
        <ion-button onClick="feedit.dismissModal()">
          <ion-icon slot="icon-only" name="close"></ion-icon>
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content class="ion-padding">
    <ion-label><strong>${window.lang.labels_for_feed}:</strong></ion-label>
    <ion-select id="labels_feed" placeholder="${window.lang.select_feed}" cancel-text="${window.lang.cancel}" ok-text="${window.lang.confirm}">`);

        $('.feed_item').each(function() {
          var $e = $(this);
          html.push('<ion-select-option value="' + $e.data('id') + '">' + $e.find('h3 span').html() + '</ion-select-option>');
        });

        html.push(`
    </ion-select>

    <br>
    <ion-spinner id="labels_loading" name="lines" class="ion-hide"></ion-spinner>

    <div id="labels_management_container" class="ion-hide">
      <br>
      <ion-label><strong>${window.lang.labels_camelcase}:</strong></ion-label>

      <br>
      <br>
      <div id="labels_list">
        <!-- preload inputs and icons through empty template //-->
        <ion-icon name="close-sharp" color="danger" size="large"></ion-icon>
        <ion-label>
          <ion-input></ion-input>
        </ion-label>
        <!-- preload end //-->
      </div>

      <br>
      <ion-button id="add_new_label" color="success">
        <ion-icon slot="start" name="add-circle"></ion-icon>
        ${window.lang.add_new_label}
      </ion-button>
    </div>

    <br>
    <br>

    <div class="ion-float-right top10px">
      <a href="javascript:feedit.dismissModal()" class="ion-margin">${window.lang.cancel}</a>
      <ion-button color="primary" id="update_labels" disabled>${window.lang.confirm}</ion-button>
    </div>
  </ion-content>
    `);

        this.innerHTML = html.join('');
      }
    });

    // react to change event of the labels feed dropdown in the labels manager
    // and load new set of labels to be shown
    $doc.on('ionChange', '#labels_feed', function(event) {
      // show loading
      $('#labels_management_container').addClass('ion-hide');
      $('#labels_loading').removeClass('ion-hide');

      // disable confirm button - nothing's changed yet
      $('#update_labels').attr('disabled', 'disabled');

      // reset update data
      labels_manager_update_data = {};

      feedit.callAPI(
        ajax_url_fetch_labels,
        {
          'feed' : event.originalEvent.detail.value,
        },
        function( response ) {
          if (typeof(response) == 'object') {
            var new_html = [];

            for (var label of response) {
              new_html.push( getLabelHTML(label) );

              // kept separate, so we can remove the last one
              new_html.push('<br>');
            }

            new_html.pop();
            $('#labels_list').html(new_html.join(''));

            $('#labels_management_container').removeClass('ion-hide');
            $('#labels_loading').addClass('ion-hide');
          } else {
            feedit.defaultErrorNotification();
          }
        }
      );
    });

    // removes the label from label manager view and adds it to the AJAX data to be sent out to the backend
    $doc.on('click', '.label_remove', function() {
      var $e = $(this);

      // add AJAX data, if we're removing an existing label
      if (!$e.next('ion-label').hasClass('newly_added')) {
        if (!labels_manager_update_data['removals']) {
          labels_manager_update_data['removals'] = [];
        }

        labels_manager_update_data['removals'].push($e.data('id'));
      }

      // remove the label from page
      $e.next('ion-label').remove();

      // remove the extra BR
      $e.next('br').remove();

      // remove the button itself
      $e.remove();

      // enable the confirm button
      $('#update_labels').removeAttr('disabled');
    });

    // adds a new label to the label manager view
    $doc.on('click', '#add_new_label', function() {
      $('#labels_list').append( '<br>' + getLabelHTML() );

      // enable the confirm button
      $('#update_labels').removeAttr('disabled');

      // make new label input active
      setTimeout(function() {
        $('.label_name').last()[0].setFocus();
      }, 500);
    });

    // enable the confirm button when a label changes
    $doc.on('ionChange', '.label_name', function() {
      $('#update_labels').removeAttr('disabled');
    });

    // submits all label changes to the backend
    $doc.on('click', '#update_labels', function() {
      // assemble all new labels
      $('.newly_added').each(function() {
        var $e = $(this);

        if ( $e.val() ) {
          if (!labels_manager_update_data['additions']) {
            labels_manager_update_data['additions'] = [];
          }

          labels_manager_update_data['additions'].push( $e.val() );
        }
      });

      // check for changed labels
      $('#labels_list ion-label').each(function() {
        var
          $e = $(this),
          $input = $e.find('ion-input');

        if (!$input.hasClass('newly_added')) {
          if ( $input.data('old-value') != $input.val() ) {
            if (!labels_manager_update_data['changed']) {
              labels_manager_update_data['changed'] = [];
            }

            labels_manager_update_data['changed'].push({
              'id' : $input.data('id'),
              'val' : $input.val()
            });
          }
        }
      });

      // check if we have any data
      // ... it could be that we've edited and then returned label names
      //     to previous state, so nothing has really changed
      if (!Object.keys(labels_manager_update_data).length) {
        // dismiss the modal and return
        feedit.dismissModal();
        return;
      }

      // add feed ID
      labels_manager_update_data['feed'] = $('#labels_feed').val();

      // dismiss the modal
      feedit.dismissModal();

      // show the loading UI, as we're now changing stuff and will need to reload the page afterwards
      $doc.trigger('show-loading', [ window.lang.saving_data ]);

      var page_update_needed = false;

      feedit.callAPI(
        ajax_url_save_labels,
        labels_manager_update_data,
        function( response ) {
          if ( response == '' ) {
            // check if we've just updated labels for current feed,
            // in which case we'll reload the labels dropdown
            var feedId = feedit.getActiveFeedId();
            if (feedId == labels_manager_update_data['feed'] || feedId == 'bookmarks' || feedId == 'all') {
              feedit.cache_labels( feedit.getActiveFeedId() );
            }

            // remove all labels on page that were removed from the DB
            if (typeof(labels_manager_update_data['removals']) != 'undefined') {
              var ids = [];

              for (var id of labels_manager_update_data['removals']) {
                ids.push('.label_badge[data-id="' + id + '"], #filter_labels ion-select-option[value="' + id + '"]');

                // if this label is currently selected in the dropdown,
                // deselect our labels drop value
                if ($filter_labels.val() == id) {
                  $filter_labels.val('');
                  page_update_needed = true;
                }
              }

              $(ids.join(',')).remove();

              // update the page, if we've deselected a removed label from the labels dropdown
              if (page_update_needed) {
                $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
              }
            }

            // update label of all labels that's been renamed
            if (!page_update_needed && typeof (labels_manager_update_data['changed']) != 'undefined') {
              for (var label of labels_manager_update_data['changed']) {
                $('.label_badge[data-id="' + label.id + '"] small').text(label.val);
              }
            }

            feedit.cache_labels();
          } else {
            // something's gone wrong if the response is not empty
            feedit.defaultErrorNotification();
          }
        },
        null,
        function() {
          if (!page_update_needed) {
            // hide loader
            $doc.trigger('hide-loading');
          }
        }
      );
    });

    // initial caching of labels from labels dropdown on page
    // ... on a timeout because we can't call this before it's created below
    //     in this model's $.extend part
    setTimeout(feedit.cache_labels, 150);

    // labels dropdown in main content update
    $doc.on('ionAlertWillDismiss', function() {
      // check that labels didn't change
      var current_value = $filter_labels.val();

      if (current_value && typeof(current_value) == 'string') {
        current_value = [ current_value ];
      }

      if (current_value && current_value.join(',') != labels_selected) {
        // labels dropdown changed, fire event to refresh content
        $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
        labels_selected = current_value.join(',');
        Cookies.set('filter_labels', labels_selected, { expires: 365 });
      }
    });

    // label filter change requested via label badge click event
    $doc.on('click', 'ion-label ion-badge.label_badge', function() {
      // don't change labels on prediction clicks, as that will instead make the prediction stable
      if (this.className.indexOf('prediction') > -1 || this.className.indexOf('longpressed') > -1) {
        return true;
      }

      $filter_labels.val( $(this).data('id') );

      var current_value = $filter_labels.val();

      if (current_value && typeof(current_value) == 'string') {
        current_value = [ current_value ];
      }

      labels_selected = current_value;
      $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
    });

    // click on the Assign Labels tab button for multiple selected items
    // and on the link's Labels badge on page
    $doc.on('click', '#multi_labels, .labels', function( event ) {
      // do nothing if we're shift-clicking on the labels
      // or if a long-press event was just finished on the element
      if (
        (this.id == 'multi_labels' && event.shiftKey) ||
        this.className.indexOf('longpressed') > -1
      ) {
        return;
      }

      showLabelsTrainer( $(this) );
    });

    // labels dropdownn changed, training of an item (or items) is in order
    $doc.on('ionChange', '#labelsTrainer', function(event) {
      var
        $e = $(this),
        links = [];

      // don't act if the dropdown was not initialized yet,
      // as that init will fire ionChange which we want to ignore
      if ($e.data('initialized')) {
        if ($e.data('id') != 'multiple') {
          links.push($e.data('id'));
        } else {
          // assemble IDs of all selected items
          links = Object.keys( feedit.getSelectedItemsCache() );
        }

        feedit.callAPI(
          ajax_url_give_labels,
          {
            'links' : links,
            'labels' : (event.originalEvent.detail.value.length ? event.originalEvent.detail.value : 'empty'),
          },
          function( response ) {
            if (response == '') {
              // cache selected label names
              var
                new_labels = [],
                highlightCacheKeys = Object.keys(feedit.getSelectedItemsCache());

              for (var label_id of event.originalEvent.detail.value) {
                var label_text = $('ion-select-option[value="' + label_id + '"]').first().text();
                new_labels.push({
                  'id' : label_id,
                  'label'  : label_text,
                  'txt' : label_text, // duplication due to different object structure in highlight cache data
                });
              }

              // remove previous labels from these items and add the ones we've assignd to them now
              for (var link of links) {
                var
                  $link = $('#main ion-item-sliding ion-item[data-id="' + link + '"]'),
                  $link_training = $('#training_slider ion-slide[data-id="' + link + '"]'),
                  $bookmark_badge = $link.find('.bookmark'),
                  $labels_badge_training = $link_training.find('p.actions .labels').first();

                $link.add($link_training).find('.label_badge').remove();
                $bookmark_badge.add($labels_badge_training).after( feedit.getLabelsHTML( new_labels ) );

                // update cached selection labels
                if (highlightCacheKeys.length) {
                  for (var item_key of highlightCacheKeys) {
                    if (link == item_key) {
                      feedit.getSelectedItemsCache()[item_key].labels = new_labels;
                    }
                  }
                }
              }

              feedit.presentToast({ 'txt' : window.lang.labels_updated });
            } else {
              feedit.defaultErrorNotification();
            }
          },
        );
      } else {
        $e.data('initialized', 1);
      }
    });

    // label prediction clicked, make it permanent
    $doc.on('click', '.prediction', function(event) {
      var $e = $(this);

      if ($e.hasClass('longpressed')) {
        return true;
      }

      // remove asterisk from the label name
      $e.find('small').text( $e.find('small').text().slice(0, -1) );

      feedit.callAPI(
        ajax_url_give_labels,
        {
          'links' : [ $e.closest('ion-item').data('id') ],
          'labels' : [ $e.data('id') ],
        },
        function( response ) {
          if (response == '') {
            // remove prediction class
            $e.removeClass('prediction');
          } else {
            // restore asterisk for the label
            $e.find('small').text( $e.find('small').text() + '*' );

            feedit.defaultErrorNotification();
          }
        },
      );
    });

    // shift+click on the multiple labels tab button in footer
    $doc.on('click', '#multi_labels', function( event ) {
      // bail out if we're not holding down the SHIFT key or we just came
      // from a long-press event
      if (!event.shiftKey || this.className.indexOf('longpressed') > -1) {
        $(this).removeClass('longpressed');
        return;
      }

      stabilizePredictedLabels();
    });

    // long-press on labels tab button makes predicted labels permanent
    $doc.on('longpress', '#multi_labels', function () {
      // add a longpressed class, so the click event won't trigger this again
      var $e = $(this);
      $e.addClass('longpressed');

      stabilizePredictedLabels();
    });

    // long-press on a label badge to select all items on page with that label
    $doc.on('longpress', '#main .label_badge', function () {
      // highlight all items with the same badge
      var $e = $(this);

      $e.addClass('longpressed');

      $('#main .label_badge[data-id="' + $e.data('id') + '"]').each(function() {
        var
          $e = $(this),
          $item_sliding = $e.closest('ion-item-sliding');

        if (!$item_sliding.hasClass('ion-hide') && !$item_sliding.hasClass('child_highlighted')) {
          feedit.highlightItem( $item_sliding );
        }
      });

      // remove this class on timeout, as there are multiple potential click handlers that would remove it
      // and allow each the other one to do what they shouldn't do when this class is present
      setTimeout(function() {
        $e.removeClass('longpressed');
      }, 1000);

      // don't bubble, so we don't highlight/deselect this item by long-pressing on it
      return false;
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      // shows modal where user can manage labels
      showLabelsManager() {
        if (labels_manager_opening) {
          return;
        }

        labels_manager_opening = true;
        $doc.trigger('show-loading');
        presentLabelsManagerModal(function() {
          // select the active feed from the feeds dropdown
          setTimeout(function() {
            var active_id = feedit.getActiveFeedItem().data('id');
            if (active_id != 'bookmarks' && active_id != 'all') {
              $('#labels_feed').val( feedit.getActiveFeedItem().data('id') );
            }
          }, 500);
        });
      },

      // caches labels for current feed
      cache_labels( feed_id ) {
        // load new labels, if requested
        if (typeof(feed_id) != 'undefined') {
          feedit.callAPI(
            ajax_url_fetch_labels,
            {
              'feed' : feed_id,
            },
            function( response ) {
              if (typeof(response) == 'object') {
                // remove all old labels and create new ones
                $filter_labels.find('ion-select-option').remove();
                var
                  html = [],
                  oldValue = $filter_labels.val(),
                  have_options = false,
                  current_feed_id = feedit.getActiveFeedId();

                for (var label of response) {
                  have_options = true;
                  html.push(`
                  <ion-select-option value="${label.id}">${label.name}</ion-select-option>`);
                }

                if (have_options) {
                  $filter_labels.append(html.join(''));
                }

                // give the UI time to populate
                setTimeout(function() {
                  if (have_options) {
                    $filter_labels.val(oldValue);
                  }

                  cache_labels();

                  // if there's at least a single label present and we're not in Bookmarks or Everything,
                  // un-hide labels dropdown as well as all labels badges on page
                  if ( have_options ) {
                    $('#active_feed_title_header, #labels-select-badge').removeClass('ion-hide no-labels simple-mode-ignore');

                    // only show multi-labels tab button if we're not showing Bookmarks or Everything
                    if (feedit.getActiveFeedItem().hasClass('feed_item')) {
                      $('#multi_labels').removeClass('ion-hide no-labels simple-mode-ignore');
                    } else {
                      $('#multi_labels').addClass('ion-hide');
                    }

                    // only show labels badge if we're not in simple mode
                    if (!feedit.isSimpleMode()) {
                      var classes_to_remove = 'ion-hide';

                      // only remove simple-mode-ignore class if we're not in Bookmarks or Everything
                      if (current_feed_id != 'bookmarks' && current_feed_id != 'all') {
                        classes_to_remove += ' simple-mode-ignore';
                      }

                      $('ion-badge.labels').removeClass(classes_to_remove);
                    } else if (current_feed_id != 'bookmarks' && current_feed_id != 'all') {
                      // only remove the simple-mode-ignore class, so badges don't remain hidden upon leaving Simple Mode
                      $('ion-badge.labels').removeClass('simple-mode-ignore');
                    }
                  } else {
                    // on the contrary, if we don't have any labels (left), hide dropdown and label badges
                    $('#labels-select-badge, ion-badge.labels, #multi_labels').addClass('ion-hide');
                    $('#active_feed_title_header').addClass('no-labels');
                    $('ion-badge.labels').addClass('simple-mode-ignore');
                  }
                }, 100);
              } else {
                feedit.defaultErrorNotification();
              }
            }
          );
        } else {
          cache_labels();
        }

      },
    });

  };
})(jQuery);