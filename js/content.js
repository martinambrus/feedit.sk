(function($) {
  var
    $doc = $(document),
    ajax_url = 'api/content.php',
    ajax_url_bookmark = 'api/bookmark.php',
    ajax_in_progress = false,
    $sort_by,
    current_page = 1,
    $labels_select_badge,
    bookmarks_update_timeout_task = -1;

  $doc.on('app-init', function() {
    $sort_by = $('#filter_sort');
    $labels_select_badge = $('#labels-select-badge');
    initModule();
  });

  function getLabelPredictionsHTML( label_predictions ) {
    var html = [];
    for (var label_prediction of label_predictions) {
      html.push(`
            <ion-badge ` + (label_prediction.trust ? 'color="dark"' : 'style="background-color: #5E5E5E"') + ` class="label_badge prediction" data-id="${label_prediction.id}" title="${window.lang.label_temporary}">
              <small>${label_prediction.label}*</small>
            </ion-badge>`);
    }

    return html.join('');
  };

  function initModule() {

    // every state change will trigger this method, which will then in turn
    // collect current state (filters, sorting, labels etc.) and call the back-end
    // to return updated content
    $doc.on('refresh-content', function(event, successCallback, errorCallback, alwaysCallback, extraData, options) {
      if (ajax_in_progress) {
        return;
      }

      // hide the no articles warning
      $('#no_articles, #feed_error_info').addClass('ion-hide');

      if (typeof(options) == 'undefined' || typeof(options['no-loading']) == 'undefined' || !options['no-loading']) {
        $doc.trigger('show-loading');
      }

      feedit.showMainTitleLoadingIndicator();

      // makes a call to the get-content trigger, which actually fetches the content
      ajax_in_progress = true;
      $doc.trigger('get-content', [
        function( response ) {
          if (typeof(response) == 'object' && typeof(response.error) == 'undefined') {
            var
              html = [],
              items_added = 0;

            if (typeof(extraData) != 'undefined' && extraData != null && !extraData.first_load) {
              html.push(`
          <ion-item id="prev_page" button color="primary" class="ion-text-center" lines="none" onclick="feedit.prevPage()">
            <ion-label>
              &laquo; ${window.lang.previous_page}
            </ion-label>
          </ion-item>
          <ion-item id="prev_page_divider" lines="none"></ion-item>`);
            }

            // process response and build the markup to replace main content with
            for (var item of response) {
              items_added++;
              html.push( feedit.returnItemTemplate( item ) );
            }

            // increase/decrease page counter, if we're requesting next/previous page
            if (typeof(extraData) != 'undefined' && extraData != null && extraData.way == 'down' && items_added) {
              current_page++;
            } else if (typeof(extraData) != 'undefined' && extraData != null && extraData.way == 'up' && items_added) {
              current_page--;
            } else if (typeof(extraData) == 'undefined' || extraData == null || !extraData.way) {
              // reset current page and total pages if we didn't find pagination data
              current_page = 1;
            }

            // nothing to show - either show no articles warning
            // or remove the previous/next page button from page
            if (!items_added) {
              if (typeof(extraData) == 'undefined' || extraData == null || extraData.first_load) {
                // clear up old articles and show no articles warning
                $('#main ion-content ion-item-sliding, #prev_page, #prev_page_divider, #next_page, #next_page_divider').remove();

                // update status filter info in the No Articles view
                var $filter_status = $('#filter_status');
                $('#current_show_filter_value').text( $filter_status.find('ion-select-option[value="' + $filter_status.val() + '"]').html() );

                // update labels info in the No Articles view
                var
                  $filter_labels = $('#filter_labels'),
                  $labels_selected = $filter_labels.val();

                $('#number_labels_selected').text( $labels_selected.length );
                $('#labels_filtered_by').html(
                  ($labels_selected.length ? ' (<strong>' : '') +
                  $filter_labels
                    .find('ion-select-option')
                    .filter(function() {
                      return $labels_selected.indexOf( $(this).val() ) > -1;
                    })
                    .map(function() {
                      return $(this).text();
                    })
                    .toArray()
                    .join(', ') +
                  ($labels_selected.length ? '</strong>)' : '')
                );

                $('#no_articles').removeClass('ion-hide');

                // clear all selections, as we now have nothing to display
                feedit.resetSelectedItemsCache();

                // hide bottom tabs bar can be hidden
                $('#multiselect_actions').addClass('ion-hide');

                // untick the "select all" checkbox, if checked
                feedit.untickSelectAllCheckbox();
              } else {
                // check which button to remove
                if (extraData.way == 'up') {
                  $('#prev_page, #prev_page_divider').remove();
                } else {
                  $('#next_page, #next_page_divider').remove();
                }
              }
            } else {
              // clear up old articles
              $('#main ion-content ion-item-sliding, #prev_page, #prev_page_divider, #next_page, #next_page_divider').remove();

              // show next page button if we have as many items from the result as we display links per single page
              if (items_added == $('#filter_per_page').val()) {
                html.push(`
          <ion-item id="next_page_divider" lines="none"></ion-item>
          <ion-item id="next_page" button color="primary" class="ion-text-center" lines="none" onclick="feedit.nextPage()">
            <ion-label>
              ${window.lang.next_page} &raquo;
            </ion-label>
          </ion-item>`);
              }

              // write new items to page
              $('#no_articles').after( html.join('') );

            }
          } else if (typeof(response) == 'object' && typeof(response.error) != 'undefined') {
            // errorneous feed detected, get the latest error message and put it onto the No Records page
            $('#no_articles, #feed_error_info').removeClass('ion-hide');
            $('#last_feed_error').text( response.error );
          }

          // fire up the item-select event, so our select all checkbox can update its state
          $doc.trigger('item-select', ['content_refresh']);

          // make sure we're not making any swipable items swipable, unless on low resolution
          $doc.trigger('toggle-swipable-training-items');

          // update left menu badge counts
          $doc.trigger('refresh-link-counts', [ { 'what' : 'all' }, feedit.refreshMainTitle ]);

          // refresh labels for this feed
          feedit.cache_labels( feedit.getActiveFeedId() );

          feedit.show_hide_cleanup_icon();

          // make sure we don't enable swipe-to-train on a Desktop
          setTimeout(function() {
            $doc.trigger('toggle-swipable-training-items');
          }, 2500);

          // if we have a custom success callback to pass here, do it
          if (typeof(successCallback) == 'function') {
            successCallback( response );
          }
        },
        errorCallback,
        function() {
          // hide all left menu spinners after the AJAX has completed
          $('.feeds ion-spinner, #feed_cleanup_icon').addClass('ion-hide');
          feedit.hideMainTitleLoadingIndicator();

          // hide loader
          if (typeof(options) == 'undefined' || typeof(options['no-loading']) == 'undefined' || !options['no-loading']) {
            $doc.trigger('hide-loading');
          }

          // reset internal variable
          ajax_in_progress = false;

          // if we have a custom always callback to pass here, do it
          if (typeof(alwaysCallback) == 'function') {
            alwaysCallback();
          }
        },
        extraData
      ]);
    });

    // reacts to a data gathering event and returns content data from the backend API
    // accepts extra parameters, such as pagination data etc.
    $doc.on('get-content', function(event, successCallback, errorCallback, alwaysCallback, extraData) {
      // collect current state data
      var data = feedit.getBasePaginationData();

      // if we've switched accounts, the Everything item will become active on load
      // and we'll have the feed populated through that action
      if (!data.feed) {
        return;
      }

      if (typeof(extraData) != 'undefined' && extraData != null) {
        $.extend(data, extraData);
      }

      feedit.callAPI(
        ajax_url,
        data,
        function( response ) {
          // if we have a custom success callback to pass here, do it
          if (typeof(successCallback) == 'function') {
            successCallback( response );
          } else {
            console.log('Invalid success callback (not a function) passed to the get-content event: ', successCallback);
          }
        },
        errorCallback,
        alwaysCallback
      );
    });

    // bookmark badge was clicked
    $doc.on('click', '.bookmark', function() {
      var
        $e = $(this),
        $icon = $e.find('ion-icon'),
        was_bookmarked = ($icon.attr('color') == 'warning'),
        data = {
          'id' : $e.closest('ion-item').data('id'),
        };

      $e.attr( 'title', (was_bookmarked ? window.lang.bookmark_article : window.lang.remove_from_bookmarks) );
      $icon.attr( 'name', (was_bookmarked ? 'star-outline' : 'star') );

      if (was_bookmarked) {
        $icon.removeAttr('color');
        data['action'] = 0;
      } else {
        $icon.attr('color', 'warning');
        data['action'] = 1;
      }

      feedit.callAPI(
        ajax_url_bookmark,
        data,
        function( response ) {
          if (!response == '') {
            $e.attr( 'title', (!was_bookmarked ? window.lang.bookmark_article : window.lang.remove_from_bookmarks) );
            $icon.attr( 'name', (!was_bookmarked ? 'star-outline' : 'star') );

            if (was_bookmarked) {
              $icon.attr('color', 'warning');
            } else {
              $icon.removeAttr('color');
            }

            feedit.presentToast({ 'txt' : window.lang.bookmarking_failed, 'duration' : 20000, });
          }
        }, function() {
          $e.attr( 'title', (!was_bookmarked ? window.lang.bookmark_article : window.lang.remove_from_bookmarks) );
          $icon.attr( 'name', (!was_bookmarked ? 'star-outline' : 'star') );

          if (was_bookmarked) {
            $icon.attr('color', 'warning');
          } else {
            $icon.removeAttr('color');
          }
        }
      );

      // refresh bookmarks but not if we're bookmarking again within the next 5 seconds
      if (bookmarks_update_timeout_task > -1) {
        clearTimeout(bookmarks_update_timeout_task);
      }

      bookmarks_update_timeout_task = setTimeout(function() {
        $doc.trigger('refresh-link-counts', [ {
          'what' : 'bookmarks'
        } ]);

        bookmarks_update_timeout_task = -1;
      }, 5000);
    });

    // reacts to click on the Add First Feed button
    $doc.on('click', '#add_new_feed_intro_button', function() {
      // click the Add New Feed link
      $('.add_new_feed_link').click();

      // also click the Feeds link if they are closed, so the user can see their first feed
      // once it's been successfully added
      if ($('.feeds').hasClass('ion-hide')) {
        $('.feeds_main_item').click();
      }
    });

    // click on avatar image opens that image in new tab or shows it if we have image hiding active
    $doc.on('click', '.link_image', function( event ) {
      var
        $img = $(this).find('img'),
        src = $img.attr('src'),
        data_src = $img.attr('data-hidden-img'),
        feed_icon = feedit.getActiveFeedItem().find('ion-label h3 img').attr('src');

      // if we've got a hidden image stored in data, show it
      if (data_src) {
        $img.attr('src', data_src);
        $img.removeAttr('data-hidden-img');

        // this item doesn't have an image - inform user, so they don't feel like it didn't display
        if (src == data_src) {
          feedit.presentToast({ 'txt' : window.lang.article_no_img });
        }
      } else {
        if (src != feed_icon) {
          window.open(src, '_blank');
        }
      }
    });

    // H2 click will show/hide the full content of the long description
    $doc.on('mouseup', 'ion-item-sliding', function (event) {
      // as we're reacting to click of all elements inside the ion-item-sliding container,
      // check that we've not clicked on a badge, link or an icon
      if (
        event.target.className.indexOf('anchor') > -1 ||
        event.target.className.indexOf('avatar_img') > -1 ||
        event.target.tagName == 'ION-BADGE' ||
        event.target.tagName == 'ION-ICON' ||
        event.target.tagName == 'ION-AVATAR' ||
        event.target.tagName == 'SMALL') {
        return;
      }

      var $e;

      if (this.tagName == 'ION-TEXT' || this.className.indexOf('feed_body') > -1) {
        $e = $(this);
      } else if (this.tagName == 'ION-ITEM-SLIDING') {
        $e = $(this).find('.feed_body');
      } else {
        $e = $(this).closest('ion-item').find('.feed_body');
      }

      var $item = $e.closest('ion-item');

      // don't continue if the item is being swipe-trained
      if ( $item.parent('ion-item-sliding').hasClass('item-sliding-active-slide') ) {
        return;
      }

      setTimeout(function() {
          // check that we've not just de/highlighted this item, in which case
          // we didn't really want to expand/contract the article text
          if (!$item.hasClass('highlight_action_occured')) {
            $e.toggleClass('ion-text-wrap');

            // if we're showing the full text, unhide it and replace all image sources with their real source URLs from data
            if ($e.hasClass('ion-text-wrap')) {
              $e.find('.description_with_tags img').each(function() {
                var $img = $(this);
                $img.attr('src', $img.data('src') );
              });
            }

            // show original and hide tag-less, and vice-versa
            $e.find('ion-text').toggleClass('ion-hide');
          }
      }, 150); // do this on a delay, so the highlight action class can be added to the H2 and we could ignore this click, if neccessary
    });

    // clicking on description link will open that link in a new window
    $doc.on('click', '.description_with_tags a', function() {
      window.open( $(this).attr('href'), '_blank' );
      return false;
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      getBasePaginationData() {
        // get labels dropdown value and make sure it's an array or convert it to one
        var labels = $('#filter_labels').val();

        // convert to array if a default value is loaded from cookie and passed via PHP
        if (typeof(labels) == 'string' && labels.indexOf('[') > -1) {
          labels = labels.replace('[', '').replace(']', '').split(',');
          $('#filter_labels').val( labels );
        }

        return {
          'feed' : feedit.getActiveFeedId(),
          'status' : $('#filter_status').val(),
          'sort' : $('#filter_sort').val(),
          'hide' : $('#filter_hiding').val(),
          'per_page' : $('#filter_per_page').val(),
          'labels' : labels
        };
      },

      // returns HTML for a single item using the provided data
      returnItemTemplate( item ) {
        var
          html = [],
          active_feed_favicon = $('#main_menu .feeds ion-item.active ion-label h3 img').attr('src'),
          current_feed_id = feedit.getActiveFeedItem().data('id'),
          per_feed_show_images = (typeof(Cookies.get('show-images-' + current_feed_id)) != 'undefined' && Cookies.get('show-images-' + current_feed_id) == 1),
          show_images = (typeof(Cookies.get('show-images-' + current_feed_id)) != 'undefined' ? per_feed_show_images : (typeof(Cookies.get('show-images')) == 'undefined' || Cookies.get('show-images') == 1)),
          hide_avatars = (!show_images && feedit.isSimpleMode());

        html.push(`
          <ion-item-sliding` + (feedit.getSelectedItemsCache()[ item._id ] ? ' class="child_highlighted"' : '') + `>
            <ion-item-options side="start">
              <ion-item-option color="danger" expandable>
                <ion-icon name="close-sharp" class="ion-float-left"></ion-icon>
                &nbsp; ${window.lang.Dislike}
              </ion-item-option>
            </ion-item-options>
  
            <ion-item class="link ion-color` + ((typeof(item.rated) != 'undefined' && item.rated !== '') ? ' item_is_trained' : '')  + (feedit.getSelectedItemsCache()[ item._id ] ? ' selected_item' : '') + `" data-id="${item._id}" data-sc="${item.score_conformed}" data-date="${item.date_stamp}">
              <ion-avatar slot="start" class="link_image ` + ( hide_avatars ? 'ion-hide ' : '' ) + (item.date.indexOf(', ') > -1 ? 'date_multiline' : '') + (feedit.isSimpleMode() ? ' v_center' : '') + `">
                <img class="avatar_img" src="` + (item.img && (per_feed_show_images || show_images) ? item.img : (active_feed_favicon ? active_feed_favicon : 'img/logo114.png')) + `" ` + (!show_images && !per_feed_show_images ? ' data-hidden-img="' + (item.img ? item.img : (active_feed_favicon ? active_feed_favicon : 'img/logo114.png') ) + '"' : '') + `>
  
                <ion-badge color="light" class="link_datetime` + (item.date.indexOf(', ') > -1 ? ' link_datetime_long' : '') + (feedit.isSimpleMode() ? ' ion-hide' : '') + `" title="${item.date_long}">
                  <small>${item.date}</small>
                </ion-badge>
              </ion-avatar>
              <ion-label>
                <h2 class="ion-text-wrap` + (!item.read ? ' unread' : '') + `">
                  <a href="${item.link}" target="_blank" class="item_link"><span class="anchor">${item.title}</span></a>
                </h2>
  
                <h2 class="feed_body">
                  <ion-text class="description_tagless">${item.description_clear}</ion-text>
                  <ion-text class="description_with_tags ion-hide">${item.description}</ion-text>
                </h2>
  
                <p class="actions ion-text-wrap">
                  <ion-badge color="light" class="bookmark right5px simple-mode-ignore" title="` + (!item.bookmarked ? window.lang.bookmark_article : window.lang.remove_from_bookmarks) + `">
                    <ion-icon name="star` + (!item.bookmarked ? '-outline' : '') + `"` + (!item.bookmarked ? '' : ' color="warning"') + `></ion-icon>
                  </ion-badge>`);

        if (item.label_predictions) {
          html.push( getLabelPredictionsHTML( item.label_predictions ) );
        }

        if (item.labels) {
          html.push( feedit.getLabelsHTML( item.labels ) );
        }

        var labels_badge_classes = '';
        if (feedit.isSimpleMode()) {
          // hide labels badge in simple mode only if we're not in Bookmarks or Everything
          if (current_feed_id != 'bookmarks' && current_feed_id != 'all') {
            labels_badge_classes += 'ion-hide';
          } else if (current_feed_id == 'bookmarks' || current_feed_id == 'all') {
            // always show labels badge for Bookmarks and Everything
            labels_badge_classes += 'simple-mode-ignore';
          }
        } else {
          // hide labels badge if we have no labels
          if ($labels_select_badge.hasClass('ion-hide')) {
            labels_badge_classes += 'ion-hide simple-mode-ignore';
          } else if (current_feed_id == 'bookmarks' || current_feed_id == 'all') {
            // always show labels badge for Bookmarks and Everything
            labels_badge_classes += 'simple-mode-ignore';
          }
        }

        html.push(`
  
                  <ion-badge color="success" class="ion-hide-xl-down train_up` + (feedit.isSimpleMode() ? ((typeof(item.rated) == 'undefined' || item.rated == 1 || item.rated === '') ? ' shown-outside-simple-mode' : '') + ' ion-hide' : ((typeof(item.rated) == 'undefined' || item.rated == 1 || item.rated === '') ? '' : ' ion-hide')) + `">
                    <ion-icon name="checkmark-sharp"></ion-icon>
                  </ion-badge>
                  <ion-badge color="danger" class="ion-hide-xl-down train_down` + (feedit.isSimpleMode() ? ((typeof(item.rated) == 'undefined' || item.rated == 0 || item.rated === '') ? ' shown-outside-simple-mode' : '') + ' ion-hide' : ((typeof(item.rated) == 'undefined' || item.rated == 0 || item.rated === '') ? '' : ' ion-hide')) + `">
                    <ion-icon name="close-sharp"></ion-icon>
                  </ion-badge>
  
                  <ion-badge class="train` + (feedit.isSimpleMode() ? ' ion-hide' : '') + `">
                     <ion-icon name="bar-chart-sharp" class="ion-float-left"></ion-icon>
                    &nbsp;<ion-text><small>${window.lang.train}</small></ion-text>
                  </ion-badge>

                  <ion-badge class="labels ${labels_badge_classes}" color="` + (current_feed_id == 'bookmarks' || current_feed_id == 'all' ? 'light' : 'dark') + `">
                     <ion-icon name="pricetag-outline" class="ion-float-left"></ion-icon>
                    &nbsp;<ion-text><small>${window.lang.labels}</small></ion-text>
                  </ion-badge>

                  <!--  up/down result badge after the item has been swipe-trained //-->
                  <ion-badge color="success" class="` + (feedit.isSimpleMode() ? '' : 'ion-hide-xl-up ') + `trained_up` + ((typeof(item.rated) != 'undefined' && item.rated == 1 ) ? '' : ' ion-hide') + `">
                    <ion-icon name="checkmark-sharp"></ion-icon>
                  </ion-badge>
                  <ion-badge color="danger" class="` + (feedit.isSimpleMode() ? '' : 'ion-hide-xl-up ') + `trained_down` + ((typeof(item.rated) != 'undefined' && item.rated == 0 && item.rated !== '') ? '' : ' ion-hide') + `">
                    <ion-icon name="close-sharp"></ion-icon>
                  </ion-badge>`);

        // for "bookmarks" and "everything" feed items, also include a label with the feed name in it
        if (typeof(item.feed) != 'undefined') {
          var
            $span = $('.feeds ion-item[data-id=' + item.feed + '] ion-label h3 span'),
            feed_name = $span.text(),
            feed_favicon = $span.prev('img').attr('src');
          html.push(`
                    <ion-badge color="light" class="feed_name_badge simple-mode-ignore">
                      <img src="${feed_favicon}" class="right5px" /><small>${feed_name}</small>
                    </ion-badge>`);
        }

          html.push(`
                </p>
              </ion-label>
            </ion-item>
  
            <ion-item-options side="end">
              <ion-item-option color="success" expandable>
                ${window.lang.Like}&nbsp;
                <ion-icon name="checkmark-sharp" class="ion-float-left"></ion-icon>
              </ion-item-option>
            </ion-item-options>
          </ion-item-sliding>`);

        return html.join('');
      },

      getNextPageSortData( sort_data ) {
        if ($sort_by.val() == 'date') {
          // date sorting
          var
            $last_item = $('#main ion-content ion-item-sliding ion-item').last(),
            use_date = $last_item.data('date'),
            paginationExclusionData = feedit.getSkipLimitPaginationData( 'date', use_date ),
            use_skip_limit = paginationExclusionData.use_skip_limit,
            exclude_ids = paginationExclusionData.exclude_ids; // will contain all IDs for previous items with same score, so we can exclude them

          // update sorting data
          sort_data.use_skip_limit = use_skip_limit;
          sort_data.date = use_date;
          sort_data.exclude_ids = exclude_ids;
          sort_data.page = current_page;
        } else {
          // score training
          var
            $last_item = $('#main ion-content ion-item-sliding ion-item').last(),
            use_score = $last_item.data('sc'),
            paginationExclusionData = feedit.getSkipLimitPaginationData( 'sc', use_score ),
            use_skip_limit = paginationExclusionData.use_skip_limit,
            exclude_ids = paginationExclusionData.exclude_ids; // will contain all IDs for previous items with same score, so we can exclude them

          // update sorting data
          sort_data.use_skip_limit = use_skip_limit;
          sort_data.sc = use_score;
          sort_data.exclude_ids = exclude_ids;
          sort_data.page = current_page;
        }

        return sort_data;
      },

      nextPage() {
        $doc.trigger('refresh-content', [
          null,
          null,
          null,
          (!$('#next_page').hasClass('force_reload') ?
          feedit.getNextPageSortData( {
            'way' : 'down',
          }) : null),
        ]);
      },

      getPrevPageSortData( sort_data ) {
        if ($sort_by.val() == 'date') {
          // date sorting
          var
            $first_item = $('#main ion-content ion-item-sliding ion-item').first(),
            use_date = $first_item.data('date'),
            paginationExclusionData = feedit.getSkipLimitPaginationData( 'date', use_date ),
            use_skip_limit = paginationExclusionData.use_skip_limit,
            exclude_ids = paginationExclusionData.exclude_ids; // will contain all IDs for previous items with same date, so we can exclude them

          // update sorting data
          sort_data.use_skip_limit = use_skip_limit;
          sort_data.date = use_date;
          sort_data.exclude_ids = exclude_ids;
          sort_data.page = current_page;
        } else {
          // score sorting
          var
            $first_item = $('#main ion-content ion-item-sliding ion-item').first(),
            use_score = $first_item.data('sc'),
            paginationExclusionData = feedit.getSkipLimitPaginationData( 'sc', use_score ),
            use_skip_limit = paginationExclusionData.use_skip_limit,
            exclude_ids = paginationExclusionData.exclude_ids; // will contain all IDs for previous items with same score, so we can exclude them

          // update sorting data
          sort_data.use_skip_limit = use_skip_limit;
          sort_data.sc = use_score;
          sort_data.exclude_ids = exclude_ids;
          sort_data.page = current_page;
        }

        return sort_data;
      },

      prevPage() {
        $doc.trigger('refresh-content', [
          null,
          null,
          null,
          (!$('#prev_page').hasClass('force_reload') ?
            feedit.getPrevPageSortData({
            'way' : 'up',
          }) : null),
        ]);
      },

      getCurrentPage() {
        return current_page;
      },

      getSkipLimitPaginationData( compare_on, comparison_value ) {
        var
          $all_items = $('#main ion-content ion-item-sliding'),
          use_skip_limit = 0,
          exclude_ids = []; // will contain all IDs for previous items with same score / date, so we can exclude them

        // check if we have any more items with same score / date and that all of our items are not with the same score / date
        var $same_value_items = $all_items.find('[data-' + compare_on + '="' + comparison_value + '"]');
        if ($same_value_items.length == $all_items.length) {
          // all items on page are with same score, we'll need to use skip-limit pagination
          use_skip_limit = 1;
        } else {
          // assemble IDs of all items previous to the last item with the same score to ignore
          exclude_ids = $same_value_items.map(function() {
            return $(this).data('id');
          }).toArray();
        }

        return {
          'use_skip_limit' : use_skip_limit,
          'exclude_ids'    : exclude_ids,
        };
      },

      instaFetchFeed( feed_id ) {
        feedit.presentToast({ 'txt' : window.lang.available_soon, 'duration' : 2500, });
        console.log('insta-fetch of feed ' + feed_id + ' requested');
      },

      getLabelsHTML( labels ) {
        var html = [];
        for (var label of labels) {
          html.push(`
                <ion-badge color="dark" class="label_badge" data-id="${label.id}">
                  <small>${label.label}</small>
                </ion-badge>`);
        }

        return html.join('');
      },

      showMainTitleLoadingIndicator() {
        // hide the cleanup icon, if shown and show a loader instead
        if (!$('#feed_cleanup_icon').hasClass('ion-hide')) {
          $('#feed_cleanup_icon').addClass('ion-hide re-show-after-load');
        }

        // show the loading indicator
        if (!$('#feed_load_main_title_indicator').length) {
          $('#active_feed_title_header > span').prepend('<ion-spinner name="lines-small" class="right5px ion-float-left" id="feed_load_main_title_indicator"></ion-spinner>');
        }
      },

      hideMainTitleLoadingIndicator() {
        // hide (remove) the loading indicator
        $('#feed_load_main_title_indicator').remove();

        // re-show the cleanup icon
        if ($('#feed_cleanup_icon').hasClass('re-show-after-load')) {
          $('#feed_cleanup_icon').removeClass('re-show-after-load ion-hide');
        }
      },

      refreshMainTitle() {
        if (feedit.getActiveFeedItem().find('.links_counter ion-badge').text() != '0') {
          $('#active_feed_title_header .feed_title_and_count').text('(' + feedit.getActiveFeedItem().find('.links_counter ion-badge').text() + ') ' + feedit.getActiveFeedItem().find('ion-label h3 span').text());
        } else {
          $('#active_feed_title_header .feed_title_and_count').text(feedit.getActiveFeedItem().find('ion-label h3 span').text());
        }
      },
    });

  };
})(jQuery);