<?php
require_once "api/bootstrap.php";
?><!DOCTYPE html>
<html mode="md">
<head>
  <title>FeedIt</title>

  <meta charset="utf-8">
  <meta http-equiv="Content-type" content="text/html; charset=utf-8">

  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <meta name="msapplication-TileImage" content="/img/logo_144.png" />
  <meta name="msapplication-TileColor" content="#FFFFFF"/>

  <meta name="apple-itunes-app" content="app-id=463981119">

  <meta name="author" content="Martin Ambruš" />
  <meta name="description" content="<?php echo $lang['Machine-Learning RSS Reader based on simple user feedback']; ?>" />

  <!-- HandheldFriendly is BlackBerry's -->
  <meta content='True' name='HandheldFriendly' />
  <meta content='width=device-width, initial-scale=1' name='viewport' />
  <meta name="viewport" content="width=device-width" />

  <link rel="shortcut icon" href="favicon.ico" type="image/x-icon" />
  <link rel="icon" href="favicon.ico" type="image/x-icon" />
  <link rel="icon" href="img/logo32.png" sizes="32x32"/>
  <link rel="icon" href="img/logo64.png" sizes="64x64"/>

  <link rel="apple-touch-icon" sizes="57×57" href="img/logo57.png" />
  <link rel="apple-touch-icon" sizes="72×72" href="img/logo72.png" />
  <link rel="apple-touch-icon" sizes="114×114" href="img/logo114.png" />

  <script type="module" src="https://cdn.jsdelivr.net/npm/@ionic/core/dist/ionic/ionic.esm.js"></script>
  <script nomodule src="https://cdn.jsdelivr.net/npm/@ionic/core/dist/ionic/ionic.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ionic/core/css/ionic.bundle.css"/>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jquery-resizable-dom@0.35.0/dist/jquery-resizable.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/js-cookie@2.2.1/src/js.cookie.min.js"></script><?php
    if (defined('DEBUG') && DEBUG) {
      foreach (glob('js/*.js') as $file) {
        if ( $file != 'js/feedit-min.js' ) {
          echo '
  <script src="' . $file . '?' . LAST_UPDATE . '"></script>';
        }
      }
    } else {
      echo '
  <script src="js/feedit-min.js?' . LAST_UPDATE . '"></script>';
    }
  ?>

  <script type="module">
    import { modalController } from 'https://cdn.jsdelivr.net/npm/@ionic/core@next/dist/ionic/index.esm.js';
    import { popoverController } from 'https://cdn.jsdelivr.net/npm/@ionic/core/dist/ionic/index.esm.js';

    window.modalController = modalController;
    window.popoverController = popoverController;
  </script>
  <?php
    echo '
  <link rel="stylesheet" href="css/feedit' . (!defined('DEBUG') || !DEBUG ? '-min' : '') .'.css?' . LAST_UPDATE . '" type="text/css" charset="utf-8">';

    if (!empty($_COOKIE['left-menu-width'])) {
?>
  <style>
    #main_menu {
      --side-width: <?php echo $_COOKIE['left-menu-width']; ?>px;
    }
  </style>
<?php
    }
  ?>

</head>
<body>

  <ion-app>