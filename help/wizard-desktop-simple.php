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
  <img src="img/contents.jpg" class="right10px" />

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
</ion-slide>
<ion-slide>
  <img src="help/wizard-desktop-simple/2.gif" class="right10px" />
  <ion-text>
    <h2><?php echo $lang['First Steps - Adding Feeds']; ?></h2>
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
        <?php echo $lang['click on any of the feeds found to add them to FeedIt']; ?>
      </li>
      <li>
        <?php echo $lang['if you don\'t want to wait for language and feeds detection, choose language manually and press Confirm to add your entered Feed address']; ?>
      </li>
      <li>
        <?php echo $lang['wait a while (up to 5 minutes) for your new Feed to populate']; ?>
      </li>
    </ul>
  </ion-text>
</ion-slide>
<ion-slide>
  <img src="help/wizard-desktop-simple/3.gif" class="right10px" />
  <ion-text>
    <h2><?php echo $lang['Simple Articles Training']; ?></h2>
    <ul class="ion-text-left">
      <li>
        <?php echo $lang['swipe an article left or right to Like or Dislike it']; ?>
      </li>
      <li>
        <?php echo $lang['optionally click the']; ?> <ion-icon name="checkmark-done-outline"></ion-icon> <?php echo $lang['icon next to Feed\'s title to hide articles that you\'ve already trained']; ?>
      </li>
      <li>
        <?php echo $lang['hold down SHIFT and click on an article to highlight it, then release the SHIFT key and keep clicking on other articles you\'d like to highlight or cancel the highlight for']; ?>
      </li>
      <li>
        <?php echo $lang['click the Like or Dislike button at the bottom of page to Like or Dislike all highlighted articles']; ?>
      </li>
      <li>
        <?php echo $lang['swipe a trained article or click any of the bottom page buttons again on to un-train it']; ?>
      </li>
      <li>
        <?php echo $lang['hold down the SHIFT key while swiping article or clicking on bottom page button to make that trained article disappear upon training completion']; ?>
      </li>
    </ul>
  </ion-text>
</ion-slide>
<ion-slide>
  <img src="help/wizard-desktop-simple/4.gif" class="right10px" />
  <ion-text>
    <h2><?php echo $lang['Detailed Articles Training']; ?></h2>
    <ul class="ion-text-left">
      <li>
        <?php echo $lang['double-click an article to enter a detailed training mode']; ?>
      </li>
      <li>
        <?php echo $lang['click on &quot;like&quot; or &quot;dislike&quot; badge if you want an article to be trained in either way or ignore these badges for the system to automatically determine appropriate training']; ?>
      </li>
      <li>
        <?php echo $lang['click on']; ?> <ion-icon name="thumbs-down-sharp"></ion-icon> <?php echo $lang['and']; ?> <ion-icon name="thumbs-up-sharp"></ion-icon> <?php echo $lang['to visibly boost or hide a word, category, author or phrase in all articles for current Feed']; ?>
      </li>
      <li>
        <?php echo $lang['double-click on']; ?> <ion-icon name="thumbs-down-sharp"></ion-icon> <?php echo $lang['or']; ?> <ion-icon name="thumbs-up-sharp"></ion-icon> <?php echo $lang['to make a word, category, author or phrase even more Liked or Disliked (by 1000%)']; ?>
      </li>
      <li>
        <?php echo $lang['click the text of a word, category or author to ignore it from training (use this for words such as "bids" in an auction feed where every article ends with "XY bids")']; ?>
      </li>
      <li>
        <?php echo $lang['click the phrase text to remove a training phrase that you\'ve previously added from the current Feed']; ?>
      </li>
      <li>
        <?php echo $lang['when adding a new training phrase, either select text in title or the excerpt, or click the badge of the new phrase directly to start editing it']; ?>
      </li>
    </ul>
  </ion-text>
</ion-slide>
<ion-slide>
  <img src="help/wizard-desktop-simple/5.gif" class="right10px" />
  <ion-text>
    <h2><?php echo $lang['Sorting by Score']; ?></h2>
    <ul class="ion-text-left">
      <li>
        <?php echo $lang['train some articles, then click on the'] . ' &quot;' . $lang['Sort by Date'] . '&quot; ' . $lang['item in menu and choose'] . ' &quot;' . $lang['Sort by Score'] . '&quot; '.$lang['in the menu that comes up, then click'] . ' ' . $lang['Confirm']; ?>
      </li>
    </ul>
  </ion-text>
</ion-slide>
<ion-slide>
  <img src="help/wizard-desktop-simple/6.gif" class="right10px" />
  <ion-text>
    <h2><?php echo $lang['Assigning Labels']; ?></h2>
    <ul class="ion-text-left">
      <li>
        <?php echo $lang['click on the'] . ' &quot;' . $lang['Manage Labels'] . '&quot; ' . $lang['item in menu to open the Labels Manager']; ?>
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
        <?php echo $lang['hold down the SHIFT key and click one or more articles to highlight them, then click the'] . ' &quot;' . $lang['Assign Labels'] . '&quot; ' . $lang['button at the bottom of page to assign labels to all of the articles highlighted'] ; ?>
      </li>
    </ul>
  </ion-text>
</ion-slide>
<ion-slide>
  <img src="help/wizard-desktop-simple/7.gif" class="right10px" />
  <ion-text>
    <h2><?php echo $lang['Hiding Irrelevant Results']; ?></h2>
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
  </ion-text>
</ion-slide>
<ion-slide>
  <img src="help/wizard-desktop-simple/8.gif" class="right10px" />
  <ion-text>
    <h2><?php echo $lang['Managing Feeds']; ?></h2>
    <ul class="ion-text-left">
      <li>
        <?php echo $lang['right-click on any of your Feeds to bring up a menu with various feed actions']; ?>
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
  </ion-text>
</ion-slide>
<ion-slide>
  <ion-text>
    <h2><?php echo $lang['Keyboard Shortcuts and Tap Gestures']; ?></h2>
    <ul class="ion-text-left">
      <li>
        <?php echo $lang['CTRL+A = highlight/cancel highlighting of all articles on page']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+M = toggle Simple Mode on/off']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+ALT+A = add a new Feed']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+ALT+R = reload content for current Feed']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+LEFT ARROW = go to previous Feed']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+RIGHT ARROW = go to next Feed']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+PLUS = train all highlighted articles as Liked']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+MINUS = train all highlighted articles as Disliked']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+STAR = mark all highlighted articles read']; ?>
      </li>
      <li>
        <?php echo $lang['CTRL+0 (numerical keyboard) = '] . $lang['Hide All Trained Articles']; ?>
      </li>
      <li>
        <?php echo $lang['swipe an article left or right to train it as Liked or Disliked']; ?>
      </li>
      <li>
        <?php echo $lang['double-click an article to enter a detailed training mode']; ?>
      </li>
      <li>
        <?php echo $lang['hold down the SHIFT key and click an article to highlight it']; ?>
      </li>
      <li>
        <?php echo $lang['hold down the SHIFT key while clicking on a badge or bottom page button to make that trained article disappear upon training completion']; ?>
      </li>
    </ul>
  </ion-text>
</ion-slide>