(function($) {
  var $doc = $(document);

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // returns formatted author training data for the item,
    // if these data are found on it
    $doc.on('training-data-gather', function(event, options) {
      // add basic up/down training data
      if (options.badge_type == 'author') {
        if (options.gather_type == 'basic') {
          options.data['author'] = {
            'name': options.$icon.siblings('ion-label').text(),
            'rate': ((options.$icon.attr('name') == 'thumbs-up-sharp') ? 1 : 0),
            'rate_intensity': options.intensity
          };
        } else if (options.gather_type == 'new_ignored') {
          // add training data for newly ignored authors
          options.data['author'] = {
            'name': options.$badge.find('ion-label').text(),
            'rate': -1 // ignored items have a rate value of -1
          };
        } else if (options.gather_type == 'prev_ignored') {
          // add training data for previously ignored authors
          options.data['author'] = {
            'name': options.$badge.find('ion-label').text(),
            'rate': 2 // unignored items have a rate value of 2
          };
        }
      }
    });

    // adds HTML data into the training modal's HTML
    // for an author, if one was found in the AJAX data
    $doc.on('training-modal-add-html', function(event, options) {
      if (options.type == 'author') {
        options.html_array.push(`
            <p class="added_actions training_content_author ion-hide">`);

        if (options.item.author) {
          options.html_array.push(`
              <ion-badge class="` + (!options.item.labels ? '' : 'left5px ') + `author_label` + (options.item.author.ignored ? ' ignored' : '') + `" style="background-color: ` + (options.item.author.ignored ? options.ignored_badge_bg_color : options.item.author.bg_color) + `" data-unignored-color="${options.item.author.bg_color}" title="` + (options.item.author.ignored ? window.lang.author_ignored_from_scoring : options.item.author.name) + `"` + (options.item.author.ignored ? ' data-was-ignored="1"' : '') + ` data-id="${options.item.author.name}" data-type="author">
                <ion-icon name="eye-off-sharp" class="ion-float-left right10px` + (!options.item.author.ignored ? ' ion-hide' : '') + `"></ion-icon>
                <ion-icon name="thumbs-down-sharp" class="ion-float-left right10px` + (options.item.author.ignored ? ' ion-hide' : '') + `"></ion-icon>
                <ion-label>${options.item.author.name}</ion-label>
                <ion-icon name="thumbs-up-sharp" class="ion-float-right left10px` + (options.item.author.ignored ? ' ion-hide' : '') + `"></ion-icon>
              </ion-badge>`);

        }

        options.html_array.push(`
            </p>`);
      }
    });

  };
})(jQuery);