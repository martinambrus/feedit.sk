<?php
  require_once "authentication.php";
  require_once "../lang/locales.php";

  if (empty($_POST['topic'])) {
    send_error($lang['Missing parameter'], $lang['Help Topic Missing'], 400, 'validation', ['api' => 'help', 'field' => 'topic']);
  } else {
    $_POST['topic'] = filter_filename( (string) $_POST['topic'] );
  }

  if (empty($_POST['is_desktop']) || ($_POST['is_desktop'] !== 'true' && $_POST['is_desktop'] !== 'false')) {
    send_error($lang['Missing parameter'], $lang['Identifier Missing'], 400, 'validation', ['api' => 'help', 'field' => 'is_desktop']);
  }

  if (empty($_POST['simple_mode']) && ($_POST['simple_mode'] !== 'true' && $_POST['simple_mode'] !== 'false')) {
    send_error($lang['Missing parameter'], $lang['Simple Mode Identifier Missing'], 400, 'validation', ['api' => 'help', 'field' => 'simple_mode']);
  }

  $fname = '../help/' . $_POST['topic'] . '-' . ($_POST['is_desktop'] === 'true' ? 'desktop' : 'mobile') . '-' . ($_POST['simple_mode'] === 'true' ? 'simple' : 'full') . '.php';
  if (!file_exists($fname)) {
    send_error($lang['System Error'], $lang['Help File Not Found'] . ' (' . $_POST['topic'] . '-' . ($_POST['is_desktop'] === 'true' ? 'desktop' : 'mobile') . '-' . ($_POST['simple_mode'] === 'true' ? 'simple' : 'full') . ')', 404, 'help', ['api' => 'help', 'file' => $fname]);
  }

  header('Content-Type: text/html; charset=utf-8');
  echo '<ion-slides id="help-slides">';
  include $fname;
  echo '</ion-slides>';