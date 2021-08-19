(function($) {
  var
    $doc = $(document),
    adding_new_phrase = false,
    $new_phrase_label = null,
    adjuster_showing = false;

  $doc.on('app-init', function() {
    initModule();
  });

  async function presentAdjusterModal(phrase) {
    // create the modal with the `modal-page` component
    $doc.trigger('open-modal', [
      await modalController.create({
        component: 'modal-phrase-adjuster',
        componentProps: {
          'phrase' : phrase
        },
      })
    ]);

    adjuster_showing = false;
  };

  function initModule() {

    // prepare the phrase changer overlay template
    customElements.define('modal-phrase-adjuster', class extends HTMLElement {
      connectedCallback() {
        this.innerHTML = `
  <ion-header>
    <ion-toolbar>
      <ion-title>${window.lang.phrase_editing}</ion-title>
      <ion-buttons slot="primary">
        <ion-button onClick="feedit.dismissModal()">
          <ion-icon slot="icon-only" name="close"></ion-icon>
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content class="ion-padding" id="phrase_adjuster_modal">
    <ion-input id="phraseAdjuster" value="` + feedit.getTopModal().componentProps.phrase + `"></ion-input>
    
    <br>
    <br>

    <div class="ion-float-right top10px">
      <a href="javascript:feedit.dismissModal()" class="ion-margin">${window.lang.cancel}</a>
      <ion-button color="primary" id="update_phrase">${window.lang.confirm}</ion-button>
    </div>
  </ion-content>`;
      }
    });

    // opens phrase adjuster modal when clicked on a new phrase badge
    $doc.on('click', 'ion-badge.new_phrase', function() {
      if (adjuster_showing) {
        return;
      }

      adjuster_showing = true;
      $doc.trigger('show-loading');
      presentAdjusterModal( $(this).find('ion-label').html() );
    });

    // click on phrase adjuster confirmation button
    // ... will change the phrase's label to whatever text comes from the modal's input
    $doc.on('click', '#update_phrase', function() {
      $('ion-badge.new_phrase ion-label').html( $('#phraseAdjuster').val() );
      feedit.dismissModal();

      // click on the tick icon automatically when the phrase was changed,
      // as it gets quite tricky to do this on a mobile device with a finger
      $('.new_phrase_confirm').click();
    });

    // returns formatted phrase training data for the item,
    // if these data are found on it
    $doc.on('training-data-gather', function(event, options) {
      // add basic up/down training data
      if (options.badge_type == 'phrase') {
        if (options.gather_type == 'basic') {
          if (!options.data['phrases']) {
            options.data['phrases'] = [];
          }

          options.data['phrases'].push({
            'text': options.$icon.siblings('ion-label').text(),
            'rate': ((options.$icon.attr('name') == 'thumbs-up-sharp') ? 1 : 0),
            'rate_intensity': options.intensity
          });
        } else if (options.gather_type == 'removal' && !options.$badge.hasClass('newly_added')) {
          if (!options.data['phrases']) {
            options.data['phrases'] = [];
          }

          options.data['phrases'].push({
            'text': options.$badge.find('ion-label').text(),
            'rate': -1
          });
        }
      }
    });

    // adds HTML for the phrases training column button below the article text
    $doc.on('training-modal-add-columns', function(event, html) {
      html.push(`
              <ion-col data-id="phrases" class="ion-no-padding training_switchbox_column">
                <ion-tab-button>
                  <ion-icon name="pencil"></ion-icon>
                  <ion-label>${window.lang.phrases}</ion-label>
                </ion-tab-button>
              </ion-col>`);
    });

    // adds HTML data into the training modal's HTML
    // for phrases, if present in the AJAX data
    $doc.on('training-modal-add-html', function(event, options) {
      if (options.type == 'phrases') {
        // add custom phrases "add" badge
        options.html_array.push(`
            <p class="added_actions training_content_phrases ion-hide">
              <ion-icon name="information-circle-outline" class="right5px custom_phrase_info" title="${window.lang.what_is_custom_phrase}" onclick="feedit.showHelp(this, 'train-add-phrase')"></ion-icon>
              <ion-badge class="add_phrase" color="primary">
                <ion-icon name="add-circle" class="ion-float-left right10px"></ion-icon>
                <ion-label class="right5px">${window.lang.additional_phrase}</ion-label>
              </ion-badge>`);

        if (options.item.phrases) {
          for (var phrase of options.item.phrases) {
            options.html_array.push(`
              <ion-badge class="phrase_label right5px" style="background-color: ` + phrase.bg_color + `" data-unremoved-color="` + phrase.bg_color + `" title="` + phrase.text + `" data-id="${phrase.text}" data-type="phrase">
                <ion-icon name="close-circle" class="ion-float-left right10px ion-hide"></ion-icon>
                <ion-icon name="thumbs-down-sharp" class="ion-float-left right10px"></ion-icon>
                <ion-label>${phrase.text}</ion-label>
                <ion-icon name="thumbs-up-sharp" class="ion-float-right left10px"></ion-icon>
              </ion-badge>`);
          }
        }

        options.html_array.push(`
              </p>`);
      }
    });

    // add phrases-adding-relevant icons to be ignored from the up/down training when clicked on them
    // (i.e. they won't get highlighted)
    $doc.on('training-get-excepted-icon-names', function(event, ignored_icons_array) {
      ignored_icons_array.push('add-circle');
      ignored_icons_array.push('information-circle-outline');
      ignored_icons_array.push('close-circle');
    });

    // add phrases-adding-relevant badge classes to be ignored from ignoring when clicked on them
    // (i.e. they won't get grayed out with the eye icon appearing)
    $doc.on('training-get-ignoring-excepted-class-names', function(event, ignored_classes_array) {
      ignored_classes_array.push('new_phrase');
      ignored_classes_array.push('add_phrase');
      ignored_classes_array.push('custom_phrase');
      ignored_classes_array.push('phrase_label');
    });

    // add phrase badge classes which will support removal,
    // i.e. the class for existing phrases
    $doc.on('training-get-removal-supported-class-names', function(event, included_classes) {
      included_classes.push('phrase_label');
    });

    // reacts to an event asking whether we can train the clicked badge as a like/dislike one
    // when a user clicks on a badge with tick/cross icon
    $doc.on('training-can-like-unlike', function(event, options) {
      // if can_train is already false, then don't do anything
      if (options.can_train[0] !== false) {
        if (
          options.$badge.hasClass('new_phrase')
          ||
          options.$badge.hasClass('add_phrase')
        ) {
          options.can_train[0] = false;
        }
      }
    });

    // checks whether a phrase can be de-selected or it's a new phrase that has to have some kind of training
    // in order for its data to be passed on to the backend
    $doc.on('training-check-can-be-deselected', function(event, $badge, can_be_deselected) {
      // no need to do anything if this already cannot be deselected
      if (can_be_deselected[0] !== false) {
        if ($badge.hasClass('phrase_label') && $badge.hasClass('newly_added')) {
          can_be_deselected[0] = false;

          // show a toast to let the user know - if they didn't try to double-click that is
          setTimeout(function() {
            if (!$badge.hasClass('was_purple') && !$badge.find('ion-icon[color="purple"]').length) {
              feedit.presentToast({ 'txt' : window.lang.new_phrases_need_rating });
            }
          }, 750);
        }
      }
    });

    // add new phrase badge click handler
    // ... will either add a new badge to the end of all badges and allows for selecting text inside the slide
    //     or end the new phrase adding
    $doc.on('click', 'ion-slide ion-badge.add_phrase', function() {
      // check which action to perform
      var $e = $(this);

      // cancel new phrase adding
      if ($e.hasClass('adding')) {
        $e
          .attr('color', 'primary')
          .removeClass('adding')
          .find('ion-icon')
          .attr('name', 'add-circle')
          .next('ion-label')
          .html(window.lang.additional_phrase);

        // remove the badge that's being newly added
        $('ion-badge.new_phrase').remove();

        // restore CSS for the slider
        $('ion-slides')
          .css({
            '-webkit-user-select' : 'none',
            '-moz-user-select' : 'none',
            '-ms-user-select': 'none',
            'user-select' : 'none',
            '-webkit-tap-highlight-color': 'transparent',
            '-webkit-touch-callout': 'none'
          })
          .get(0)
          .lockSwipes(false); // re-enable slider

        adding_new_phrase = false;
        $new_phrase_label = null;
      } else {
        // start adding a new phrase
        $e
          .attr('color', 'danger')
          .addClass('adding')
          .find('ion-icon')
          .attr('name', 'close-circle')
          .next('ion-label')
          .html(window.lang.cancel_adding_a_phrase);

        // add a new badge for the phrase
        $e
          .parent()
          .append(`
            <ion-badge class="new_phrase" color="tertiary">
              <ion-icon name="close-circle" class="ion-float-left right10px ion-hide"></ion-icon>
              <ion-label class="ion-padding-start new_phrase_label">${window.lang.select_phrase_from_article}</ion-label>
              <ion-icon name="checkmark-sharp" class="new_phrase_confirm ion-float-right left10px"></ion-icon>
            </ion-badge>`);

        // update CSS for the slider, so we can actually start selecting text
        $('ion-slides')
          .css({
            '-webkit-user-select' : 'auto',
            '-moz-user-select' : 'auto',
            '-ms-user-select': 'auto',
            'user-select' : 'auto',
            '-webkit-tap-highlight-color': 'currentColor',
            '-webkit-touch-callout': 'inherit'
          })
          .get(0)
          .lockSwipes(true); // disable slider's sliding capabilities

        adding_new_phrase = true;
        $new_phrase_label = $('.new_phrase_label');

        // show a short hint
        feedit.presentToast({ 'txt' : window.lang.select_text_or_click_phrase_label, 'duration' : 4000, });
      }
    });

    // new phrase selection handler
    // ... this will change the label of a new phrase badge according to the text selected on page
    $doc.on('mousemove touchmove mouseup touchend touchcancel selectionchange', '.link_content, .link_anchor', function() {
      if (adding_new_phrase) {
        var t = '';

        if (window.getSelection) {
          t = window.getSelection();
        } else if (document.getSelection) {
          t = document.getSelection();
        } else if (document.selection) {
          t = document.selection.createRange().text;
        }

        if ('' + t) {
          // if the event is a mouse up or touch end/cancel,
          // getting this value seems somewhat unreliable directly
          // but always works on delay
          setTimeout(function() {
            // it may be that we've cancelled the label adding in the meanwhile
            if ($new_phrase_label !== null) {
              $new_phrase_label.html('' + t);
            }
          }, 100);
        }
      }
    });

    // click on the tick next to a newly added label
    // ... will fix the new phrase into a permanent badge and restores sliding functionality
    $doc.on('click', '.new_phrase_confirm', function() {
      var
        $new_phrase_badge = $('ion-badge.new_phrase'),
        $new_phrase_label = $new_phrase_badge.find('ion-label');

      // if there is no text selected
      // or we have a duplicate phrase
      // or the label text remains with its initial value,
      // simply remove the label
      if (
        !$new_phrase_label.html()
        ||
        $new_phrase_label.html() == window.lang.select_phrase_from_article
        ||
        $('.phrase_label ion-label').filter(function() { return this.innerHTML === $new_phrase_label.html(); }).length
      ) {
        $new_phrase_badge.parent().find('.add_phrase').click();
        return;
      }

      $new_phrase_badge
        .toggleClass('new_phrase newly_added phrase_label')
        .css('background-color', '#ffffb3') // make the new phrase neutral
        .attr('title', $new_phrase_label.html())
        .data({
          'id' : 'new',
          'type' : 'phrase',
          'unremoved-color' : '#ffffb3'
        });

      // remove the new phrase class, so we can add another phrase
      $new_phrase_label.removeClass('new_phrase_label ion-padding-start');

      // add appropriate icons to the new phrase
      $new_phrase_badge
        .prepend('<ion-icon name="thumbs-down-sharp" class="ion-float-left right10px"></ion-icon>')
        .append('<ion-icon name="thumbs-up-sharp" class="ion-float-right left10px" color="primary"></ion-icon>');

      // remove the new phrase confirmation icon
      $('.new_phrase_confirm').remove();

      // click the "cancel adding new phrase" badge to restore slider functionality
      // ... this will not remove any phrase, as we've already removed its "new" class
      $new_phrase_badge.parent().find('.add_phrase').click();

      // don't bubble this event, or we'll end up opening the phrase adjuster modal
      return false;
    });

  };
})(jQuery);