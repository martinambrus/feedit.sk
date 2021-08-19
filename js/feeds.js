(function($) {
  var
    $doc = $(document),
    add_feed_opening = false,
    ajax_url_feeds = 'api/feeds.php',
    ajax_url_feeds_discovery = 'api/feeds_discovery.php',
    ajax_url_feeds_counts = 'api/feeds_counts.php',
    ajax_url_feed_remove = 'api/feed_remove.php',
    ajax_url_feed_add = 'api/feed_add.php',
    ajax_url_feed_edit = 'api/feed_edit.php',
    ajax_url_languages = 'api/languages.php',
    ajax_in_progress = false,
    lang_manually_selected = false,
    new_feed_discovery_changed_timer = -1,
    previous_feed_active_id = null,
    $feeds_sort,
    langs_cached = {},
    ignore_next_feed_item_click = false; // will become true when we need to programatically click an item
                                         // to make it active, while feeds removal toggle is on, as to prevent
                                         // that newly activated item to be removed as well

  $doc.on('app-init', function() {
    $feeds_sort = $('#feeds_sort');
    initModule();
  });

  // opens the Add Feed modal
  async function presentAddFeedModal( langs_data ) {
    lang_manually_selected = false;

    // create the modal with the `modal-page` component
    $doc.trigger('open-modal', [
      await modalController.create({
        component: 'modal-add-feed',
        componentProps: {
          'langs' : langs_data
        },
      }),
      function() {
        setTimeout(function() {
          $('#feed_url')[0].setFocus();
        }, 500);
      }
    ]);
  };

  // opens the Edit Feed modal
  async function presentEditFeedModal( $item, langs_data ) {
    // create the modal with the `modal-page` component
    $doc.trigger('open-modal', [
      await modalController.create({
        component: 'modal-update-feed',
        componentProps: {
          'langs' : langs_data,
          'lang'  : $item.data('lang'),
          'id'    : $item.data('id'),
          'url'   : $item.data('url'),
          'title' : $item.find('ion-label h3 span').text(),
          'allow_duplicates' : $item.data('allow-duplicates'),
        },
      }),
      function() {
        setTimeout(function() {
          $('#feed_title')[0].setFocus();
        }, 500);
      }
    ]);
  };

  // checks whether all the required data is filled
  // and at least a single feed source is selected
  function checkRequiredData() {
    var
      title_check = true,
      url_check   = true,
      updating    = $('#feed_title').length;

    // title is only checked when the feed it updated
    if ($('#feed_title').length) {
      title_check = ($('#feed_title').val() != '');
    } else {
      url_check = ($('#feed_url').val() != '');
    }

    if (!url_check || !$('#feed_lang').val() || !title_check) {
      $('#' + (updating ? 'edit_feed' : 'add_selected_feeds')).attr('disabled', 'disabled');
    } else {
      $('#' + (updating ? 'edit_feed' : 'add_selected_feeds')).removeAttr('disabled');
    }
  };

  function initModule() {

    // wait a second for all modules to load, then cache current feed ID
    setTimeout(function() {
      previous_feed_active_id = feedit.getActiveFeedId();
    }, 1000);

    // set a timer to refresh links count on left menu badges every 5 minutes
    setInterval(function() {
      $doc.trigger('refresh-link-counts', [ { 'what' : 'all'} ]);
    }, 300000);

    // prepare the update feed template
    customElements.define('modal-update-feed', class extends HTMLElement {
      connectedCallback() {
        var html = [];
        html.push(`
  <ion-header>
    <ion-toolbar>
      <ion-title>${window.lang.edit_feed}</ion-title>
      <ion-buttons slot="primary">
        <ion-button onClick="feedit.showHelp($(this).find('ion-icon')[0], 'edit-feed')">
          <ion-icon slot="icon-only" name="information-circle-outline" title="${window.lang.how_to_edit_a_feed}"></ion-icon>
        </ion-button>
        <ion-button onClick="feedit.dismissModal()">
          <ion-icon slot="icon-only" name="close"></ion-icon>
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content class="ion-padding">
    <ion-label><strong>${window.lang.feeds_url}</strong>:</ion-label>
    <ion-text class="bottom10px">` + feedit.getTopModal().componentProps.url + `</ion-text>

    <br><br><br>
  
    <ion-label><strong>${window.lang.feed_title}</strong> <small>(${window.lang.required})</small>:</ion-label>
    <ion-input id="feed_title" value="` + feedit.getTopModal().componentProps.title + `" class="bottom10px"></ion-input>

    <br>
    <ion-label><strong>${window.lang.feed_lang}</strong> <small>(${window.lang.required})</small>:</ion-label>
    <ion-select id="feed_lang" value="` + feedit.getTopModal().componentProps.lang + `" cancel-text="${window.lang.cancel}" ok-text="${window.lang.confirm}">`);

        for (var i in feedit.getTopModal().componentProps.langs) {
          var lang = feedit.getTopModal().componentProps.langs[ i ];
          html.push('<ion-select-option value="' + lang.code + '">' + lang.name_en + (lang.name ? ' (' + lang.name + ')' : '') + '</ion-select-option>');
        }

        html.push(`
    </ion-select>

    <br>
    <ion-item class="ion-no-padding">
      <ion-icon slot="start" name="information-circle-outline" class="ion-no-margin right10px" title="${window.lang.what_is_allow_duplicate_articles}?" onclick="feedit.showHelp(this, 'allow-duplicates')"></ion-icon>
      <ion-checkbox slot="end" id="allow_duplicates"` + (feedit.getTopModal().componentProps.allow_duplicates ? ' checked="true"' : '') + `></ion-checkbox>
      <ion-label><strong>${window.lang.allow_duplicate_articles}</strong></ion-label>
    </ion-item>

    <br>

    <div class="ion-float-right top10px">
      <a href="javascript:feedit.dismissModal()" class="ion-margin">${window.lang.cancel}</a>
      <ion-button color="primary" id="edit_feed" data-feed-id="` + feedit.getTopModal().componentProps.id + `" disabled>${window.lang.confirm}</ion-button>
    </div>
  </ion-content>
    `);

        this.innerHTML = html.join('');
      }
    });

    // prepare the add feed template
    customElements.define('modal-add-feed', class extends HTMLElement {
      connectedCallback() {
        var html = [];
        html.push(`
  <ion-header>
    <ion-toolbar>
      <ion-title>${window.lang.add_new_feed}</ion-title>
      <ion-buttons slot="primary">
        <ion-button onClick="feedit.showHelp($(this).find('ion-icon')[0], 'add-feed')">
          <ion-icon slot="icon-only" name="information-circle-outline" title="${window.lang.how_to_add_a_feed}"></ion-icon>
        </ion-button>
        <ion-button onClick="feedit.dismissModal()">
          <ion-icon slot="icon-only" name="close"></ion-icon>
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content class="ion-padding">
    <ion-label><strong>${window.lang.feeds_url}</strong> <small>(${window.lang.required})</small>:</ion-label>
    <ion-input id="feed_url" placeholder="https://" class="bottom10px"></ion-input>

    <br>
    <ion-label><strong>${window.lang.feed_lang}</strong> <small>(${window.lang.required})</small>:</ion-label>
    <ion-select id="feed_lang" placeholder="${window.lang.select_feed_lang}" cancel-text="${window.lang.cancel}" ok-text="${window.lang.confirm}">`);

        for (var i in feedit.getTopModal().componentProps.langs) {
          var lang = feedit.getTopModal().componentProps.langs[ i ];
          html.push('<ion-select-option value="' + lang.code + '">' + lang.name_en + (lang.name ? ' (' + lang.name + ')' : '') + '</ion-select-option>');
        }

          html.push(`
    </ion-select>

    <br>
    <ion-item class="ion-no-padding">
      <ion-icon slot="start" name="information-circle-outline" class="ion-no-margin right10px" title="${window.lang.what_is_allow_duplicate_articles}?" onclick="feedit.showHelp(this, 'allow-duplicates')"></ion-icon>
      <ion-checkbox slot="end" id="allow_duplicates"></ion-checkbox>
      <ion-label><strong>${window.lang.allow_duplicate_articles}</strong></ion-label>
    </ion-item>

    <br>
    <ion-item class="ion-no-padding">
      <ion-icon slot="start" name="information-circle-outline" class="ion-no-margin right10px" title="${window.lang.what_is_manual_prioritization}?" onclick="feedit.showHelp(this, 'manual-priorities')"></ion-icon>
      <ion-checkbox slot="end" id="use_manual_priorities"></ion-checkbox>
      <ion-label><strong>${window.lang.use_manual_priority}</strong></ion-label>
    </ion-item>

    <br>
    <ion-reorder-group id="manual_reorder_group" class="ion-hide">
      <ion-item>
        <ion-label>${window.lang.words}</ion-label>
        <ion-reorder slot="end"></ion-reorder>
      </ion-item>
      <ion-item>
        <ion-label>${window.lang.numbers}</ion-label>
        <ion-reorder slot="end"></ion-reorder>
      </ion-item>
      <ion-item>
        <ion-label>${window.lang.measurement_units}</ion-label>
        <ion-reorder slot="end"></ion-reorder>
      </ion-item>
    </ion-reorder-group>

    <br>
    <br>
    <ion-list id="feeds_found">
      <ion-list-header>
        <h4>${window.lang.feeds_found}: <ion-spinner name="lines-small" class="ion-hide"></ion-spinner></h4>
      </ion-list-header>
    </ion-list>

    <br>

    <div class="ion-float-right top10px">
      <a href="javascript:feedit.dismissModal()" class="ion-margin">${window.lang.cancel}</a>
      <ion-button color="primary" id="add_selected_feeds" disabled>${window.lang.confirm}</ion-button>
    </div>
  </ion-content>
    `);

        this.innerHTML = html.join('');
      }
    });

    // as per Ionic docs, we need to implement this for items reorder to work
    $doc.on('ionItemReorder', function(event) {
      event.originalEvent.detail.complete();
    });

    // handle clicking on enable/disable manual priorities checkbox
    $doc.on('ionChange', '#use_manual_priorities', function(event) {
      $('#manual_reorder_group')
        .toggleClass('ion-hide')
        .get(0)
        .disabled = !event.originalEvent.detail.checked;
    });

    // check our data on each input change
    $doc.on('ionChange', checkRequiredData);

    // proxy click catchers for small buttons on top of feeds to activate real action items below them
    $doc.on('click', '.feed_actions_grid ion-col', function() {
      // click on the appropriate hidden item
      $('.feeds .' + this.id.replace('_btn', '_link') ).click();
    });

    // feed change or feed removal
    $doc.on('click', '.feeds ion-item', function( event ) {
      var $e = $(this);

      // don't react on divider click
      if ($e.hasClass('divider')) {
        return true;
      }

      // bail out if we've right-clicked, as that action presents the popover menu
      if ( event.which == 3 ) {
        return true;
      }

      // we asked to add a new feed
      if ($e.hasClass('add_new_feed_link')) {
        if (add_feed_opening) {
          return true;
        }

        add_feed_opening = true;
        $doc.trigger('show-loading');

        // if we have language data cached already, pass it directly
        if (Object.keys(langs_cached).length) {
          presentAddFeedModal( langs_cached );
          add_feed_opening = false;
        } else {
          feedit.callAPI(
            ajax_url_languages,
            null,
            function( response ) {
              if (typeof(response) == 'object') {
                langs_cached = response;
                presentAddFeedModal( response );
              } else {
                feedit.defaultErrorNotification();
              }
            },
            null,
            function() {
              // hide loader
              $doc.trigger('hide-loading');

              // make sure we can call this again
              add_feed_opening = false;
            }
          );
        }

        return true;
      }

      // if we've asked to reload the feeds menu, trigger the appropriate event and bail out
      if ($e.hasClass('reload_feeds_link')) {
        $doc.trigger('refresh-left-menu-feeds', [ null, {'no-content-update' : 1} ]);
        return true;
      }

      // we've asked to show/hide images
      if ($e.hasClass('show_images_link')) {
        var
          show_hide = 1,
          current_feed_id = feedit.getActiveFeedItem().data('id');

        if ( ( typeof(Cookies.get('show-images-' + current_feed_id)) != 'undefined' ) ) {
          show_hide = (Cookies.get('show-images-' + current_feed_id) == 0 ? 1 : 0);
          Cookies.set('show-images-' + current_feed_id, show_hide, { expires: 365 });
        } else if (( typeof(Cookies.get('show-images')) != 'undefined' )) {
          show_hide = (Cookies.get('show-images') == 0 ? 1 : 0);
          Cookies.set('show-images', show_hide, { expires: 365 });
          Cookies.set('show-images-' + current_feed_id, show_hide, { expires: 365 });
        } else {
          show_hide = 0;
          Cookies.set('show-images', 0, { expires: 365 });
          Cookies.set('show-images-' + current_feed_id, 0, { expires: 365 });
        }

        // show/hide images as requested
        if (show_hide) {
          // restore all images
          $('#main .link_image img[data-hidden-img]').each(function() {
            var $e = $(this);

            $e
              .attr('src', $e.attr('data-hidden-img'))
              .removeAttr('data-hidden-img');
          });

          // show all avatars
          $('#main ion-item ion-avatar').removeClass('ion-hide');

          // make the icon green
          $('#show_images_icon').attr('color', 'success');
        } else {
          // hide all images
          $('#main .link_image img').each(function() {
            var $e = $(this);

            $e
              .attr({
                'data-hidden-img' : $e.attr('src'),
                'src' : ((current_feed_id != 'all' && current_feed_id != 'bookmarks') ? feedit.getActiveFeedItem().find('ion-label h3 img').attr('src') : 'img/logo114.png'), // replace by feed's favicon
              });
          });

          // hide all avatars if we're in simple mode
          if (feedit.isSimpleMode()) {
            $('#main ion-item ion-avatar').addClass('ion-hide');
          }

          // make the icon black
          $('#show_images_icon').attr('color', 'dark');
        }

        return true;
      }

      // bail out for a feed removal or feed sorting item click
      if ($e.hasClass('remove_feed_item') || $e.hasClass('feeds_sort_select')) {
        return true;
      }

      // check if we're not about to remove this feed and that it's not a favorites link
      var $icon;
      if (
        ($icon = $e.find('ion-icon[name="trash-bin"]')) &&
        $icon.length &&
        !$icon.hasClass('ion-hide') &&
        !ignore_next_feed_item_click
      ) {
        feedit.presentRemoveFeedDialog( $e );
        return true;
      } else {
        if ($icon.attr('name') == 'star') {
          // favorites cannot be removed
          feedit.presentToast({ 'txt' : window.lang.bookmarks_cannot_be_removed });
        }
        ignore_next_feed_item_click = false;
      }

      if (!$e.hasClass('longpressed')) {
        // don't re-select the same feed
        if (!$e.hasClass('active')) {
          $('.feeds ion-item').removeClass('active');
          $e.addClass('active');

          // store last active feed
          Cookies.set('feed-active', $e.data('id'), { expires: 365 });
        }

        // update the main title header and hide the cleanup icon
        feedit.refreshMainTitle();
        $('#feed_cleanup_icon').addClass('ion-hide');
        $e.find('ion-spinner').removeClass('ion-hide');
        $('#feed_load_main_title_indicator').remove();

        // check if we have a filter cookies saved for this feed
        if (Cookies.get('filter_sort-' + $e.data('id')) && $('#filter_sort').val() != Cookies.get('filter_sort-' + $e.data('id'))) {
          $('#filter_sort').addClass('no_content_change').val( Cookies.get('filter_sort-' + $e.data('id')) );
        }

        if (Cookies.get('filter_status-' + $e.data('id')) && $('#filter_status').val() != Cookies.get('filter_status-' + $e.data('id'))) {
          $('#filter_status').addClass('no_content_change').val( Cookies.get('filter_status-' + $e.data('id')) );
        }

        if (Cookies.get('filter_hiding-' + $e.data('id')) && $('#filter_hiding').val() != Cookies.get('filter_hiding-' + $e.data('id'))) {
          $('#filter_hiding').addClass('no_content_change').val( Cookies.get('filter_hiding-' + $e.data('id')) );
        }

        if (Cookies.get('filter_per_page-' + $e.data('id')) && $('#filter_per_page').val() != Cookies.get('filter_per_page-' + $e.data('id'))) {
          $('#filter_per_page').addClass('no_content_change').val( Cookies.get('filter_per_page-' + $e.data('id')) );
        }

        // remove all selected items from old feed
        feedit.resetSelectedItemsCache();

        // update the Select All checkbox state
        $doc.trigger('item-select', [{ 'origin' : 'content_refresh' }]);

        // make sure we don't carry current labels to the next feed
        // and also remove them from our cookie, if we have them there
        if ( previous_feed_active_id != feedit.getActiveFeedId() ) {
          $('#filter_labels').val('');
          Cookies.remove('filter_labels',);
          previous_feed_active_id = feedit.getActiveFeedId();
        }

        // update the "show images" icon
        feedit.updateShowImagesIcon();

        $doc.trigger('refresh-content', [
          null,
          null,
          null,
          null,
          {
            'no-loading': true
          },
        ]);
      } else {
        // long-press event was registered before our click,
        // unmark the class as longpressed and return
        $e.removeClass('longpressed');
      }
    });

    // add feeds handler - called when we click the Confirm button on add feeds modal
    $doc.on('click', '#add_selected_feeds', function() {
      var data = {
        'feeds' : $('#feeds_found ion-checkbox.checkbox-checked').map(function (index, item) {
          return {
            'title' : $(this).prev('ion-label').find('strong').text(),
            'url' : item['value'],
          };
        }).toArray(),
        'feed_lang' : $('#feed_lang').val(),
        'feed_url' : $('#feed_url').val(),
        'allow_duplicates' : $('#allow_duplicates')[0].checked,
        'manual_priorities' : $('#use_manual_priorities')[0].checked,
      };

      // collect manual priorities for sending to the backend
      if (data.manual_priorities) {
        data.priorities = $('#manual_reorder_group ion-label').map(function () {
          return $(this).html();
        }).toArray();
      }

      // call the API to add new feed(s)
      $doc.trigger('show-loading');

      feedit.callAPI(
        ajax_url_feed_add,
        data,
        function( response ) {
          if (typeof(response) == 'object') {
            // fire up event which will add these new feeds to the left menu
            $doc.trigger('left-menu-feeds-add', [ response ]);
            feedit.dismissModal();
          } else {
            feedit.defaultErrorNotification();
          }
        },
        function() {
          // in case there was an error, the feed might have still been added,
          // so we'll update the left menu with feeds, just in case
          $doc.trigger('refresh-left-menu-feeds');
        },
        function() {
          $doc.trigger('hide-loading');
        }
      );
    });

    // edit feed handler - called when we click the Confirm button on add feeds modal
    $doc.on('click', '#edit_feed', function() {
      var
        $e = $(this),
        data = {
        'feed' : $e.data('feed-id'),
        'feed_lang' : $('#feed_lang').val(),
        'feed_title' : $('#feed_title').val(),
        'allow_duplicates' : $('#allow_duplicates')[0].checked,
      };

      // call the API to edit the feed
      $doc.trigger('show-loading');

      feedit.callAPI(
        ajax_url_feed_edit,
        data,
        function( response ) {
          if (response == '') {
            // update left menu item's data and title
            $('.feeds ion-item[data-id="' + data['feed'] + '"]')
              .data({
                'lang' : data['feed_lang'],
                'allow-duplicates' : $('#allow_duplicates')[0].checked ? 1 : 0,
              })
              .attr({
                'data-lang' : data['feed_lang'],
                'data-allow-duplicates' : $('#allow_duplicates')[0].checked ? 1 : 0,
                'title' : data['feed_title']
              })
              .find('ion-label h3 span')
              .text( data['feed_title'] );

            // if this feed was active, also update the main title
            if ( feedit.getActiveFeedId() == $e.data('feed-id') ) {
              feedit.refreshMainTitle();
            }

            feedit.dismissModal();
            feedit.presentToast({ 'txt' : window.lang.feed_updated, 'duration' : 1000, });
          } else {
            feedit.defaultErrorNotification();
          }
        },
        null,
        function() {
          $doc.trigger('hide-loading');
        }
      );
    });

    // feeds removal toggle - showing and hiding trash icons next to feeds
    $doc.on('click', '#remove_feeds', function() {
      // show/hide our icons
      $(this).closest('ion-list').find('ion-icon[name="trash-bin"], .links_counter').toggleClass('ion-hide');
    });

    // loads new counts for left menu items and refreshes those counters on page
    $doc.on('refresh-link-counts', function(event, data, successCallback) {
      $.extend( data, feedit.getBasePaginationData());

      // call the API to refresh links counter
      feedit.callAPI(
        ajax_url_feeds_counts,
        data,
        function( response ) {
          if (typeof(response) == 'object') {
            // update all feeds' counter badges with their appropriate values
            $doc.trigger('update-feeds-counts', [ response ]);

            // if the "No Content" page is showing and our current feed's badge has a number above 0
            // and there are no labels used and we're not looking at bookmarks item, reload content
            if (
              !$('#no_articles').hasClass('ion-hide') && // No Conten page showing
              feedit.getActiveFeedItem().find('.links_counter ion-badge').text() != '0' && // we've got new articles
              feedit.getActiveFeedItem().data('id') != 'bookmarks' && // we don't have the bookmarks feed active
              !$('#filter_labels').val().length // we're not using labels
            ) {
              $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
            }

            if (typeof(successCallback) == 'function') {
              successCallback();
            }
          }
        },
        false
      );
    });

    // completely replaces left menu items with data from the API
    // ... used during the initial page load and when we're reloading the whole menu
    $doc.on('refresh-left-menu-feeds', function( event, successCallback, options ) {
      if (ajax_in_progress) {
        return;
      }

      $doc.trigger('show-loading');
      ajax_in_progress = true;

      var data = feedit.getBasePaginationData();
      data['feeds_sort'] = $feeds_sort.val();

      feedit.callAPI(
        ajax_url_feeds,
        data,
        function( response ) {
          if (typeof(response) == 'object') {
            // update the bookmarks counter
            if (typeof(response.bookmarks_count) != 'undefined') {
              $('.bookmarks_item .links_counter ion-badge').text( response.bookmarks_count );
              delete response.bookmarks_count;
            }

            // update the "all feeds" counter
            if (typeof(response.all_count) != 'undefined') {
              $('.all_feeds_item .links_counter ion-badge').text( response.all_count );
              delete response.all_count;
            }

            // show a low negative rating warning
            if (typeof(response.low_negatives_warning) != 'undefined') {
              feedit.presentToast({ 'txt' : window.lang.negative_training_warning, 'duration' : 30000, });
              Cookies.set( 'training_check_warning_displayed', 1, { expires: 365 });
              delete response.low_negatives_warning;
            }

            $doc.trigger('left-menu-feeds-update', [ response, successCallback ]);
          } else {
            feedit.defaultErrorNotification();
          }
        },
        null,
        function() {
          ajax_in_progress = false;

          // now load main content - if not requested otherwise
          if (typeof(options) == 'undefined' || !options['no-content-update'] || options['no-content-update'] != 1) {
            $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
          } else {
            // hide loader
            $doc.trigger('hide-loading');
          }
        }
      );
    });

    // react to a change in feeds ordering
    $doc.on('ionChange', '#feeds_sort', function() {
      Cookies.set( 'feeds_sort', $feeds_sort.val(), { expires: 365 } );
      $doc.trigger('refresh-left-menu-feeds');
    });

    // react to a change in language field
    $doc.on('ionChange', '#feed_lang', function() {
      // this will be immediately changed to false when changing the language
      // from our discovery AJAX call but will remain true if a user changed the language
      lang_manually_selected = true;
    });

    // react to a change in new feed URL field
    $doc.on('ionChange', '#feed_url', function(event) {
      // only react if we're not updating the feed
      if ($('#feed_title').length) {
        return;
      }

      if (new_feed_discovery_changed_timer > -1) {
        clearTimeout(new_feed_discovery_changed_timer);
      }

      new_feed_discovery_changed_timer = setTimeout(function() {
        if (ajax_in_progress !== false) {
          ajax_in_progress.abort();
          ajax_in_progress = false;
        }

        // remove any old items discovered
        $('#feeds_found ion-item').remove();

        // show loading indicator
        $('#feeds_found ion-spinner').removeClass('ion-hide');

        ajax_in_progress = feedit.callAPI(
          ajax_url_feeds_discovery,
          {
            'url' : event.originalEvent.detail.value
          },
          function( response ) {
            if (typeof(response) == 'object' && typeof(response.items) != 'undefined') {
              // check if we have the determined language present in our languages dropdown
              // and select it, otherwise select "Other"
              if (typeof(response.lang) != 'undefined') {
                // if a language was already manually selected, don't change it
                if (!lang_manually_selected) {
                  for (var i in langs_cached) {
                    if (langs_cached[i].code == response.lang) {
                      $('#feed_lang').val(response.lang);
                      lang_manually_selected = false;
                      break;
                    }
                  }
                }
              }

              // add all items returned from the API result
              var html = [];
              for (var i in response.items) {
                var feed = response.items[ i ];

                html.push(`
      <ion-item>
        <ion-avatar slot="start"><img src="${response.icon}" /></ion-avatar>
        <ion-label><strong>${feed.title}</strong><br>${feed.url}</ion-label>
        <ion-checkbox slot="end" value="${feed.url}"></ion-checkbox>
      </ion-item>
                `);
              }

              $('#feeds_found ion-list-header').after( html.join('') );
            } else {
              feedit.defaultErrorNotification();
            }
          },
          null,
          function() {
            ajax_in_progress = false;
            new_feed_discovery_changed_timer = -1;

            // hide loading indicator
            $('#feeds_found ion-spinner').addClass('ion-hide');
          }
        );
      }, 1500); // wait for 1500 seconds until we start feeds discovery
    });

    // hides all trained items for current feed
    $doc.on('click', '#feed_cleanup_icon', function() {
      $('#main ion-item-sliding ion-item').each(function() {
        var $e = $(this);

        if ($e.hasClass('item_is_trained') || !$e.find('h2').first().hasClass('unread')) {
          feedit.hide_single_item( $(this) );
        }
      });

      // hide the cleanup icon
      $(this).addClass('ion-hide');
    });

    // load left menu feeds after 1 second, so all of our other modules had the time to load
    setTimeout(function () {
      $doc.trigger('refresh-left-menu-feeds', [ function() {
        // leave a bit of space for the UI to refresh, then update the main title header
        setTimeout(function() {
          var $active_item = feedit.getActiveFeedItem();

          if ($active_item.length) {
            feedit.refreshMainTitle();
          } else {
            setTimeout(function() {
              $('.all_feeds_item').click();
            }, 500);
          }

          $('#feed_cleanup_icon').addClass('ion-hide');

          // update the "show images" icon
          feedit.updateShowImagesIcon();
        }, 250);
      } ]);
    }, 1000);

    // extend the feedit object with these public methods
    $.extend(feedit, {
      // toggles a main left menu feeds item to show or hide its sub-items
      toggleFeedsMenuItem(element) {
        var
          $e = $(element),
          $chevron_icon = $e.find('ion-icon[slot="end"]');

        if (!$chevron_icon.length) {
          return;
        }

        // open our item
        $e.siblings('.feed_actions_grid, .feeds').toggleClass('ion-hide');

        if ($chevron_icon.attr('name').indexOf('-down') > -1) {
          $chevron_icon.attr('name', 'chevron-forward-outline');
          Cookies.set( 'feeds-menu-open', false, { expires: 365 } );
        } else {
          $chevron_icon.attr('name', 'chevron-down-outline');
          Cookies.set( 'feeds-menu-open', true, { expires: 365 } );
        }
      },

      // asks the user whether to really remove the requested feed,
      // then calls the feed removal AJAX API once confirmed
      presentRemoveFeedDialog( $e ) {
        // select the appropriate ION-ITEM, if an ID was passed to this function
        if (typeof($e) == 'string') {
          $e = $('.feed_item[data-id="' + $e + '"]');
        }

        var alert = document.createElement('ion-alert');
        //alert.cssClass = 'my-custom-class';
        alert.header = window.lang.remove_feed;
        alert.message = window.lang.remove_feed_question + ' <strong>' + $e.find('ion-label h3 span').text() + ' (' + $e.data('url') + ')</strong>?';
        alert.buttons = [
          {
            text: window.lang.cancel,
            role: 'cancel',
            cssClass: 'secondary',
            /*handler: (blah) => {
              console.log('Confirm Cancel: blah');
            }*/
          }, {
            text: window.lang.confirm,
            handler: () => {
              // something is already happening with that feed, bail out
              if (!$e.find('ion-spinner').hasClass('ion-hide')) {
                return;
              }

              // show spinner next to the item
              $e.find('ion-spinner').removeClass('ion-hide');

              // make the API call to remove this feed
              feedit.callAPI(
                ajax_url_feed_remove,
                {
                  'id' : $e.data('id'),
                },
                function( response ) {
                  if (response == '') {
                    // animate item removal
                    setTimeout(function () {
                      $e.css({
                        'transition': '0.35s ease-out',
                        'transform': 'translate3d(' + ($e.width() + 10) + 'px, 0, 0)'
                      });

                      setTimeout(function () {
                        // make the previous/next item active
                        if ($e.hasClass('active')) {
                          var $replacement_active_item = $e.prev('.feed_item');

                          // try next item if previous was not found
                          if (!$replacement_active_item.length) {
                            $replacement_active_item = $e.next('.feed_item');
                          }

                          // no more items, click on all feeds
                          if (!$replacement_active_item.length) {
                            $replacement_active_item = $('.all_feeds_item');

                            // hide labels management item if we have no feeds
                            $('.labels_management').addClass('ion-hide');
                          }

                          ignore_next_feed_item_click = true;
                          $replacement_active_item.click();
                        } else {
                          // refresh content if the active feed is Bookmarks or Everything
                          if (feedit.getActiveFeedId() == 'bookmarks' || feedit.getActiveFeedId() == 'all') {
                            $doc.trigger('refresh-content');
                          }
                        }

                        feedit.presentToast({'txt': window.lang.feed_removed, 'short': true});

                        // request update of left menu badges, as number of bookmarks could have changed
                        $doc.trigger('refresh-link-counts', [{
                          'what': 'all'
                        }]);

                        $e.remove();
                      }, 340);
                    }, 1000);
                  } else {
                    feedit.defaultErrorNotification();
                  }
                },
                null,
                function() {
                  // hide the spinner
                  $e.find('ion-spinner').addClass('ion-hide');
                },
              );
            }
          }
        ];

        document.body.appendChild(alert);
        alert.present();
      },

      // presents a feed edit dialog
      presentUpdateFeedDialog( $e ) {
        // select the appropriate ION-ITEM, if an ID was passed to this function
        if (typeof($e) == 'string') {
          $e = $('.feed_item[data-id="' + $e + '"]');
        }

        // show loader
        $doc.trigger('show-loading');

        // either pass data to the edit feed modal directly
        // or load list of languages first, if not loaded yet
        // and then open the dialog
        if (Object.keys(langs_cached).length) {
          presentEditFeedModal( $e, langs_cached );
        } else {
          feedit.callAPI(
            ajax_url_languages,
            null,
            function( response ) {
              if (typeof(response) == 'object') {
                langs_cached = response;
                presentEditFeedModal( $e, response );
              } else {
                feedit.defaultErrorNotification();
              }
            },
            null,
            function() {
              // hide loader
              $doc.trigger('hide-loading');
            }
          );
        }
      },

      getActiveFeedItem() {
        return $('.feeds ion-item.active');
      },

      getActiveFeedId() {
        return $('.feeds ion-item.active').data('id');
      },

      updateShowImagesIcon() {
        var
          current_feed_id = feedit.getActiveFeedItem().data('id'),
          per_feed_show_images = (typeof(Cookies.get('show-images-' + current_feed_id)) != 'undefined' && Cookies.get('show-images-' + current_feed_id) == 1),
          show_images = (typeof(Cookies.get('show-images-' + current_feed_id)) != 'undefined' ? per_feed_show_images : (typeof(Cookies.get('show-images')) == 'undefined' || Cookies.get('show-images') == 1));

        if (show_images) {
          // make the icon green
          $('#show_images_icon').attr('color', 'success');
        } else {
          // make the icon black
          $('#show_images_icon').attr('color', 'dark');
        }
      },
    });

  };
})(jQuery);