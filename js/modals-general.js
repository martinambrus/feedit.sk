(function($) {
  var
    $doc = $(document),
    modalElements = [],
    popOverElements = [];

  $doc.on('app-init', function() {
    initModule();
  });

  function initModule() {

    // push an item into the popOverElements array,
    // so it can be dismissed via modal close button
    $doc.on('open-popover', function(event, popOverElement) {
      popOverElements.push(popOverElement);
      popOverElement.present();
    });

    // push an item into the modalElement array,
    // so it can be dismissed via modal close button
    $doc.on('open-modal', function(event, modalElement, callback) {
      modalElements.push(modalElement);
      modalElement.present().then(function() {
        $doc.trigger('hide-loading');

        if (typeof(callback) == 'function') {
          callback();
        }
      });
    });

    // dismiss the topmost popover showing
    $doc.on('dismiss-popover', function() {
      if (popOverElements.length) {
        var popover_content_id = $('.popover-wrapper ion-content').last().attr('id');
        popOverElements[popOverElements.length - 1].dismiss({
          'id': popover_content_id
        });
      }
    });

    // dismiss the topmost modal showing
    $doc.on('dismiss-modal', function() {
      if (modalElements.length) {
        var modal_content_id = $('.modal-wrapper ion-content').last().attr('id');
        modalElements[modalElements.length - 1].dismiss({
          'id': modal_content_id
        });
      }
    });

    // remove last popover from the stack on modal dismiss
    $doc.on('ionPopoverDidDismiss', function() {
      popOverElements.pop();
    });

    // remove last modal from the stack on modal dismiss
    $doc.on('ionModalDidDismiss', function() {
      modalElements.pop();
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      dismissPopover() {
        $doc.trigger('dismiss-popover');
      },

      dismissModal() {
        $doc.trigger('dismiss-modal');
      },

      getTopPopover() {
        if (popOverElements.length) {
          return popOverElements[ popOverElements.length - 1 ];
        } else {
          return null;
        }
      },

      getTopModal() {
        if (modalElements.length) {
          return modalElements[ modalElements.length - 1 ];
        } else {
          return null;
        }
      },
    });

  };
})(jQuery);