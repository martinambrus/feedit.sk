(function($) {
  var $doc = $(document);

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // set up all AJAX calls to timeout after 30 seconds max
    $.ajaxSetup({
      timeout: 120000,
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      callAPI(url, data, successCallback, errorCallback, alwaysCallback) {
        if (typeof(data) != 'undefined' && data) {
          data.lang = window.lang_id;
        } else {
          data = {
            lang : window.lang_id,
          }
        }

        return $.ajax(url, {
          'method' : 'post',
          'data' : data
        })
        .done(successCallback)
        .fail(function(jqXHR, textStatus, errorThrown ) {
          // close any loading modal we might have on page
          $doc.trigger('hide-loading');

          // no need for action if we've aborted the request
          if (textStatus != 'abort' && (typeof(errorCallback) == 'undefined' || errorCallback !== false)) {
            // in case of an error, a generic error message will be shown
            // and if an error callback is provided, it will be called afterwards
            switch (jqXHR.status) {
              // data validation error - something wrong on our side or possible hack attempt
              case 400 :
                feedit.presentToast({
                  'txt': window.lang.system_error + ': ' + JSON.parse(jqXHR.responseText).error.detail,
                  'duration': 5000
                });
                break;

              // unauthorized - reload the page, which will re-check authorization
              case 401 :
                feedit.presentToast({
                  'txt': window.lang.ajax_error_reload,
                  'duration': 2500,
                  'buttons': [] // no OK button, as we're about to reload the page
                });

                setTimeout(function () {
                  document.location.reload();
                }, 2600);
                break;

              // 404 = possibly a version upgrade
              // 500 = internal server error
              case 404 :
              case 500 :
                feedit.defaultErrorNotification();
                break;

              // same as 404 & 500 errors, for now
              default:
                feedit.defaultErrorNotification();
            }
          }

          if (typeof(errorCallback) == 'function') {
            errorCallback(jqXHR, textStatus, errorThrown);
          }
        })
        .always(function() {
          if (typeof(alwaysCallback) == 'function') {
            alwaysCallback();
          }
        });
      },

      defaultErrorNotification() {
        feedit.presentToast({ 'txt' : window.lang.ajax_error_maintenance, 'duration' : 20000, });
      },
    });

  };
})(jQuery);