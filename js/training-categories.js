(function($) {
  var $doc = $(document);

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // returns formatted category training data for the item,
    // if these data are found on it
    $doc.on('training-data-gather', function(event, options) {
      // add basic up/down training data
      if (options.badge_type == 'category') {
        if (options.gather_type == 'basic') {
          if (!options.data['categories']) {
            options.data['categories'] = [];
          }

          options.data['categories'].push({
            'name': options.$icon.siblings('ion-label').text(),
            'rate': ((options.$icon.attr('name') == 'thumbs-up-sharp') ? 1 : 0),
            'rate_intensity': options.intensity
          });
        } else if (options.gather_type == 'new_ignored') {
          // add training data for newly ignored categories
          if (!options.data['categories']) {
            options.data['categories'] = [];
          }

          options.data['categories'].push({
            'name': options.$badge.find('ion-label').text(),
            'rate': -1 // ignored items have a rate value of -1
          });
        } else if (options.gather_type == 'prev_ignored') {
          // add training data for previously ignored categories
          if (!options.data['categories']) {
            options.data['categories'] = [];
          }

          options.data['categories'].push({
            'name': options.$badge.find('ion-label').text(),
            'rate': 2 // unignored items have a rate value of 2
          });
        }
      }
    });

    // adds HTML data into the training modal's HTML
    // for categories, if present in the AJAX data
    $doc.on('training-modal-add-html', function(event, options) {
      if (options.type == 'categories') {
        options.html_array.push(`
              <p class="added_actions training_content_categories ion-hide">`);

        if (options.item.categories) {
          for (var category of options.item.categories) {
            options.html_array.push(`
                <ion-badge class="category_label ` + (category.ignored ? 'ignored ' : '') + `right5px" style="background-color: ` + (category.ignored ? options.ignored_badge_bg_color : category.bg_color) + `" data-unignored-color="${category.bg_color}" title="` + (category.ignored ? window.lang.category_ignored_from_scoring : category.name) + `"` + (category.ignored ? ' data-was-ignored="1"' : '') + ` data-id="${category.name}" data-type="category">
                  <ion-icon name="eye-off-sharp" class="ion-float-left right10px` + (!category.ignored ? ' ion-hide' : '') + `"></ion-icon>
                  <ion-icon name="thumbs-down-sharp" class="ion-float-left right10px` + (category.ignored ? ' ion-hide' : '') + `"></ion-icon>
                  <ion-label>${category.name}</ion-label>
                  <ion-icon name="thumbs-up-sharp" class="ion-float-right left10px` + (category.ignored ? ' ion-hide' : '') + `"></ion-icon>
                </ion-badge>`);
          }
        }

        options.html_array.push(`
              </p>`);
      }
    });

  };
})(jQuery);