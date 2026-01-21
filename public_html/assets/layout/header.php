<?php
if(!isset($_SESSION["userid"]) && !isset($skip))
{
    setcookie("redirect", "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", time()+3600, '/');
    header("Location: http://".BASEURL."/login");
}

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
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.14.7/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/js/bootstrap-select.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bs-custom-file-input/dist/bs-custom-file-input.js"></script>
    <link rel="apple-touch-icon" sizes="180x180" href="http://<?=BASEURL?>/public_html/assets/img/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="http://<?=BASEURL?>/public_html/assets/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="http://<?=BASEURL?>/public_html/assets/img/favicon-16x16.png">
    <link rel="manifest" href="http://<?=BASEURL?>/public_html/assets/img/site.webmanifest">
    <link rel="mask-icon" href="http://<?=BASEURL?>/public_html/assets/img/safari-pinned-tab.svg" color="#5bbad5">
    <link rel="shortcut icon" href="http://<?=BASEURL?>/public_html/assets/img/favicon.ico">
    <meta name="msapplication-TileColor" content="#da532c">
    <meta name="msapplication-config" content="http://<?=BASEURL?>/public_html/assets/img/browserconfig.xml">
    <meta name="theme-color" content="#ffffff">
    <link rel="stylesheet" href="<?= asset('public_html/assets/layout/header.css') ?>">

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
                <a href="http://<?=BASEURL?>/logout" class="btn btn-primary skip">Tak</a>
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
                <img style="width: 100%;" id="modalimg" src="#"/>
            </div>
        </div>
    </div>
</div>



