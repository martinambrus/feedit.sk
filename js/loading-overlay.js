(function($) {
  var
    $doc = $(document),
    loading_overlay,
    is_showing = false;

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // present a loading overlay on top of the whole app
    $doc.on('show-loading', function(event, title, custom_time) {
      if (is_showing) {
        return;
      }

      is_showing = true;
      loading_overlay = document.createElement('ion-loading');

      //loading.cssClass = 'my-custom-class';
      loading_overlay.message = (typeof(title) != 'undefined' ? title : window.lang.loading);

      // only use duration with custom time
      if (typeof(custom_time) != 'undefined') {
        loading_overlay.duration = custom_time;
      }

      document.body.appendChild(loading_overlay);

      loading_overlay.present().then(function() {
        const { role, data } = loading_overlay.onDidDismiss().then(function() {
          //console.log('Loading dismissed!');
        });
      });
    });

    // hides the currently shown loading overlay
    $doc.on('hide-loading', function() {
      is_showing = false;
      loading_overlay.dismiss();
    });
  };
})(jQuery);