(function($) {
  var
    $doc = $(document),
    help_opening = false,
    ajax_url_help = 'api/help.php';

  $doc.on('app-init', function() {
    initModule();
  });

  async function presentHelpModal(title, topic) {
    // create the modal with the `modal-page` component
    $doc.trigger('open-modal', [
      await modalController.create({
        component: 'modal-help',
        componentProps: {
          'ajaxURL' : 'help-ajax.php',
          'page_title' : title,
          'topic' : topic,
          'is_desktop' : feedit.isDesktopSized(),
          'simple_mode' : feedit.isSimpleMode(),
          'lang' : window.lang_id,
        },
      })
    ]);

    help_opening = false;
  };

  function initModule() {

    // prepare the help overlay template
    customElements.define('modal-help', class extends HTMLElement {
      connectedCallback() {
        this.innerHTML = `
  <ion-header>
    <ion-toolbar>
      <ion-title>` + feedit.getTopModal().componentProps.page_title + `</ion-title>
      <ion-buttons slot="primary">
        <ion-button onClick="feedit.dismissModal()">
          <ion-icon slot="icon-only" name="close"></ion-icon>
        </ion-button>
      </ion-buttons>
    </ion-toolbar>
  </ion-header>
  <ion-content class="ion-padding" id="help_content">
    <p class="ion-text-center top10px">
      <ion-spinner name="lines"></ion-spinner>
    </p>
  </ion-content>`;

        feedit.callAPI(
          ajax_url_help,
          feedit.getTopModal().componentProps,
          function( response ) {
            $('#help_content').html(response);
          },
          function( response ) {
            var result = JSON.parse(response.responseText);
            $('#help_content').html( result.error.detail );
          }
        );
      }
    });

    // extend the feedit object with these public methods
    $.extend(feedit, {
      showHelp(element, topic) {
        if (help_opening) {
          return;
        }

        help_opening = true;
        $doc.trigger('show-loading');
        presentHelpModal(element.title, topic);
      },

      goToPageIndex( pageIndex ) {
        $('#help-slides')[0].slideTo( pageIndex );
      }
    });

  };
})(jQuery);