<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <a class="navbar-brand" href="http://<?=BASEURL?>"><img style="height: 40px" src="http://<?=BASEURL?>/public_html/assets/img/atte2.png" alt="Logo Atte"></a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarSupportedContent">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item">
                <a class="nav-link btn btn-light skip" href="http://<?=BASEURL?>">Strona Główna</a>
            </li>
            <?php if(isset($_SESSION['userid'])) :?>
                <li class="nav-item dropdown dropdown-button">
                    <a class="nav-link dropdown-toggle btn btn-light skip" href="#" id="navbarDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        Formularze
                    </a>
                    <div class="dropdown-menu" aria-labelledby="navbarDropdown">
                        <div class="dropdown-submenu">
                            <a class="dropdown-item dropdown-arrow sub-dropdown" href="#">Produkcja<span class="caret"></span></a>
                            <div class="dropdown-menu bg-light" aria-labelledby="submenu">
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/production/tht">Produkcja THT</a>
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/production/smd">Produkcja SMD</a>
                            </div>
                        </div>
                    </div>
                </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link btn btn-light skip" href="http://<?=BASEURL?>/transfer">Transfer</a>
            </li>
            <?php if(isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] == true) :?>
                <li class="nav-item">
                    <a class="nav-link btn btn-light skip" href="http://<?=BASEURL?>/verification">Weryfikuj</a>
                </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link btn btn-light skip" href="http://<?=BASEURL?>/commissions">Zlecenia</a>
            </li>
            <li class="nav-item">
                <a class="nav-link btn btn-light skip" href="http://<?=BASEURL?>/archive">Archiwum</a>
            </li>
            <li class="nav-item">
                <a class="nav-link btn btn-light skip" href="http://<?=BASEURL?>/warehouse">Magazyn</a>
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
                <li class="nav-item dropdown dropdown-button">
                    <a class="nav-link dropdown-toggle btn btn-light skip" data-toggle="dropdown">Admin</a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="navbarDropdown">
                        <div class="dropdown-submenu dropdown-submenu-left">
                            <a class="dropdown-item dropdown-arrow sub-dropdown" href="#">BOM</a>
                            <div class="dropdown-menu bg-light" style="right: 100%" aria-labelledby="submenu">
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/bom/upload">Wczytaj BOM</a>
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/bom/edit">Edytuj BOM</a>
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/bom/dictionary">Edytuj Słownik</a>
                            </div>
                        </div>
                        <div class="dropdown-submenu dropdown-submenu-left">
                            <a class="dropdown-item dropdown-arrow sub-dropdown" href="#">Profile</a>
                            <div class="dropdown-menu bg-light" style="right: 100%" aria-labelledby="submenu">
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/profiles/edit">Edytuj Profil</a>
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/profiles/groups">Konfiguruj Grupy</a>
                            </div>
                        </div>
                        <div class="dropdown-submenu dropdown-submenu-left">
                            <a class="dropdown-item dropdown-arrow sub-dropdown" href="#">Komponenty</a>
                            <div class="dropdown-menu bg-light" style="right: 100%" aria-labelledby="submenu">
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/components/edit">Dodaj/Edytuj Komponenty</a>
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/components/detect-new-parts">Aktualizuj Parts</a>
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/components/from-orders">Pobierz z Zamówień</a>
                            </div>
                        </div>
                        <div class="dropdown-submenu dropdown-submenu-left">
                            <a class="dropdown-item dropdown-arrow sub-dropdown" href="#">Magazyny</a>
                            <div class="dropdown-menu bg-light" style="right: 100%" aria-labelledby="submenu">
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/magazines/edit">Edytuj Magazyny</a>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div class="dropdown-submenu dropdown-submenu-left">
                            <a class="dropdown-item dropdown-arrow sub-dropdown" href="#"><i class="bi bi-cloud-download"></i> Dane z FlowPin</a>
                            <div class="dropdown-menu bg-light" style="right: 100%" aria-labelledby="submenu">
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/flowpin/sessions">Przeglądaj sesje</a>
                                <a class="dropdown-item bg-light" href="http://<?=BASEURL?>/admin/flowpin/update">Aktualizacja Danych</a>
                            </div>
                        </div>
                        </a>
                    </div>
                </li>
            <?php endif; ?>
            <?php if(isset($_SESSION['userid'])) :?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle btn btn-light skip dropdown-button" data-toggle="dropdown">Profil</a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="navbarDropdown">
                        <a class="dropdown-item" href="http://<?=BASEURL?>/profile">Mój Profil</a>
                        <a class="dropdown-item" href="http://<?=BASEURL?>/profile/warehouse">Mój Magazyn</a>
                        <a class="dropdown-item" href="http://<?=BASEURL?>/profile/devices-produced">Produkowane Urządzenia</a>
                    </div>
                </li>
                <a id="logout" class="nav-link btn btn-secondary skip text-white" href="http://<?=BASEURL?>/logout">Wyloguj</a>
            <?php else: ?>
                <a class="nav-link btn btn-light skip" href="http://<?=BASEURL?>/login">Logowanie</a>
            <?php endif; ?>
        </ul>
    </div>
</nav>
<?php if(isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] == true) :?>
    <div class="text-left" style="position: absolute; top: 70px; float: left; left: 10px; z-index: 9999;">
        <button id="toggleFlowpinUpdate" class="btn btn-primary">Pokaż</button>
        <br>
        <small>aktualizacja z flowpin</small>
        <div id="flowpinUpdate" style="display:none;">
            <div>
                <button class="btn btn-sm btn-primary" id="updateDataFromFlowpin">Pobierz dane z Flowpin.</button>
            </div>
            <small class="bg-light">Data ostatniej aktualizacji: <br><b><span id="flowpinDate"></span></b></small>
            <br>
            <hr>
            <div>
                <button class="btn btn-sm btn-primary" id="sendWarehousesToGS">Wyślij stan magazynowy<br>do Google Sheets.</button>
            </div>
            <small class="bg-light">Data ostatniej aktualizacji: <br><b><span id="GSWarehouseDate"></span></b></small><br>
            <div id="spinnerflowpin" class="spinner-border mt-1 text-center" style="display:none" role="status"></div>
        </div>
    </div>
<?php endif; ?>
<script src="<?= asset('public_html/assets/layout/header.js') ?>"></script>
<script src="<?= asset('public_html/assets/layout/side-menu/menu.js') ?>"></script>
