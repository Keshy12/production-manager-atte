<?php
// Use vlucas/dotenv to get credentials from .env 
$dotenv = Dotenv\Dotenv::createImmutable(ROOT_DIRECTORY);
$dotenv->load();
define("BASEURL", $_ENV["BASEURL"]);

// if(!isset($_SESSION["userid"]) && !isset($skip))
// {
//     setcookie("redirect", "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", time()+3600, '/');
//     header("Location: http://".BASEURL."/views/login_page.php");
// }
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/css/bootstrap-select.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.14.7/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bs-custom-file-input/dist/bs-custom-file-input.js"></script>
    <link rel="apple-touch-icon" sizes="180x180" href="/atte_ms/img/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/atte_ms/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/atte_ms/img/favicon-16x16.png">
    <link rel="manifest" href="/atte_ms/img/site.webmanifest">
    <link rel="mask-icon" href="/atte_ms/img/safari-pinned-tab.svg" color="#5bbad5">
    <link rel="shortcut icon" href="/atte_ms/img/favicon.ico">
    <meta name="msapplication-TileColor" content="#da532c">
    <meta name="msapplication-config" content="/atte_ms/img/browserconfig.xml">
    <meta name="theme-color" content="#ffffff">
    <link rel="stylesheet" href="http://<?=BASEURL?>/public_html/assets/css/header.css">

</head>
<body>
<!-- Logout -->
<div class="modal fade" id="logoutModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Wyloguj</h5>
        <button type="button" class="close" data-dismiss="modal">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        Czy na pewno chcesz się wylogować?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary skip" data-dismiss="modal">Nie</button>
        <a href="/atte_ms/logout.php" class="btn btn-primary skip">Tak</a>
      </div>
    </div>
  </div>
</div>

<div class="modal fade bd-example-modal-xl" id="imageModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Urządzenie</h5>
        <button type="button" class="close" data-dismiss="modal">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body d-flex justify-content-center">
        <img style="width: 100%;" id="modalimg" src=""/>
      </div>
    </div>
  </div>
</div>



<nav class="navbar navbar-expand-lg navbar-light bg-light">
  <a class="navbar-brand" href="http://<?=BASEURL?>/"><img style="height: 40px" src="http://<?=BASEURL?>/img/atte2.png" alt="Logo Atte"></a>
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navbarSupportedContent">
    <ul class="navbar-nav mr-auto">
      <li class="nav-item">
        <a class="nav-link btn btn-light skip" href="/atte_ms/index.php">Strona Główna</a>
      </li>
      <?php if(isset($_SESSION['userid'])) :?>
          <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle btn btn-light skip" href="#" id="navbarDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          Formularze
          </a>
              <div class="dropdown-menu" aria-labelledby="navbarDropdown">
              <div class="dropdown-submenu">
                <a class="dropdown-item dropdown-arrow test" href="#">Produkcja<span class="caret"></span></a>
                <!-- <a class="dropdown-item" href="/atte_ms/forms/transfer.php">Przekazanie materiałów</a> -->
                <div class="dropdown-menu bg-light" aria-labelledby="submenu">
                  <a class="dropdown-item bg-light" href="/atte_ms/forms/thtform_page.php">Produkcja THT</a>
                  <a class="dropdown-item bg-light" href="/atte_ms/forms/smdform_page.php">Produkcja SMD</a>
                </div>
          </li>
      <?php endif; ?>
      <li class="nav-item">
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/transfer_page.php">Transfer</a>
      </li>
      <?php if(isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] == true) :?>
      <li class="nav-item">
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/verify_page.php">Weryfikuj</a>
      </li>
      <?php endif; ?>
      <li class="nav-item">
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/commissions_page.php">Zlecenia</a>
      </li>
      <li class="nav-item">
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/archive_page.php">Archiwum</a>
      </li>
      <li class="nav-item">
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/magazine_page.php">Magazyn</a>
      </li>
    </ul>
    <ul class="navbar-nav ml-auto">
    <?php if(isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] == true) :?>
      <li class="nav-item dropdown dropleft">
        <a id="showNotifications" data-toggle="dropdown" class="nav-link btn btn-light skip">
        <span class="badge badge-danger" id="notificationCounter"></span><i class="bi bi-bell-fill"></i></a>
        <div id="notificationDropdown" class="dropdown-menu" style="width:500px; max-height: 500px; overflow-y: auto;" aria-labelledby="showNotifications">
        </div>
      </li>
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/admin_page.php">Admin</a>
    <?php endif; ?>
    <?php if(isset($_SESSION['userid'])) :?>
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/profile_page.php">Profil</a>
        <a id="logout" class="nav-link btn btn-secondary skip text-white" href="/atte_ms/logout.php">Wyloguj</a>
    <?php else: ?>
        <a class="nav-link btn btn-light skip" href="/atte_ms/views/login_page.php">Logowanie</a>
    <?php endif; ?>
    </ul>
  </div>
</nav>
<?php if(isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] == true) :?>
<div class="text-right" style="position: absolute; top: 70px; float: right; right: 10px; z-index: 9999;">
    <button id="toggleFlowpinUpdate" class="btn btn-primary">Pokaż</button>
    <br>
    <small>aktualizacja z flowpin</small>
    <div id="flowpinUpdate" style="display:none;">
      <div>
          <button class="btn btn-primary" id="updateDataFromFlowpin">Aktualizuj dane z Flowpin.</button>
      </div>
      <small class="bg-light">Data ostatniej aktualizacji: <br><span id="flowpinDate"></span></small>
      <br>
      <div id="spinnerflowpin" class="spinner-border mt-1 text-center" style="display:none" role="status"></div>
    </div>
</div>
<?php endif; ?>
<script src="<?=BASEURL?>/public_html/assets/js/header.js"></script>