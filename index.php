<?php
// make sure we store that we're inside an APP into a cookie,
// so we don't show all the APP links and YouTube video trailer inside it
if ( isset($_GET['app']) ) {
  setcookie('feedit-in-app', 1, time() + (60 * 60 * 24 * 365)); // expires in 1 year
}

// if we're logged-in, redirect to app
if ( !empty($_COOKIE['feedit']) ) {
  header( 'Location: app.php?lang=' . ( !empty($_COOKIE['lang']) ? $_COOKIE['lang'] : 'en' ) );
  exit;
}

require_once "header.php";
?>

    <ion-content class="ion-text-center ion-padding">
      <h1><strong>FeedIt.sk</strong></h1>
      <strong>Machine-Learning RSS Reader based on simple user feedback</strong>

      <br>
      <br>
      <br>

      <ion-grid>
        <ion-row><?php if (!isset($_GET['app'])) { ?>
          <ion-col>
            <iframe width="560" height="315" src="https://www.youtube.com/embed/x4l0ltXHicg" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
          </ion-col>
          <?php } ?>
          <ion-col>
            <form id="reg_form">
              <br>
              <div>
                <ion-label class="top10px ion-float-left"><?php echo $lang['E-Mail Address']; ?>: &nbsp;</ion-label>
                <ion-input id="email" name="email" type="email" required></ion-input>
              </div>
              <br><?php
              require_once "lang/locales.php";
              ?>
              <div class="ion-text-start">
                <ion-label class="top10px"><?php echo $lang['Language']; ?>: &nbsp;</ion-label>
                <ion-select id="lang" cancel-text="<?php echo $lang['Cancel']; ?>" ok-text="<?php echo $lang['Confirm']; ?>" value="<?php echo (!empty($locales[ LANGUAGE ]) ? LANGUAGE : 'en') ?>"><?php
                  foreach ($locales as $locale_key => $locale_data) {
                    echo '<ion-select-option value="' . $locale_key . '">' . $locale_data['name'] . '</ion-select-option>';
                  }
                  ?>
                </ion-select>
              </div>
              <br>
              <div class="ion-text-start">
                <ion-label class="top10px"><?php echo $lang['Timezone']; ?>: &nbsp;</ion-label>
                <ion-select id="timezone" cancel-text="<?php echo $lang['Cancel']; ?>" ok-text="<?php echo $lang['Confirm']; ?>" value="UTC"><?php
                  foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $timezone) {
                    echo '<ion-select-option value="' . $timezone . '">' . $timezone . '</ion-select-option>';
                  }
                  ?>
                </ion-select>
              </div>
              <br>
              <ion-button type="submit" data-action='submit' id="register"><?php echo $lang['Register']; ?></ion-button>
              <ion-button type="submit" data-action='submit' color="success" id="login"><?php echo $lang['Login']; ?></ion-button>
            </form>

            <br>

            <ion-spinner name="lines-small" class="ion-hide"></ion-spinner>
            <ion-badge id="registration_result" class="ion-padding"></ion-badge>
          </ion-col>
        </ion-row><?php if (!isset($_GET['app'])) { ?>
        <ion-row>
          <ion-col>&nbsp;</ion-col>
        </ion-row>
        <ion-row>
          <ion-col>&nbsp;</ion-col>
        </ion-row>
         <ion-row>
          <ion-col>
            <a href='https://play.google.com/store/apps/details?id=sk.feedit&pcampaignid=pcampaignidMKT-Other-global-all-co-prtnr-py-PartBadge-Mar2515-1'><img height="100" alt='<?php echo $lang['Get it on Google Play']; ?>' src='https://play.google.com/intl/en_us/badges/static/images/badges/<?php echo LANGUAGE; ?>_badge_web_generic.png'/></a>
          </ion-col>
          <!--<ion-col>
            <a href="https://apps.apple.com/us/app/feedit-rss-reader/id1538541609?itsct=apps_box&amp;itscg=30200" style="display: inline-block; overflow: hidden; border-radius: 13px; width: 250px; height: 83px;"><img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/<?php /*echo $locales[ LANGUAGE ]['full_id'] */?>?size=250x83&amp;releaseDate=1605571200&h=8bc73f1e1e3a1f2b6ae23c7e7095697c" alt="Download on the App Store" style="border-radius: 13px; width: 250px; height: 83px;"></a>
          </ion-col>-->
        </ion-row>
        <ion-row>
            <ion-col>
                <p>&nbsp;</p>Privacy Policy of Feedit.sk - this application does not collect any personal information or data that could be used to identify a user, does not utilize any tracking methods, ads and the such. Your e-mail used for registration and login purposes serves as a 3-rd party 2-step verification information that is stored encrypted and cannot be read in its original form by anyone, including the site administrators.
            </ion-col>
        </ion-row>
        <?php } ?>
      </ion-grid>
    </ion-content>

    <script src="https://www.google.com/recaptcha/api.js?render=6LcwoBAcAAAAALFySP8EDETXmKB_PPEdanvgx2I2"></script>
    <script type="application/javascript">
      $(function() {
        // set user's timezone
        try {
          var zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
          if (typeof (zone) == 'string') {
            $('#timezone').val(zone);
          }
        } catch (ex) {}
      });

      $('#lang').on('ionChange', function() {
        document.location.href = '?lang=' + $(this).val();
      });

      $('#register').on('click', function() {
        $('#reg_form').addClass('register').removeClass('login');
      });

      $('#login').on('click', function() {
        $('#reg_form').addClass('login').removeClass('register');
      });

      $('#reg_form').on('submit', function() {
        var
          $reg_form = $(this),
          $spinner = $('ion-spinner'),
          $badge = $('#registration_result');

        if (!$reg_form.hasClass('register') && !$reg_form.hasClass('login')) {
          $reg_form.addClass('register');
        }

        // disable buttons
        $('ion-button').attr('disabled', 'disabled');

        // show spinner, hide badge (i.e. any old badge showing)
        $spinner.removeClass('ion-hide');
        $badge.addClass('ion-hide');

        grecaptcha.ready(function() {
          grecaptcha
            .execute('6LcwoBAcAAAAALFySP8EDETXmKB_PPEdanvgx2I2', {action: 'submit'})
            .then(function(token) {
              // verify Re-Captcha
              $.post('api/captcha.php', {
                'token' : token,
                'email' : $('#email').val(),
                'tz' : $('#timezone').val(),
                'lang' : $('#lang').val(),
                'action' : ($('#reg_form').hasClass('register') ? 'reg' : 'log'),
              })
              .done(function( result ) {
                $badge.html('');
                if ( $('#reg_form').hasClass('register') ) {
                  if ( result.response && typeof(result.response.extra) != 'undefined' && typeof(result.response.extra.lang) != 'undefined' ) {
                    document.location.href = 'vreg.php?lang=' + result.response.extra.lang;
                  } else {
                    $badge.html('<?php echo $lang['There was a problem performing the request. Please, try again.']; ?>');
                  }
                } else {
                  if ( result.response && typeof(result.response.extra) != 'undefined' && typeof(result.response.extra.lang) != 'undefined' && typeof(result.response.extra.id) != 'undefined' ) {
                    document.location.href = 'vlog.php?lang=' + result.response.extra.lang + '&id=' + result.response.extra.id;
                  } else {
                    $badge.html('<?php echo $lang['There was a problem performing the request. Please, try again.']; ?>');
                  }
                }
              })
              .fail(function(result) {
                $badge
                  .attr('color', 'danger');
                try {
                  var result = JSON.parse(result.responseText);
                  $badge.html(result.error.detail);
                } catch (ex) {
                  $badge.html('<?php echo $lang['There was a problem performing the request. Please, try again.']; ?>');
                }
              })
              .always(function() {
                // hide spinner, show badge
                $badge
                  .add($spinner)
                  .toggleClass('ion-hide');

                // re-enable buttons
                $('ion-button').removeAttr('disabled');
              });
            })
            .catch(function(err) {
              $badge
                .attr('color', 'danger')
                .html('<?php echo $lang['There was a problem performing the request. Please, try again.']; ?>')
                .add($spinner)
                .toggleClass('ion-hide'); // hide spinner and show badge

              // re-enable buttons
              $('ion-button').removeAttr('disabled');

              console.log('captcha error', err);
            });
        });

        return false;
      });
    </script>

<?php
require_once "footer.php";