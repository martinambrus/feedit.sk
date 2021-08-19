<ion-menu side="start" content-id="main" class="left-menu<?php if (!empty($_COOKIE['left-menu-visible']) && $_COOKIE['left-menu-visible'] == 'false') { echo ' ion-hide'; } ?>" id="main_menu">
  <ion-header>
    <ion-toolbar translucent>
      <ion-title>
        <div class="ion-float-left">
          <?php echo $lang['Menu']; ?>
        </div>
        <ion-icon id="menu_hide" size="large" name="caret-back-circle-outline" class="ion-float-right ion-hide-lg-down"></ion-icon>
        <ion-icon id="menu_hide_mobile" size="large" name="close-sharp" class="ion-float-right ion-hide-lg-up"></ion-icon>
      </ion-title>
    </ion-toolbar>
  </ion-header>
  <ion-content>

    <ion-list class="root_menu">

      <ion-item button class="ion-no-padding main_item feeds_main_item" onclick="feedit.toggleFeedsMenuItem(this)">
        <ion-icon name="newspaper-outline" class="ion-float-left"></ion-icon>
        <ion-label><?php echo $lang['Feeds']; ?></ion-label>
        <ion-icon name="chevron-<?php if (empty($_COOKIE['feeds-menu-open']) || $_COOKIE['feeds-menu-open'] == 'false') { echo 'forward'; } else { echo 'down'; } ?>-outline" slot="end"></ion-icon>
      </ion-item>

      <ion-grid class="ion-no-padding feed_actions_grid<?php if (empty($_COOKIE['feeds-menu-open']) || $_COOKIE['feeds-menu-open'] == 'false') { echo ' ion-hide'; } ?>">
        <ion-row class="ion-text-center">
          <ion-col class="ion-no-padding" id="add_new_feed_btn">
            <ion-tab-button>
              <ion-icon name="add-circle-outline" color="success" size="small" title="<?php echo $lang['Add New Feed']; ?>"></ion-icon>
            </ion-tab-button>
          </ion-col>
          <ion-col class="ion-no-padding" id="remove_feed_btn">
            <ion-tab-button>
              <ion-icon name="remove-circle-outline" color="danger" size="small" title="<?php echo $lang['Remove Feeds']; ?>"></ion-icon>
            </ion-tab-button>
          </ion-col>
          <ion-col class="ion-no-padding" id="reload_feeds_btn">
            <ion-tab-button>
              <ion-icon name="sync-outline" color="primary" size="small" title="<?php echo $lang['Reload Feeds']; ?>"></ion-icon>
            </ion-tab-button>
          </ion-col>
          <ion-col class="ion-no-padding" id="show_images_btn">
            <ion-tab-button>
              <ion-icon name="image-outline" color="<?php echo ((!isset($_COOKIE['show-images']) || $_COOKIE['show-images'] == 1) ? 'success' : 'dark'); ?>" size="small" id="show_images_icon" title="<?php echo $lang['Show / Hide Images']; ?>"></ion-icon>
            </ion-tab-button>
          </ion-col>
          <ion-col class="ion-no-padding" id="sort_feed_btn">
            <ion-tab-button>
              <ion-icon name="swap-vertical-outline" color="dark" size="small" title="<?php echo $lang['Feeds Order']; ?>"></ion-icon>
            </ion-tab-button>
          </ion-col>
        </ion-row>
      </ion-grid>

      <div class="submenu feeds<?php if (empty($_COOKIE['feeds-menu-open']) || $_COOKIE['feeds-menu-open'] == 'false') { echo ' ion-hide'; } ?>">
        <ion-list>
          <ion-item button class="ion-no-border all_feeds_item<?php if (empty($_COOKIE['feed-active']) || $_COOKIE['feed-active'] == 'all') { echo ' active'; } ?>" lines="none" title="<?php echo $lang['Everything']; ?>" data-id="all">
            <ion-label>
              <h3>
                <ion-icon name="pulse-outline" color="dark" class="ion-float-left menu_all_feeds"></ion-icon>
                <span><?php echo $lang['Everything']; ?></span>
              </h3>
            </ion-label>
            <ion-badge slot="end" color="none" class="links_counter">
              <!-- <ion-badge color="success">54</ion-badge> //-->
              <ion-badge color="light">0</ion-badge>
            </ion-badge>
            <ion-spinner name="lines-small" slot="end" class="ion-hide"></ion-spinner>
          </ion-item>
          <ion-item button class="ion-no-border bookmarks_item<?php if (!empty($_COOKIE['feed-active']) && $_COOKIE['feed-active'] == 'bookmarks') { echo ' active'; } ?>" lines="none" title="<?php echo $lang['Bookmarks']; ?>" data-id="bookmarks">
            <ion-label>
              <h3>
                <ion-icon name="star" color="warning" class="ion-float-left menu_bookmarks"></ion-icon>
                <span><?php echo $lang['Bookmarks']; ?></span>
              </h3>
            </ion-label>
            <ion-badge slot="end" color="none" class="links_counter">
              <!-- <ion-badge color="success">54</ion-badge> //-->
              <ion-badge color="light">0</ion-badge>
            </ion-badge>
            <ion-spinner name="lines-small" slot="end" class="ion-hide"></ion-spinner>
          </ion-item>
          <ion-item button title="<?php echo $lang['Add New Feed']; ?>" class="add_new_feed_link ion-hide">
            <ion-icon name="add-circle-outline" slot="start"></ion-icon>
            <ion-label class="left5px">
              <h3>
                <?php echo $lang['Add New Feed']; ?>
              </h3>
            </ion-label>
          </ion-item>
          <ion-item button title="<?php echo $lang['Remove Feeds']; ?>" class="ion-hide remove_feed_item">
            <ion-toggle id="remove_feeds" class="ion-float-left remove_feed_link"></ion-toggle>
            <ion-label id="remove_feeds_label" class="top10px" onclick="$('#remove_feeds').click()">
              <h3>
                <?php echo $lang['Remove Feeds']; ?>
              </h3>
            </ion-label>
          </ion-item>
          <ion-item button title="<?php echo $lang['Reload Feeds']; ?>" class="reload_feeds_link ion-hide">
            <ion-icon name="sync-outline" slot="start"></ion-icon>
            <ion-label class="left5px">
              <h3>
                <?php echo $lang['Reload Feeds']; ?>
              </h3>
            </ion-label>
          </ion-item>
          <ion-item button title="<?php echo $lang['Show / Hide Images']; ?>" class="show_images_link ion-hide">
            <ion-icon name="image-outline" slot="start"></ion-icon>
            <ion-label class="left5px">
              <h3>
                <?php echo $lang['Show / Hide Images']; ?>
              </h3>
            </ion-label>
          </ion-item>
          <ion-item title="<?php echo $lang['Feeds Order']; ?>" class="feeds_sort_select ion-hide">
            <ion-icon name="swap-vertical-outline" class="ion-float-left right0px"></ion-icon>
            <ion-select id="feeds_sort" class="sort_feed_link" value="<?php echo (!empty($_COOKIE['feeds_sort']) ? $_COOKIE['feeds_sort'] : 'name'); ?>" interface="alert" cancel-text="<?php echo $lang['Cancel']; ?>" ok-text="<?php echo $lang['Confirm']; ?>">
              <ion-select-option value="name"><?php echo $lang['Order Feeds by Name']; ?></ion-select-option>
              <ion-select-option value="unread"><?php echo $lang['Order Feeds by Unread']; ?></ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item class="ion-no-border main_item divider" lines="none"></ion-item>
        </ion-list>
      </div>

      <?php
        $filter_status_txt = $lang['Show unread'];

        if (!empty($_COOKIE['filter_status'])) {
          switch ($_COOKIE['filter_status']) {
            case 'unread' :   $filter_status_txt = $lang['Show unread'];
                              break;

            case 'untrained': $filter_status_txt = $lang['Show untrained'];
                              break;

            case 'all':       $filter_status_txt = $lang['Show all'];
                              break;
          }
        }
      ?><ion-item class="ion-no-padding" title="<?php echo $filter_status_txt; ?>">
        <ion-icon name="book-outline" class="ion-float-left right0px"></ion-icon>
        <ion-select id="filter_status" value="<?php echo (!empty($_COOKIE['filter_status']) ? $_COOKIE['filter_status'] : 'unread'); ?>" interface="alert" cancel-text="<?php echo $lang['Cancel']; ?>" ok-text="<?php echo $lang['Confirm']; ?>">
          <ion-select-option value="unread"><?php echo $lang['Show unread']; ?></ion-select-option>
          <ion-select-option value="untrained"><?php echo $lang['Show untrained']; ?></ion-select-option>
          <ion-select-option value="trained_pos"><?php echo $lang['Show trained positively']; ?></ion-select-option>
          <ion-select-option value="all"><?php echo $lang['Show all']; ?></ion-select-option>
        </ion-select>
      </ion-item>

      <?php
      $filter_sort_txt = $lang['Sort by Score'];

      if (!empty($_COOKIE['filter_sort'])) {
        switch ($_COOKIE['filter_sort']) {
          case 'score' : $filter_sort_txt = $lang['Sort by Score'];
                         break;

          case 'date':   $filter_sort_txt = $lang['Sort by Date'];
                         break;
        }
      }
      ?><ion-item class="ion-no-padding" title="<?php echo $filter_sort_txt; ?>">
        <ion-icon name="stats-chart" class="ion-float-left right0px"></ion-icon>
        <ion-select id="filter_sort" value="<?php echo (!empty($_COOKIE['filter_sort']) ? $_COOKIE['filter_sort'] : 'date'); ?>" interface="alert" cancel-text="<?php echo $lang['Cancel']; ?>" ok-text="<?php echo $lang['Confirm']; ?>">
          <ion-select-option value="score"><?php echo $lang['Sort by Score']; ?></ion-select-option>
          <ion-select-option value="date"><?php echo $lang['Sort by Date']; ?></ion-select-option>
        </ion-select>
      </ion-item>

      <ion-item class="ion-no-padding" title="<?php echo $lang['Articles Per Page']; ?>">
        <ion-icon name="documents-outline" class="ion-float-left right0px"></ion-icon>
        <ion-select id="filter_per_page" value="<?php echo (!empty($_COOKIE['filter_per_page']) ? $_COOKIE['filter_per_page'] : '15'); ?>" interface="alert" cancel-text="<?php echo $lang['Cancel']; ?>" ok-text="<?php echo $lang['Confirm']; ?>">
          <ion-select-option value="5">5 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="10">10 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="15">15 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="25">25 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="50">50 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="100">100 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="150">150 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="200">200 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
          <ion-select-option value="250">250 <?php echo $lang['Articles Per Page']; ?></ion-select-option>
        </ion-select>
      </ion-item>

      <ion-item class="ion-no-padding" title="<?php echo $lang['Hide Non-Interesting Articles']; ?>">
        <ion-icon name="color-wand-outline" class="ion-float-left right0px"></ion-icon>
        <ion-range id="filter_hiding" min="1" max="5" step="1" snaps="true" value="<?php echo (!empty($_COOKIE['filter_hiding']) ? $_COOKIE['filter_hiding'] : '1'); ?>">
          <ion-icon size="small" slot="start" name="close-sharp" color="primary"></ion-icon>
          <ion-icon size="small" slot="end" name="checkmark-sharp" color="primary"></ion-icon>
        </ion-range>
      </ion-item>

      <ion-item button class="ion-no-padding ion-hide labels_management" onclick="feedit.showLabelsManager()">
        <ion-icon name="pricetag-outline" class="ion-float-left"></ion-icon>
        <ion-label><?php echo $lang['Manage Labels']; ?></ion-label>
      </ion-item>

      <ion-item button class="ion-no-padding" title="<?php echo $lang['Simple Mode']; ?>">
        <ion-toggle id="simple_mode" class="ion-float-left"<?php if ($simple_mode_active)  { echo ' checked'; } ?>></ion-toggle>
        <ion-label id="simple_mode_label" class="top10px" onclick="$('#simple_mode').click()"><?php echo $lang['Simple Mode']; ?></ion-label>
      </ion-item>

      <ion-item class="ion-no-padding" button onclick="feedit.changeLanguage()">
        <ion-icon name="language-outline" class="ion-float-left"></ion-icon>
        <ion-label><?php echo $lang['Change Language']; ?></ion-label>
      </ion-item>

      <ion-item class="ion-no-padding" button onclick="feedit.sendBugReport()">
        <ion-icon name="bug-outline" class="ion-float-left"></ion-icon>
        <ion-label><?php echo $lang['Report a Problem']; ?></ion-label>
      </ion-item>

      <ion-item class="ion-no-padding" button onclick="document.location.href = 'logout.php'">
        <ion-icon name="exit-outline" class="ion-float-left"></ion-icon>
        <ion-label><?php echo $lang['Log Out']; ?></ion-label>
      </ion-item>

    </ion-list>

  </ion-content>

  <!--<ion-footer class="ion-text-center ion-padding">
    <ion-searchbar animated placeholder="Search" debounce="1500" show-cancel-button="focus" cancel-button-text="Custom Cancel"></ion-searchbar>
  </ion-footer>-->

  <ion-icon class="splitter_touch_handle splitter_touch_handle_left ion-hide-xl-up ion-hide-lg-down" size="large" name="code-outline"></ion-icon>
</ion-menu>