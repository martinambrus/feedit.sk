<ion-slide>
  <ion-grid class="ion-no-padding">
    <ion-row class="ion-text-center">
      <ion-col class="ion-no-padding">
        <img src="help/training.png" class="bordered" />
      </ion-col>
    </ion-row>
    <ion-row class="ion-text-left">
      <ion-col class="ion-no-padding">
        <ul class="ion-text-left">
          <li>
            <?php echo $lang['tap the'];?> <ion-badge><ion-icon name="bar-chart-sharp" class="ion-float-left"></ion-icon><ion-text><small> <?php echo $lang['train']; ?></small></ion-text></ion-badge> <?php echo $lang['badge to enter a detailed training mode']; ?>
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