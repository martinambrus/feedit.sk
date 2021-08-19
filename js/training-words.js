(function($) {
  var $doc = $(document);

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // returns formatted word training data for the item,
    // if these data are found on it
    $doc.on('training-data-gather', function(event, options) {
      // add basic up/down training data
      if (options.badge_type == 'word') {
        if (options.gather_type == 'basic') {
          if (!options.data['words']) {
            options.data['words'] = [];
          }

          options.data['words'].push({
            'word': options.$icon.siblings('ion-label').text(),
            'rate': ((options.$icon.attr('name') == 'thumbs-up-sharp') ? 1 : 0),
            'rate_intensity': options.intensity
          });
        } else if (options.gather_type == 'new_ignored') {
          // add training data for newly ignored words
          if (!options.data['words']) {
            options.data['words'] = [];
          }

          options.data['words'].push({
            'word': options.$badge.find('ion-label').text(),
            'rate': -1 // ignored items have a rate value of -1
          });
        } else if (options.gather_type == 'prev_ignored') {
          // add training data for previously ignored words
          if (!options.data['words']) {
            options.data['words'] = [];
          }

          options.data['words'].push({
            'word': options.$badge.find('ion-label').text(),
            'rate': 2 // unignored items have a rate value of 2
          });
        }
      }
    });

    // adds HTML data into the training modal's HTML
    // for words, if present in the AJAX data
    $doc.on('training-modal-add-html', function(event, options) {
      if (options.type == 'words') {
        options.html_array.push(`
              <p class="added_actions training_content_words">`);

        if (options.item.words) {
          for (var word of options.item.words) {
            options.html_array.push(`
                <ion-badge class="word_label ` + (word.ignored ? 'ignored ' : '') + `right5px" style="background-color: ` + (word.ignored ? options.ignored_badge_bg_color : word.bg_color) + `" data-unignored-color="${word.bg_color}" title="` + (word.ignored ? window.lang.word_ignored_from_scoring : word.word) + `"` + (word.ignored ? ' data-was-ignored="1"' : '') + ` data-id="${word.word}" data-type="word">
                  <ion-icon name="eye-off-sharp" class="ion-float-left right10px` + (!word.ignored ? ' ion-hide' : '') + `"></ion-icon>
                  <ion-icon name="thumbs-down-sharp" class="ion-float-left right10px` + (word.ignored ? ' ion-hide' : '') + `"></ion-icon>
                  <ion-label>${word.word}</ion-label>
                  <ion-icon name="thumbs-up-sharp" class="ion-float-right left10px` + (word.ignored ? ' ion-hide' : '') + `"></ion-icon>
                </ion-badge>`);
          }
        }

        options.html_array.push(`
              </p>`);
      }
    });

  };
})(jQuery);