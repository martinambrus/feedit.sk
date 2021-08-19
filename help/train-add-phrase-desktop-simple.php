<ion-slide>
  <ion-list>
    <ion-item lines="none">
      <ion-text class="ion-text-center">
        <img src="help/training-phrase.png" class="bordered" />
      </ion-text>
    </ion-item>
    <ion-item lines="none" class="ion-text-center">
      <ion-text>
        <br><br>
        <?php echo $lang['Phrases provide a way to train articles based on not just single words, categories or authors but potentially full, multi-words phrases.']; ?>
        <br><br>
        <?php echo $lang['You can add a new phrase by clicking on the'] . ' ' . $lang['Additional Phrase'] . ' ' . $lang['badge and then either selecting the phrase you want from article\'s title or details, or by clicking on the new phrase label itself and editing the phrase manually.']; ?>
        <br><br>
        <?php echo $lang['Phrases are case-insensitive, so Apple, APPLE, apple, aPPle are all the same to the training.']; ?>
        <br><br>
        <?php echo $lang['Phrases are saved per-feed, so you need to train a phrase for each of your Feed\'s individually, if that same phrase applies to multiple Feeds.']; ?>
        <br><br>
        <?php echo $lang['To remove an existing phrase, simply click on its name to schedule it for deletion. Once you confirm the training, that phrase will be removed from current Feed.']; ?>
      </ion-text>
    </ion-item>
  </ion-list>
</ion-slide>