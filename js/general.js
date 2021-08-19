var feedit = (function($) {
  var
    $doc = $(document),
    $win = $(window),
    desktop_sized = false,
    page_loaded = false,
    bug_report_opening = false,
    mouse_still_down = false,
    long_press_delay = 500,
    double_click_delay = 350,
    simple_mode_on = false,
    ajax_bug_report = 'api/report_bug.php',
    ajax_langs = 'api/langs.php',
    $session_id,
    langs_cached = {},
    change_lang_opening = false,
    session_check_interval;

  // document ready
  $(function() {
    init();
  });

  function showLangChangeDropdown() {
    var select_html = ['<ion-select id="languageSelect" interface="alert" cancel-text="' + window.lang.cancel + '" ok-text="' + window.lang.confirm + '" class="ion-no-padding ion-hide" value="' + window.lang_id + '">'];

    for (var lang_data of langs_cached) {
      select_html.push('<ion-select-option value="' + lang_data.id + '">' + lang_data.name + '</ion-select-option>');
    }

    select_html.push('</ion-select>');
    $('body').append(select_html.join(''));

    // asynchronous call to let the browser create this select menu before clicking on it
    // as well as to let it set up the default selection
    setTimeout(function () {
      var select_element = $('#languageSelect')[0];
      select_element.value = window.lang_id;
      select_element.interfaceOptions = {
        'header': window.lang.change_language,
      };

      select_element.open().then(function () {
        $doc.trigger('hide-loading');
      });
    }, 100);
  }

  function init() {
    // page has loaded
    if ($('ion-title:visible').length) {
      page_loaded = true;
      $session_id = $('#session_id');
      applyBindings();
      $doc.trigger('app-init');
    } else {
      setTimeout(init, 1000);
    }
  };

  // stores an unsent bug report text in case we've reloaded
  // or clicked on modal dismissal by accident
  function saveUnsentBugReport() {
    var $e = $('#bug_report_text');
    if ($e.length && $e.val() && !$e.hasClass('no-local-save')) {
      feedit.setLocalStorageValue('bugreport', $('#bug_report_text').val());
    } else {
      feedit.removeLocalStorageValue('bugreport');
      $e.removeClass('no-local-save');
    }
  }

  // opens a bug report modal
  async function presentBugReportModal() {
    // create the modal with the `modal-page` component
    $doc.trigger('open-modal', [
      await modalController.create({
        component: 'modal-bugreport',
      })
    ]);

    bug_report_opening = false;
  };

  function applyBindings() {

    simple_mode_on = ($('#simple_mode').prop('checked') ? true : false);

    // set a repeating timer that will check current session and reload the page if it's changed
    session_check_interval = setInterval(function() {
      if (Cookies.get('feedit') != $session_id.val()) {
        clearInterval( session_check_interval );
        document.location.reload();
      }
    }, 1000);

    // prepare the bug report overlay template
    customElements.define('modal-bugreport', class extends HTMLElement {
      connectedCallback() {
        // restore possible unsent report content
        var text_area_value = feedit.getLocalStorageValue('bugreport');
        if (text_area_value === null) {
          text_area_value = '';
        }

        this.innerHTML = `
  <ion-header>
    <ion-toolbar>
      <ion-title>${window.lang.report_a_problem}</ion-title>
      <ion-buttons slot="primary">
        <ion-button onClick="feedit.dismissModal()">
          <ion-icon slot="icon-only" name="close"></ion-icon>
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content class="ion-padding">
    <ion-item>
    <ion-label position="floating">${window.lang.problem_type}</ion-label>
      <ion-select id="bug_type" value="bug">
        <ion-select-option value="bug">${window.lang.technical_problem}</ion-select-option>
        <ion-select-option value="acc">${window.lang.account_problem}</ion-select-option>
      </ion-select>

      <ion-label position="floating">${window.lang.problem_description}</ion-label>
      <ion-textarea id="bug_report_text" rows="10" placeholder="${window.lang.describe_problem_in_steps}" value="${text_area_value}"></ion-textarea>
    </ion-item>

    <br>

    <div class="ion-float-right top10px">
      <a href="javascript:feedit.dismissModal()" class="ion-margin">${window.lang.cancel}</a>
      <ion-button color="primary" id="send_bug_report">${window.lang.send}</ion-button>
    </div>
  </ion-content>`;
      }
    });

    // send bug report button
    $doc.on('click', '#send_bug_report', function() {
      // show the global loading dialog
      $doc.trigger('show-loading');

      feedit.callAPI(
        ajax_bug_report,
        {
          'type' : $('#bug_type').val(),
          'msg' : $('#bug_report_text').val(),
        },
        function( response ) {
          if (response == '') {
            feedit.dismissModal();
            feedit.presentToast({ 'txt' :  window.lang.problem_report_sent });

            // don't save into local storage when the report was already sent
            $('#bug_report_text').addClass('no-local-save');
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
    });

    $win.on('resize', function () {
      if (page_loaded) {
        // disable items swiping for training purposes, if the app has been sized
        // for a desktop resolution
        $doc.trigger('toggle-swipable-training-items');
      }
    });

    // react to desktop size change to make sure we can count on this variable
    // to be correctly populated
    $doc.on('desktop-sized-change', function(event, value) {
      desktop_sized = value;
    });

    // blur active link on item click
    $doc.on('mouseup', 'ion-item-sliding', function (event) {
      // clicking on an item makes our link anchor active
      // which brings a status bar on desktop browsers
      // which covers the bottom tabs action bar
      document.activeElement.blur();
    });

    // de/activate simple mode
    $doc.on('ionChange', '#simple_mode', function(event) {
      // hide all badges except for labels and training ones
      $('#main ion-item ion-badge').not('.label_badge, .train_up, .train_down, .trained_up, .trained_down, .simple-mode-ignore').toggleClass('ion-hide');

      if (event.originalEvent.detail.checked) {
        // add a return class to all training icons currently showing and then hide them as well
        $('#main ion-item ion-badge.train_up, ion-item ion-badge.train_down')
          .not('.ion-hide')
          .addClass('shown-outside-simple-mode ion-hide');

        // remove all ion-hide-xl-up classes, as even Desktops need to see trained icons after training
        $('.trained_up, .trained_down').removeClass('ion-hide-xl-up');

        // hide all avatars if this feed (or all feeds) have images turned off
        var current_feed_id = feedit.getActiveFeedItem().data('id');
        if (
          ( typeof(Cookies.get('show-images-' + current_feed_id)) != 'undefined' && (Cookies.get('show-images-' + current_feed_id) == 0) )
          ||
          ( typeof(Cookies.get('show-images-' + current_feed_id)) == 'undefined' &&  typeof(Cookies.get('show-images')) != 'undefined' && (Cookies.get('show-images') == 0) )
        ) {
          $('#main ion-item ion-avatar').addClass('ion-hide');
        }

        Cookies.set( 'simple-mode', true, { expires: 365 } );
        simple_mode_on = true;

        // if we've used simple mode on Desktop for the first time, let user know it's different from clicking around
        if ( feedit.isDesktopSized() && typeof (Cookies.get('first_simple_mode_message')) == 'undefined') {
          Cookies.set('first_simple_mode_message', 1, { expires: 365 });
          feedit.presentToast({
            'txt': window.lang.simple_mode_controls_differ,
            'duration': 30000,
            'buttons' : [
              {
                'txt' : window.lang.learn_more,
                'callback' : function() { $('.main_help_icon').click(); }
              }
            ]
          });
        }
      } else {
        // unhide those training badges that have the return class as well as all avatars
        $('#main ion-item ion-badge.shown-outside-simple-mode, #main ion-item ion-avatar').removeClass('shown-outside-simple-mode ion-hide');

        // restore ion-hide-xl-up classes
        $('.trained_up, .trained_down').addClass('ion-hide-xl-up');

        Cookies.set( 'simple-mode', false, { expires: 365 } );
        simple_mode_on = false;
      }

      $('#main ion-item-sliding ion-avatar').toggleClass('v_center');
      $doc.trigger('toggle-swipable-training-items');
    });

    // content refresh
    $doc.on('ionRefresh', function() {
      // remove all selected items from old feed
      feedit.resetSelectedItemsCache();

      // update the Select All checkbox state
      $doc.trigger('item-select', [{ 'origin' : 'content_refresh' }]);

      $doc.trigger('refresh-content', [
        // success callback
        function() {
          // re-select any previously selected items
          $('#main ion-item-sliding ion-item').each(function() {
            if (feedit.getSelectedItemsCache()[ $(this).data('id') ]) {
              $(this)
                .addClass('selected_item')
                .find('ion-item-sliding')
                .addClass('child_highlighted');
            }
          });
        },
        // error callback
        null,
        // always callback
        function() {
          $('ion-refresher')[0].complete();
        },
        null,
        {
          'no-loading' : 1,
        }
      ]);
    });

    // sets the mouse_still_down variable, used in swipe-training of items
    $doc.on('mousedown touchstart', function () {
      mouse_still_down = true;
    });

    // sets the mouse_still_down variable, used in swipe-training of items
    $doc.on('mouseup touchend touchcancel', function () {
      mouse_still_down = false;
    });

    // app language has changed
    $doc.on('ionChange', '#languageSelect', function(event) {
      document.location.href = document.location.href.substring(0, document.location.href.indexOf('?lang=')) + '?lang=' + event.originalEvent.detail.value;
    });

    // store any text written inside a bug report if we reloaded by accident
    $win.on('beforeunload', saveUnsentBugReport);
    $win.on('pagehide', saveUnsentBugReport); // iOS mobile browser window gone to background
    $win.on('visibilitychange', saveUnsentBugReport); // mobile browser window gone to background
    $win.on('ionModalWillDismiss', saveUnsentBugReport);
  };

  // public methods
  return {
    isDesktopSized() {
      return desktop_sized;
    },

    setLocalStorageValue(key, value) {
      try {
        localStorage.setItem(key, value);
      } catch (ex) {
        // localStorage not available
      }
    },

    getLocalStorageValue(key) {
      try {
        return localStorage.getItem(key);
      } catch (ex) {
        // localStorage not available
      }
    },

    removeLocalStorageValue(key) {
      try {
        localStorage.removeItem(key);
      } catch (ex) {
        // localStorage not available
      }
    },

    isMouseDown() {
      return mouse_still_down;
    },

    getLongPressDelayTime() {
      return long_press_delay;
    },

    getDoubleClickDelayTime() {
      return double_click_delay;
    },

    isSimpleMode() {
      return simple_mode_on;
    },

    // shows a modal with dropdown containing all languages in the system,
    // allowing user to change their application language
    changeLanguage() {
      if (change_lang_opening) {
        return;
      }

      change_lang_opening = true;
      $doc.trigger('show-loading');

      // assemble languages
      if (Object.keys(langs_cached).length) {
        showLangChangeDropdown();
      } else {
        feedit.callAPI(
          ajax_langs,
          null,
          function( response ) {
            if (typeof(response) == 'object') {
              langs_cached = response;
              showLangChangeDropdown();
            } else {
              feedit.defaultErrorNotification();
            }
          },
          null,
          function() {
            // hide loader
            $doc.trigger('hide-loading');

            // make sure we can call this again
            change_lang_opening = false;
          }
        );
      }
    },

    sendBugReport() {
      // open bug report form
      if (bug_report_opening) {
        return;
      }

      bug_report_opening = true;
      $doc.trigger('show-loading');
      presentBugReportModal();
    },

    async presentToast(options) {
      const toast = document.createElement('ion-toast');
      toast.message = options.txt;

      if (!options.short) {
        toast.duration = (typeof(options.duration) == 'number' ? options.duration : 1500);
      } else {
        toast.duration = 1000;
        toast.cssClass = 'ion-text-center';
      }

      if (options.buttons) {
        var btns = [];

        for (var i in options.buttons) {
          var btn = {
            text: options.buttons[i].txt,
          };

          if (typeof(options.buttons[i].callback) == 'function') {
            btn.handler = options.buttons[i].callback;
          }

          btns.push( btn );
        }

        // add an OK button
        btns.push({
          text: window.lang.ok,
          role: 'cancel'
        });

        toast.buttons = btns;
      } else {
        // default OK button, unless short toast was requested
        if (!options.short) {
          toast.buttons = [
            {
              text: window.lang.ok,
              role: 'cancel'
            }
          ];
        }
      }

      document.body.appendChild(toast);
      return toast.present();
    }
  }
})(jQuery);