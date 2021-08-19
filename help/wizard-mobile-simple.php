<ion-slide>
  <ion-list>
    <ion-item lines="none">
      <ion-text>
        <img src="img/swipeleft.gif" />
      </ion-text>
    </ion-item>
    <ion-item lines="none" class="ion-text-center">
      <ion-text>
        <?php echo $lang['Swipe left to go to content']; ?> &raquo;
      </ion-text>
    </ion-item>
  </ion-list>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="img/contents.jpg" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ol>
          <li>
            <a href="javascript:feedit.goToPageIndex(2)"><?php echo $lang['First Steps - Adding Feeds']; ?></a>
          </li>
          <li>
            <a href="javascript:feedit.goToPageIndex(3)"><?php echo $lang['Simple Articles Training']; ?></a>
          </li>
          <li>
            <a href="javascript:feedit.goToPageIndex(4)"><?php echo $lang['Detailed Articles Training']; ?></a>
          </li>
          <li>
            <a href="javascript:feedit.goToPageIndex(5)"><?php echo $lang['Sorting by Score']; ?></a>
          </li>
          <li>
            <a href="javascript:feedit.goToPageIndex(6)"><?php echo $lang['Assigning Labels']; ?></a>
          </li>
          <li>
            <a href="javascript:feedit.goToPageIndex(7)"><?php echo $lang['Hiding Irrelevant Results']; ?></a>
          </li>
          <li>
            <a href="javascript:feedit.goToPageIndex(8)"><?php echo $lang['Managing Feeds']; ?></a>
          </li>
          <li>
            <a href="javascript:feedit.goToPageIndex(9)"><?php echo $lang['Keyboard Shortcuts and Tap Gestures']; ?></a>
          </li>
        </ol>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <?php echo $lang['First Steps - Adding Feeds']; ?>
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/wizard-mobile-simple/2.gif" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['use the Add New Feed button or the']; ?> <ion-icon name="add-circle-outline" color="success"></ion-icon> <?php echo $lang['button in Feeds submenu']; ?>
          </li>
          <li>
            <?php echo $lang['enter the web address of your Feed or the website you want to check for feeds']; ?>
          </li>
          <li>
            <?php echo $lang['either wait for the dialog to auto-detect language of your Feed or select it manually']; ?>
          </li>
          <li>
            <?php echo $lang['double-check detected language']; ?>
          </li>
          <li>
            <?php echo $lang['tap on any of the feeds found to add them to FeedIt']; ?>
          </li>
          <li>
            <?php echo $lang['if you don\'t want to wait for language and feeds detection, choose language manually and press Confirm to add your entered Feed address']; ?>
          </li>
          <li>
            <?php echo $lang['wait a while (up to 5 minutes) for your new Feed to populate']; ?>
          </li>
        </ul>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <?php echo $lang['Simple Articles Training']; ?>
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/wizard-mobile-simple/3.gif" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['swipe an article left or right to Like or Dislike it']; ?>
          </li>
          <li>
            <?php echo $lang['optionally tap the']; ?> <ion-icon name="checkmark-done-outline"></ion-icon> <?php echo $lang['icon next to Feed\'s title to hide articles that you\'ve already trained']; ?>
          </li>
          <li>
            <?php echo $lang['long-press on an article to highlight it, then keep clicking on other articles you\'d like to highlight or cancel the highlight for']; ?>
          </li>
          <li>
            <?php echo $lang['tap the Like or Dislike button at the bottom of page to Like or Dislike all highlighted articles']; ?>
          </li>
          <li>
            <?php echo $lang['swipe a trained article or click any of the bottom page buttons again on to un-train it']; ?>
          </li>
          <li>
            <?php echo $lang['long-press while clicking on bottom page button to make highlighted trained articles disappear upon training completion']; ?>
          </li>
        </ul>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <?php echo $lang['Detailed Articles Training']; ?>
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/wizard-mobile-simple/4.gif" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['double-tap an article to enter a detailed training mode']; ?>
          </li>
          <li>
            <?php echo $lang['tap on &quot;like&quot; or &quot;dislike&quot; badge if you want an article to be trained in either way or ignore these badges for the system to automatically determine appropriate training']; ?>
          </li>
          <li>
            <?php echo $lang['tap on']; ?> <ion-icon name="thumbs-down-sharp"></ion-icon> <?php echo $lang['and']; ?> <ion-icon name="thumbs-up-sharp"></ion-icon> <?php echo $lang['to visibly boost or hide a word, category, author or phrase in all articles for current Feed']; ?>
          </li>
          <li>
            <?php echo $lang['long-press on']; ?> <ion-icon name="thumbs-down-sharp"></ion-icon> <?php echo $lang['or']; ?> <ion-icon name="thumbs-up-sharp"></ion-icon> <?php echo $lang['to make a word, category, author or phrase even more Liked or Disliked (by 1000%)']; ?>
          </li>
          <li>
            <?php echo $lang['tap the text of a word, category or author to ignore it from training (use this for words such as "bids" in an auction feed where every article ends with "XY bids")']; ?>
          </li>
          <li>
            <?php echo $lang['tap the phrase text to remove a training phrase that you\'ve previously added from the current Feed']; ?>
          </li>
          <li>
            <?php echo $lang['when adding a new training phrase, either select text in title or the excerpt, or tap the badge of the new phrase directly to start editing it']; ?>
          </li>
        </ul>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <?php echo $lang['Sorting by Score']; ?>
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/wizard-mobile-simple/5.gif" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['train some articles, then tap on the'] . ' &quot;' . $lang['Sort by Date'] . '&quot; ' . $lang['item in menu and choose'] . ' &quot;' . $lang['Sort by Score'] . '&quot; '.$lang['in the menu that comes up, then tap on'] . ' ' . $lang['Confirm']; ?>
          </li>
        </ul>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <?php echo $lang['Assigning Labels']; ?>
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/wizard-mobile-simple/6.gif" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['tap on the'] . ' &quot;' . $lang['Manage Labels'] . '&quot; ' . $lang['item in menu to open the Labels Manager']; ?>
          </li>
          <li>
            <?php echo $lang['current Feed will be auto-selected, unless you\'re browsing'] . ' ' . $lang['Bookmarks'] . ' ' . $lang['or'] . ' ' . $lang['Everything']; ?>
          </li>
          <li>
            <?php echo $lang['you can add, remove or rename labels for the selected Feed in this view']; ?>
          </li>
          <li>
            <?php echo $lang['please note that you will need to save changes for each Feed individually, as changing feeds in the middle of labels editing will cancel your previous changes']; ?>
          </li>
          <li>
            <?php echo $lang['a new dropdown with all labels will become available next to the Feed\'s title (badge titled labels on top of page)'] ; ?>
          </li>
          <li>
            <?php echo $lang['use the top labels badge to only show articles with the selected labels assigned']; ?>
          </li>
          <li>
            <?php echo $lang['long-tap on an article to highlight it, then after all desired articles are highlighted, tap the'] . ' &quot;' . $lang['Assign Labels'] . '&quot; ' . $lang['button at the bottom of page to assign labels to all of the articles highlighted'] ; ?>
          </li>
        </ul>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <?php echo $lang['Hiding Irrelevant Results']; ?>
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/wizard-mobile-simple/7.gif" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['use the slider in menu to hide articles that are under a certain training/scoring threshold']; ?>
          </li>
          <li>
            <?php echo $lang['this option will need some amount of previous training to be completed for the Feed in question - it is recommended that you train at least 200 articles before using this filter or you may end up with interesting articles being hidden from you']; ?>
          </li>
          <li>
            <?php echo $lang['if the current Feed isn\'t trained well enough, you will be notified']; ?>
          </li>
          <li>
            <?php echo $lang['you will also be notified as soon as one of your feeds becomes trained enough for this option to be used safely']; ?>
          </li>
        </ul>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <?php echo $lang['Managing Feeds']; ?>
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/wizard-mobile-simple/8.gif" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['long-press on any of your Feeds to bring up a menu with various feed actions']; ?>
          </li>
          <li>
            <?php echo $lang['this menu will allow you to train a whole Feed in one click, mark everything read, update that Feed\'s details and remove it']; ?>
          </li>
          <li>
            <?php echo $lang['this menu is not available for Bookmarks or Everything items']; ?>
          </li>
          <li>
            <?php echo $lang['you can also use the top button in the Feeds menu to add or remove Feeds, turn showing thumbnails for articles on or off, or choose how your Feeds display is being sorted']; ?>
          </li>
        </ul>
      </ion-col>
    </ion-row>
  </ion-grid>
</ion-slide>
<ion-slide>
  <ion-text>
    <h2><?php echo $lang['Keyboard Shortcuts and Tap Gestures']; ?></h2>
    <ul class="ion-text-left">
      <li>
        <?php echo $lang['swipe an article left or right to train it as Liked or Disliked']; ?>
      </li>
      <li>
        <?php echo $lang['double-tap an article to enter a detailed training mode']; ?>
      </li>
      <li>
        <?php echo $lang['long-press on an article to highlight it, then keep clicking on other articles you\'d like to highlight or cancel the highlight for']; ?>
      </li>
      <li>
        <?php echo $lang['long-press while clicking on bottom page button to make highlighted trained articles disappear upon training completion']; ?>
      </li>
      <li>
        <?php echo $lang['long-press on the Highlight All checkbox to inverse current highlight (i.e. de-select highlighted articles and highlight the rest)']; ?>
      </li>
    </ul>
  </ion-text>
</ion-slide>