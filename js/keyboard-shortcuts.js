(function($) {
  var
    $doc = $(document),
    $select_all_checkbox;

  $doc.on('app-init', function() {
    $select_all_checkbox = $('#select_all');
    initModule();
  });

  function initModule() {

    // ctrl+a highlight all/none for items
    $doc.on('keydown', function(event) {
      if (event.ctrlKey && !event.altKey && !event.shiftKey && event.keyCode == 'A'.charCodeAt(0)) {
        // don't act if there's an input active
        if (
          !(
            (
              document.activeElement.tagName == 'INPUT' &&
              (
                document.activeElement.type == 'text' ||
                document.activeElement.type == 'number' ||
                document.activeElement.type == 'email' ||
                document.activeElement.type == 'password' ||
                document.activeElement.type == 'tel' ||
                document.activeElement.type == 'url' ||
                document.activeElement.type == 'search'
              )
            )
            ||
            document.activeElement.tagName == 'TEXTAREA'
          )
        ) {
          event.preventDefault();
          $select_all_checkbox.click();
        }
      } else if (event.ctrlKey && !event.altKey && !event.shiftKey && event.keyCode == 'M'.charCodeAt(0)) {
        // CTRL+M for switching simple mode
        $('#simple_mode').click();
      } else if (event.ctrlKey && event.altKey && !event.shiftKey && event.keyCode == 65) {
        // CTRL + ALT + A to add new feed
        $('.add_new_feed_link').click();
      } else if (event.ctrlKey && event.altKey && !event.shiftKey && event.keyCode == 82) {
        // CTRL + ALT + R to refresh content
		    // remove all selected items from old feed
        feedit.resetSelectedItemsCache();

        // update the Select All checkbox state
        $doc.trigger('item-select', [{ 'origin' : 'content_refresh' }]);
        $doc.trigger('refresh-content', [null, null, null, {'first_load': true}]);
      } else if (event.ctrlKey && !event.altKey && !event.shiftKey && event.keyCode == 37) {
        // CTRL + left arrow key to go to a previous feed
        var
          $active_item = feedit.getActiveFeedItem(),
          $prev_item = $active_item.prev('ion-item');
        if ($prev_item.length) {
          $prev_item.click();
        }
      } else if (event.ctrlKey && !event.altKey && !event.shiftKey&& event.keyCode == 39) {
        // CTRL + right arrow key to go to a next feed
        var
          $active_item = feedit.getActiveFeedItem(),
          $next_item = $active_item.next('ion-item.feed_item'); // next item will always be .feed_item if it's a feed
        if ($next_item.length) {
          $next_item.click();
        }
      } else if (event.shiftKey && !event.altKey && (event.keyCode == 189 || event.keyCode == 109)) {
        // (CTRL +) SHIFT + minus key (also on numerical keypad) to train selected articles DOWN
        if ( Object.keys(feedit.getSelectedItemsCache()).length ) {
          $doc.trigger('train-simple-multi', [
            {
              'way' : 'down',
              'dont_refresh_content' : (event.ctrlKey ? true : false),
            }
          ]);
        }
      } else if (event.shiftKey && !event.altKey && (event.keyCode == 187 || event.keyCode == 107)) {
        // (CTRL +) SHIFT + plus key (also on numerical keypad) to train selected articles UP
        if ( Object.keys(feedit.getSelectedItemsCache()).length ) {
          $doc.trigger('train-simple-multi', [
            {
              'way' : 'up',
              'dont_refresh_content' : (event.ctrlKey ? true : false),
            }
          ]);
        }
      } else if (event.shiftKey && !event.altKey && (event.keyCode == 56 || event.keyCode == 106)) {
        // (CTRL +) SHIFT + star key (also on numerical keypad) to mark train selected articles as read
        if ( Object.keys(feedit.getSelectedItemsCache()).length ) {
          $('#multi_mark_read').click();
        }
      } else if (!event.shiftKey && event.altKey && event.ctrlKey && event.keyCode == 73) {
        // CTRL + ALT + I to invert selection
        $doc.trigger('invert-selection');
      } else if (!event.shiftKey && event.altKey && event.ctrlKey && event.keyCode == 96) {
        // CTRL + ALT + 0 (num) to click the Cleanup Trained icon
        $('#feed_cleanup_icon').click();
      } else {
        //console.log(event.ctrlKey, event.altKey, event.shiftKey, event.keyCode);
      }
    });

  };
})(jQuery);