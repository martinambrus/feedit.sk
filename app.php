<?php
require_once "api/bootstrap.php";

// check that we are currently logged-in with an active session
if (!empty($_COOKIE['feedit'])) {
  $session = $mongo->{MONGO_DB_NAME}->sessions->findOne( [ 'auth_hash' => (string) $_COOKIE['feedit'], 'expires' => [ '$gt' => time() ] ] );
  if ( !$session ) {
    // not logged-in
    header( 'Location: logout.php' );
    exit;
  }
} else {
  // no login cookie
  header( 'Location: index.php' . ( !empty($_COOKIE['feedit-in-app']) ? '?app=1' : '') );
  exit;
}

$simple_mode_active = (!empty($_COOKIE['simple-mode']) && $_COOKIE['simple-mode'] == 'true');

// authentication script to load the user data into a global $user variable
require_once "api/authentication.php";
require_once "header.php";
?>
  <input type="hidden" id="session_id" value="<?php echo $_COOKIE['feedit']; ?>" />
  <ion-split-pane content-id="main">

    <?php require_once "left-menu.php"; ?>

    <div id="splitter"<?php if (!empty($_COOKIE['left-menu-visible']) && $_COOKIE['left-menu-visible'] == 'false') { echo ' class="ion-hide"'; } ?>></div>

    <div class="ion-page" id="main">

      <ion-icon class="splitter_touch_handle splitter_touch_handle_right ion-hide-xl-up ion-hide-lg-down" size="large" name="code-outline"></ion-icon>

      <ion-header>
        <ion-toolbar>
          <ion-buttons slot="start">
            <ion-menu-button></ion-menu-button>
          </ion-buttons>
          <ion-title>
            <ion-icon id="menu_show" size="large" name="caret-forward-circle-outline" class="ion-float-left right10px ion-hide-lg-down<?php if (empty($_COOKIE['left-menu-visible']) || $_COOKIE['left-menu-visible'] != 'false') { echo ' ion-hide'; } ?>"></ion-icon>

            <?php

            require_once "functions/functions-content.php";
            cache_labels();

            $filter_labels_value = [];
            if (isset($_COOKIE['filter_labels']) && $_COOKIE['filter_labels']) {
              foreach (explode(',', $_COOKIE['filter_labels']) as $cookie_label) {
                foreach ($cached_labels as $label_data) {
                  if ($label_data->_id == $cookie_label) {
                    $filter_labels_value[] = $label_data->_id;
                  }
                }
              }
            }

            if (count($filter_labels_value)) {
              $filter_labels_value = '[' . implode(',', $filter_labels_value) . ']';
            } else {
              $filter_labels_value = '';
            }
            ?><ion-label class="ion-float-left<?php echo (!count($cached_labels) ? ' no-labels' : ''); ?>" id="active_feed_title_header">
              <span class="ion-float-left"><span class="feed_title_and_count"></span></span>
            </ion-label>
            <ion-badge color="light" class="ion-float-left left10px<?php echo (count($cached_labels) ? '' : ' ion-hide'); ?>" id="labels-select-badge">
              <ion-select value="<?php echo $filter_labels_value; ?>" id="filter_labels" interface="alert" cancel-text="<?php echo $lang['Cancel']; ?>" ok-text="<?php echo $lang['Confirm']; ?>" multiple="true" placeholder="<?php echo $lang['labels']; ?>" class="ion-no-padding labels_dropdown_placeholder_text"><?php
                foreach ($cached_labels as $label_data) {
                  echo '
                <ion-select-option value="' . $label_data->_id . '">' . $label_data->label . '</ion-select-option>';
                }
                ?>
              </ion-select>
            </ion-badge>

            <ion-checkbox class="ion-float-right" id="select_all"></ion-checkbox>
            <ion-icon name="information-circle-outline" class="ion-float-right main_help_icon right5px" onClick="feedit.showHelp(this, 'wizard')" title="<?php echo $lang['How to Use FeedIt']; ?>"></ion-icon>
          </ion-title>
        </ion-toolbar>
      </ion-header>

      <ion-footer>
        <ion-grid id="multiselect_actions" class="ion-hide ion-no-padding">
          <ion-row class="ion-text-center">
            <ion-col class="ion-no-padding" id="multi_vote_up">
              <ion-tab-button>
                <ion-icon name="checkmark-sharp" color="success"></ion-icon>
                <ion-label color="success"><?php echo mb_ucfirst($lang['like']); ?></ion-label>
              </ion-tab-button>
            </ion-col>
            <ion-col class="ion-no-padding" id="multi_vote_down">
              <ion-tab-button>
                <ion-icon name="close-sharp" color="danger"></ion-icon>
                <ion-label color="danger"><?php echo mb_ucfirst($lang['dislike']); ?></ion-label>
              </ion-tab-button>
            </ion-col>
            <ion-col class="ion-no-padding" id="multi_mark_read">
              <ion-tab-button>
                <ion-icon name="remove-circle-outline" color="primary"></ion-icon>
                <ion-label color="primary"><?php echo $lang['Mark as Read']; ?></ion-label>
              </ion-tab-button>
            </ion-col>
            <ion-col class="ion-no-padding" id="multi_train">
              <ion-tab-button>
                <ion-icon name="bar-chart-sharp" color="primary"></ion-icon>
                <ion-label color="primary"><?php echo $lang['Train Selected']; ?></ion-label>
              </ion-tab-button>
            </ion-col>
            <ion-col class="ion-no-padding<?php echo (count($cached_labels) ? '' : ' ion-hide'); ?>" id="multi_labels">
              <ion-tab-button>
                <ion-icon name="pricetag-outline" color="dark"></ion-icon>
                <ion-label color="dark"><?php echo $lang['Assign Labels']; ?></ion-label>
              </ion-tab-button>
            </ion-col>
          </ion-row>
        </ion-grid>
      </ion-footer>

      <ion-content class="ion-padding">
        <div>
          <ion-refresher slot="fixed">
            <ion-refresher-content></ion-refresher-content>
          </ion-refresher
        </div>

        <ion-text id="no_articles" class="ion-text-center ion-hide">
          <h2><?php echo $lang['No articles to show']; ?></h2>
          <p>
            <?php echo $lang['If nothing is showing, you can try adjusting your filters in the menu or labels on top.']; ?>
            <br>
            <?php echo $lang['Unless your feed is brand sparkling new, some hidden articles are definitely waiting for you to read...']; ?>
            <br>
            <br>
            <?php echo $lang['Your current filter is set to &quot;<strong><span id="current_show_filter_value"></span></strong>&quot; and you are filtering results by <strong><span id="number_labels_selected">0</span></strong> label(s)']; ?>
            <span id="labels_filtered_by"></span>
            <br>
            <br>
            <br>
            <br>
            <ion-button id="add_new_feed_intro_button" color="success"><?php echo $lang['Add New Feed']; ?></ion-button>
            <br>
            <ion-button color="dark" onClick="feedit.showHelp(this, 'wizard')" title="<?php echo $lang['How to Use FeedIt']; ?>"><?php echo $lang['How to Use FeedIt']; ?></ion-button>
            <br>
            <br>
            <br>
            <div id="feed_error_info" class="ion-hide">
              <h2><ion-label color="danger"><?php echo $lang['Feed Problems']; ?></ion-label></h2>
              <p class="ion-text-center">
                <ion-label color="danger"><?php echo $lang['Our systems were unable to retrieve data for current Feed.'] . '<br>' . $lang['Latest error reported was']; ?>: <span id="last_feed_error"></span></ion-label>
              </p>
            </ion-text>
          </p>
        </ion-text>

      </ion-content>
    </div>
  </ion-split-pane>

  <script>
    var
      lang_id = '<?php echo LANGUAGE; ?>',
      lang = {
      'loading' : '<?php echo $lang['Loading'] ?>...',
      'remove_feed' : '<?php echo $lang['Remove Feed'] ?>',
      'remove_feed_question' : '<?php echo $lang['Do you want to remove the feed'] ?>',
      'cancel' : '<?php echo $lang['Cancel'] ?>',
      'confirm' : '<?php echo $lang['Confirm'] ?>',
      'send' : '<?php echo $lang['Send'] ?>',
      'article_training' : '<?php echo $lang['Article Training'] ?>',
      'training_successful' : '<?php echo $lang['Training successful'] ?>',
      'how_to_train_article' : '<?php echo $lang['How to Train an Article'] ?>',
      'assign_labels' : '<?php echo $lang['Assign Labels'] ?>',
      'articles' : '<?php echo $lang['Articles'] ?>',
      'like' : '<?php echo $lang['like'] ?>',
      'Like' : '<?php echo $lang['Like'] ?>',
      'dislike' : '<?php echo $lang['dislike'] ?>',
      'Dislike' : '<?php echo $lang['Dislike'] ?>',
      'labels' : '<?php echo $lang['labels'] ?>',
      'labels_camelcase' : '<?php echo mb_ucfirst($lang['labels']) ?>',
      'word_ignored_from_scoring' : '<?php echo $lang['word is excluded from article scoring'] ?>',
      'item_will_be_removed' : '<?php echo $lang['item will be removed'] ?>',
      'author_ignored_from_scoring' : '<?php echo $lang['author is excluded from article scoring'] ?>',
      'category_ignored_from_scoring' : '<?php echo $lang['category is excluded from article scoring'] ?>',
      'article_marked_interesting' : '<?php echo $lang['Article marked as interesting'] ?>',
      'article_marked_uninteresting' : '<?php echo $lang['Article marked as uninteresting'] ?>',
      'articles_marked_interesting' : '<?php echo $lang['Articles marked as interesting'] ?>',
      'articles_marked_uninteresting' : '<?php echo $lang['Articles marked as uninteresting'] ?>',
      'feeds_url' : '<?php echo $lang['Feeds URL'] ?>',
      'feeds_found' : '<?php echo $lang['Feeds Found'] ?>',
      'how_to_add_a_feed' : '<?php echo $lang['How to Add a Feed'] ?>',
      'how_to_edit_a_feed' : '<?php echo $lang['How to Edit a Feed'] ?>',
      'add_new_feed' : '<?php echo $lang['Add New Feed'] ?>',
      'report_a_problem' : '<?php echo $lang['Report a Problem'] ?>',
      'problem_type' : '<?php echo $lang['Type of Problem'] ?>',
      'technical_problem' : '<?php echo $lang['Technical Problem'] ?>',
      'account_problem' : '<?php echo $lang['Account Problem'] ?>',
      'problem_description' : '<?php echo $lang['Problem Description'] ?>',
      'describe_problem_in_steps' : '<?php echo $lang['please describe the problem and steps that lead to it'] ?>',
      'problem_report_sent' : '<?php echo $lang['Problem successfully reported. Thank you!'] ?>',
      'article_training_reset' : '<?php echo $lang['Article training was reset'] ?>',
      'bookmark_article' : '<?php echo $lang['bookmark article'] ?>',
      'remove_from_bookmarks' : '<?php echo $lang['remove from bookmarks'] ?>',
      'additional_phrase' : '<?php echo $lang['Additional Phrase'] ?>',
      'what_is_custom_phrase' : '<?php echo $lang['What is a custom phrase'] ?>',
      'cancel_adding_a_phrase' : '<?php echo $lang['Cancel Adding New Phrase'] ?>',
      'select_phrase_from_article' : '<?php echo $lang['select text or tap here'] ?>',
      'show_training_ui' : '<?php echo $lang['show training interface'] ?>',
      'new_phrases_need_rating' : '<?php echo $lang['New phrases need rating'] ?>',
      'close' : '<?php echo $lang['Close'] ?>',
      'ok' : '<?php echo $lang['OK'] ?>',
      'words' : '<?php echo $lang['Words'] ?>',
      'author' : '<?php echo $lang['Author'] ?>',
      'categories' : '<?php echo $lang['Categories'] ?>',
      'phrases' : '<?php echo $lang['Phrases'] ?>',
      'feed_lang' : '<?php echo $lang['Feed Language'] ?>',
      'select_feed_lang' : '<?php echo $lang['Select Feed Language'] ?>',
      'use_manual_priority' : '<?php echo $lang['Use Manual Prioritization'] ?>',
      'words' : '<?php echo $lang['words'] ?>',
      'numbers' : '<?php echo $lang['numbers'] ?>',
      'measurement_units' : '<?php echo $lang['measurement units'] ?>',
      'manual_priorities_explanation_1' : '<?php echo $lang['This will allow you to make sure that certain constructs, such as words, numbers or measurement units will weight more when scoring an article. With this option off, all everything will be scored equally.'] ?>',
      'manual_priorities_explanation_2' : '<?php echo $lang['Drag and Drop items by their right-hand handle to change their priority. Priority goes from highest to lowest from top to bottom.'] ?>',
      'what_is_manual_prioritization' : '<?php echo $lang['What is Manual Prioritization'] ?>',
      'required' : '<?php echo $lang['required'] ?>',
      'manage_labels' : '<?php echo $lang['Manage Labels'] ?>',
      'manage_labels_help' : '<?php echo $lang['Manage Labels'] ?>',
      'labels_for_feed' : '<?php echo $lang['Labels for Feed'] ?>',
      'select_feed' : '<?php echo $lang['Select a Feed'] ?>',
      'add_new_label' : '<?php echo $lang['Add Label'] ?>',
      'saving_data' : '<?php echo $lang['Saving data'] ?>',
      'select_text_or_click_phrase_label' : '<?php echo $lang['Select text in article or click the phrase text'] ?>',
      'phrase_editing' : '<?php echo $lang['Phrase Editing'] ?>',
      'articles_marked_read' : '<?php echo $lang['Articles successfully marked as read'] ?>',
      'feed_marked_read' : '<?php echo $lang['Feed successfully marked as read'] ?>',
      'change_language' : '<?php echo $lang['Change Language'] ?>',
      'ajax_error_maintenance' : '<?php echo $lang['There was a problem performing the request. Please, try again.'] ?>',
      'ajax_error_reload' : '<?php echo $lang['It seems you may have been logged out. Redirecting to the login page...'] ?>',
      'system_error' : '<?php echo $lang['System error'] ?>',
      'train' : '<?php echo $lang['train'] ?>',
      'previous_page' : '<?php echo $lang['Previous Page'] ?>',
      'next_page' : '<?php echo $lang['Next Page'] ?>',
      'feed_removed' : '<?php echo $lang['Feed was removed'] ?>',
      'mark_feed_read' : '<?php echo $lang['Mark Feed Read'] ?>',
      'insta_fetch_articles' : '<?php echo $lang['Insta-Fetch Articles'] ?>',
      'remove_feed' : '<?php echo $lang['Remove Feed'] ?>',
      'edit_feed' : '<?php echo $lang['Edit Feed Info'] ?>',
      'bookmarking_failed' : '<?php echo $lang['There was a problem updating your bookmarks. Please, try again.'] ?>',
      'available_soon' : '<?php echo $lang['This feature will be available soon'] ?>',
      'feed_title' : '<?php echo $lang['Feed Title'] ?>',
      'feed_updated' : '<?php echo $lang['Feed Successfully Updated'] ?>',
      'labels_updated' : '<?php echo $lang['Labels Updated'] ?>',
      'label_temporary' : '<?php echo $lang['This is a temporary label. Tap on it to make it permanent.'] ?>',
      'reload_content_changed' : '<?php echo $lang['Reload (order changed by training)'] ?>',
      'allow_duplicate_articles' : '<?php echo $lang['Allow Duplicate Articles'] ?>',
      'what_is_allow_duplicate_articles' : '<?php echo $lang['What does Allowing Duplicate Articles do'] ?>',
      'like_all_feed_articles' : '<?php echo $lang['Like All Articles'] ?>',
      'dislike_all_feed_articles' : '<?php echo $lang['Dislike All Articles'] ?>',
      'article_no_img' : '<?php echo $lang['Article has no image'] ?>',
      'hide_all_trained_articles' : '<?php echo $lang['Hide All Trained Articles'] ?>',
      'negative_training_warning' : '<?php echo $lang['Remember to keep marking irrelevant articles as Disliked otherwise FeedIt will not be able to filter them out properly.'] ?>',
      'feed_well_trained_info' : '<?php echo $lang['One of your feeds is now well trained to use the slider to hide irrelevant articles in menu. Feeds this well-trained will be marked in blue color. You are welcome to give the slider a try!'] ?>',
      'multi_feeds_tiers_slider_used_with_untrained_feeds' : '<?php echo $lang['You have used the irrelevant articles hiding slider in Bookmarks or Everything while not all of your Feeds are well-trained yet (i.e. not marked blue). This is not recommended, as some relevant articles might be hidden in this display.'] ?>',
      'tiers_slider_used_with_untrained_feed' : '<?php echo $lang['You have used the irrelevant articles hiding slider for a Feed that is not well-trained yet (i.e. not marked blue). This is not recommended, as some relevant articles might be hidden in this display.'] ?>',
      'simple_mode_controls_differ' : '<?php echo $lang['Simple mode controls are different from full mode ones.'] ?>',
      'learn_more' : '<?php echo $lang['Learn More'] ?>',
    };
  </script>

<?php
require_once "footer.php";