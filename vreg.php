<?php
require_once "header.php";
?>

  <ion-content class="ion-text-center ion-padding">
    <form id="reg_verify_form" method="get" action="v.php">
      <br>
      <div>
        <ion-label class="top10px ion-float-left"><?php echo $lang['Verification Code from E-Mail']; ?>: &nbsp;</ion-label>
        <ion-input id="id" name="id" type="text" required></ion-input>
        <input type="hidden" name="lang" value="<?php echo (isset($_GET['lang']) ? $_GET['lang'] : ''); ?>" />
      </div>
      <br>
      <ion-button type="submit" data-action="submit" id="verify"><?php echo $lang["Verify"]; ?></ion-button>
    </form>

    <br>

    <ion-badge class="ion-padding" color="success" id="registration_result"><?php echo $lang['Please provide the code sent to you in the verification e-mail, or simply click the link in that e-mail to activate your account in your browser.'] ?></ion-badge>
  </ion-content>

<?php
require_once "footer.php";