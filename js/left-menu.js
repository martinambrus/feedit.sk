(function($) {
  var
    $doc = $(document),
    $main_menu,
    $show_menu_icon,
    first_desktop_size_change_event_received = false, // used to prevent showing left menu when hidden on an iPad during the first event
    menu_showing,
    left_item_popover_opening = false;

  $doc.on('app-init', function() {
    $main_menu = $('#main_menu');
    $show_menu_icon = $('#menu_show');
    // check if our left menu is showing
    menu_showing = !$main_menu.hasClass('ion-hide');

    initModule();
  });

  function getFeedItemTemplate( item ) {
    return `
          <ion-item button class="feed_item ion-no-border` + (Cookies.get('feed-active') == item.id ? ' active' : '') + `" lines="none" title="${item.title}" data-id="${item.id}" data-url="${item.url}" data-lang="${item.lang}" data-allow-duplicates="${item.allow_duplicates}">
            <ion-label>
              <h3>
                <img src="${item.icon}" class="ion-float-left">
                <span` + (typeof(item.tiers_training_check) != 'undefined' && item.tiers_training_check ? ' class="trained"' : '') + `>${item.title}</span>
              </h3>
            </ion-label>
            <ion-badge slot="end" class="links_counter` + ($('#remove_feeds').prop('checked') ? ' ion-hide' : '') + `">
              <!--<ion-badge color="success">0</ion-badge> //-->
              <ion-badge color="light">${item.count}</ion-badge>
            </ion-badge>
            <ion-icon name="trash-bin" slot="end" size="small" class="` + ($('#remove_feeds').prop('checked') ? '' : 'ion-hide') + `" color="danger"></ion-icon>
            <ion-spinner name="lines-small" slot="end" class="ion-hide"></ion-spinner>
          </ion-item>
        `;
  };

  // opens the left menu item popover with per-feed actions
  async function presentLeftItemMenu( event, feed_id, feed_title ) {
    // create the popover with the `modal-page` component
    $doc.trigger('open-popover', [
      await popoverController.create({
        component: 'popover-left-item-menu',
        showBackdrop: false,
        event : event,
        componentProps: {
          'feed_id' : feed_id,
          'title' : feed_title
        },
      })
    ]);

    left_item_popover_opening = false;
  };

  function initModule() {

    // prepare the add feed template
    customElements.define('popover-left-item-menu', class ModalContent extends HTMLElement {
      connectedCallback() {
        var html = [];
        html.push(`
        <ion-content id="left-menu-item-popover">
          <ion-list>
            <ion-list-header>` + feedit.getTopPopover().componentProps.title + `</ion-list-header>
            <ion-item button onclick="feedit.markFeedRead('` + feedit.getTopPopover().componentProps.feed_id + `'); feedit.dismissPopover();">${window.lang.mark_feed_read}</ion-item>
            <ion-item button onclick="feedit.trainAllUp('` + feedit.getTopPopover().componentProps.feed_id + `'); feedit.dismissPopover();">${window.lang.like_all_feed_articles}</ion-item>
            <ion-item button onclick="feedit.trainAllDown('` + feedit.getTopPopover().componentProps.feed_id + `'); feedit.dismissPopover();">${window.lang.dislike_all_feed_articles}</ion-item>
            <ion-item button onclick="feedit.instaFetchFeed('` + feedit.getTopPopover().componentProps.feed_id + `'); feedit.dismissPopover();">${window.lang.insta_fetch_articles}</ion-item>
            <ion-item button onclick="feedit.presentUpdateFeedDialog('` + feedit.getTopPopover().componentProps.feed_id + `'); feedit.dismissPopover();">${window.lang.edit_feed}</ion-item>
            <ion-item button onclick="feedit.presentRemoveFeedDialog('` + feedit.getTopPopover().componentProps.feed_id + `'); feedit.dismissPopover();">${window.lang.remove_feed}</ion-item>
            <ion-item lines="none" detail="false" button onClick="feedit.dismissPopover()">${window.lang.close}</ion-item>
          </ion-list>
        </ion-content>`);

        this.innerHTML = html.join('');
      }
    });

    // loads new counts for left menu items and refreshes those counters on page
    $doc.on('update-feeds-counts', function(event, data) {
      for (var item of data) {
        $('.feeds ion-item[data-id="' + item.id + '"] .links_counter ion-badge').text( item.count );
      }
    });

    // replaces the left menu feeds items with the given data
    $doc.on('left-menu-feeds-update', function( event, data, successCallback ) {
      $('.feeds .feed_item').remove();

      var html = [];

      for (var i in data) {
        html.push( getFeedItemTemplate( data[ i ] ) );
      }

      if (html.length) {
        $('.root_menu .bookmarks_item').after( html.join('') );

        // show labels management item if we have feeds
        $('.labels_management').removeClass('ion-hide');

        // if any of the feeds are properly trained (to use the tiers slider),
        // and the user wasn't notified yet, let the user know
        if (typeof(Cookies.get( 'feed_well_trained_info_shown' )) == 'undefined' && $('.feeds span.trained').length) {
          Cookies.set( 'feed_well_trained_info_shown', 1, { expires: 365 } );
          feedit.presentToast({ 'txt' : window.lang.feed_well_trained_info, 'duration' : 30000, });
        }
      } else {
        // hide labels management item if we have feeds
        $('.labels_management').addClass('ion-hide');
      }

      if (typeof(successCallback) == 'function') {
        successCallback();
      }
    });

    // appends newly added feeds to the left menu
    $doc.on('left-menu-feeds-add', function( event, data ) {
      var html = [];

      for (var i in data) {
        html.push( getFeedItemTemplate( data[ i ] ) );
      }

      if (html.length) {
        $('.root_menu .add_new_feed_link').before( html.join('') );
        // show labels management item if we have feeds
        $('.labels_management').removeClass('ion-hide');
      } else {
        // hide labels management item if we have feeds
        $('.labels_management').addClass('ion-hide');
      }
    });

    // hides/shows the left menu on the show/hide icon click
    $doc.on('click', '#menu_hide, #menu_show', function() {
      $main_menu.add($show_menu_icon).toggleClass('ion-hide');
      menu_showing = !$main_menu.hasClass('ion-hide');

      $doc.trigger('show-hide-splitter', [ (!menu_showing ? 'hide' : 'show') ]);
      Cookies.set( 'left-menu-visible', menu_showing, { expires: 365 } );
    });

    // closes the left menu on a mobile device when the close icon is clicked
    $doc.on('click', '#menu_hide_mobile', function() {
      $main_menu[0].close(true);
    });

    // reacts to a desktop size change to ensure that our menu is visible
    // when showing a mobile resolution and it was previously hidden
    $doc.on('desktop-sized-change', function(event, is_desktop) {
      // don't act on first page change event,
      // as that's being fired up on page load
      // and would show a hidden left menu on iPad in error
      if (!first_desktop_size_change_event_received) {
        first_desktop_size_change_event_received = true;
        return;
      }

      if (!is_desktop && $main_menu.hasClass('ion-hide')) {
        // reveal menu if we changed from desktop to mobile and the menu is hidden
        $main_menu.addClass('was_hidden').removeClass('ion-hide');
        $show_menu_icon.addClass('ion-hide');
      } else if (is_desktop && $main_menu.hasClass('was_hidden')) {
        // hide the menu back when switching to desktop and it was hidden before
        $main_menu.removeClass('was_hidden').addClass('ion-hide');
        $show_menu_icon.removeClass('ion-hide');
      }
    });

    // fire up content update on each filter dropdown change in the left menu
    // note: the delayed timeout was disabled, as it was either too short to do multiple filter changes
    //       or too long to wait for a single one
    $doc.on('ionChange', '#filter_status, #filter_sort, #filter_hiding, #filter_per_page', function() {
      // store per-feed state, but also store last sort as well below, so we can start with that
      Cookies.set( this.id + '-' + feedit.getActiveFeedItem().data('id'), this.value, { expires: 365 } );

      // save current state in cookie
      Cookies.set( this.id, this.value, { expires: 365 } );

      // if this item has a no-content-change class, it means that something else is loading content,
      // possibly without the loading overlay in refresh-content and we should not interfere
      if (this.className.indexOf('no_content_change') == -1) {
        $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
      } else {
        $(this).removeClass('no_content_change');
      }

      // if we've use the tiers hiding slider and we're either looking at a feed that's not been well-trained yet
      // or we're looking at Bookmarks or Everything and not all feeds are well-trained yet, warn user
      if (this.id == 'filter_hiding') {
        if (
          (feedit.getActiveFeedId() == 'all' || feedit.getActiveFeedId() == 'bookmarks')
          &&
          $('.feeds span.trained').length < $('.feeds .feed_item').length
          &&
          typeof (Cookies.get('all_feeds_not_well_trained_warning')) == 'undefined'
        ) {
          // showing Bookmarks or Everything and not all feeds are well-trained
          Cookies.set('all_feeds_not_well_trained_warning', 1, { expires: 365 });
          feedit.presentToast({
            'txt': window.lang.multi_feeds_tiers_slider_used_with_untrained_feeds,
            'duration': 60000,
          });
        } else if (
          !feedit.getActiveFeedItem().find('h3 span').hasClass('trained')
          &&
          typeof (Cookies.get('feed_not_well_trained_warning')) == 'undefined'
        ) {
          // showing untrained feed
          Cookies.set('feed_not_well_trained_warning', 1, { expires: 365 });
          feedit.presentToast({'txt': window.lang.tiers_slider_used_with_untrained_feed, 'duration': 60000,});
        }
      }
    });

    // prevent right-click context menu on left menu feed items,
    // as we want to actually show the popover menu when we right-click on them
    $doc.on('contextmenu', '.feed_item', function( event ) {
      if (left_item_popover_opening) {
        return;
      }

      left_item_popover_opening = true;

      // add this class, so the click event won't register
      // as we release the mouse / finger, as we don't want to be
      // changing feed but rather showing a popover menu
      $(this).addClass('longpressed');

      presentLeftItemMenu( event, $(this).data('id'), $(this).find('ion-label h3 span').text() );
      return false;
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      leftMenuShowing() {
        return menu_showing;
      }
    });

  };
})(jQuery);