(function($) {
  var
    $doc = $(document),
    $splitter_touch_handles,
    $splitter;

  $doc.on('app-init', function() {
    $splitter_touch_handles = $('.splitter_touch_handle');
    $splitter = $('#splitter');
    initModule();
  });

  function initModule() {

    // make the left menu / content resizable
    $("#main_menu").resizable({
      handleSelector: "#splitter, .splitter_touch_handle",
      resizeHeight: false,
      onDragEnd: function () {
        // save left menu width inside a cookie
        Cookies.set( 'left-menu-width', $('#main_menu').width(), { expires: 365 } );
      }
    });

    // shows a splitter-assisted icon near top of the menu/content split when touch starts on a mobile device
    // which can see and use the actual split-panel design
    $doc.on('touchstart', '.splitter_touch_handle, #splitter', function() {
      // only show if the menu is showing
      if (feedit.leftMenuShowing()) {
        $splitter_touch_handles.css('opacity', 1);
      }
    });

    // hides a splitter-assisted icon near top of the menu/content split when touch ends on a mobile device
    // which can see and use the actual split-panel design
    $doc.on('touchend touchcancel', '.splitter_touch_handle, #splitter', function() {
      // only hide if the menu is showing
      if (feedit.leftMenuShowing()) {
        $splitter_touch_handles.css('opacity', 0);
      }
    });

    // reacts to a menu hide/show change to ensure that our splitter is also in/visible
    // in tandem with that menu
    $doc.on('show-hide-splitter', function(event, action) {
      if (action == 'show') {
        $splitter.removeClass('ion-hide');
      } else {
        $splitter.addClass('ion-hide');
      }
    });
  };
})(jQuery);