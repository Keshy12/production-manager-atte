<?php

$headerDir = 'public_html/assets/layout/header.php';
$request = strtok(str_replace('/atte_ms_new/', '', $_SERVER['REQUEST_URI']), '?');
$componentsDir = ROOT_DIRECTORY .'/public_html/components';

switch ($request) {
    case '':
    case '/':
        includeWithVariables($headerDir, array('title' => 'Strona Główna', 'skip' => true));
        if(isset($_SESSION["userid"])) require $componentsDir . '/index/active-commissions-view.php';
        break;

    case 'production/tht':
        includeWithVariables($headerDir, array('title' => 'Produkcja THT'));
        require $componentsDir . '/production/tht/tht-view.php';
        break;
    case 'production/smd':
        includeWithVariables($headerDir, array('title' => 'Produkcja SMD'));
        require $componentsDir . '/production/smd/smd-view.php';
        break;
    case 'transfer':
        includeWithVariables($headerDir, array('title' => 'Transfer'));
        require $componentsDir . '/transfer/transfer-view.php';
        break;
    case 'verification':
        includeWithVariables($headerDir, array('title' => 'Weryfikacja'));
        require $componentsDir . '/verification/verification-view.php';
        break;
    case 'archive':
        includeWithVariables($headerDir, array('title' => 'Archiwum'));
        require $componentsDir . '/archive/archive-view.php';
        break;
    case 'warehouse':
        includeWithVariables($headerDir, array('title' => 'Magazyn'));
        require $componentsDir . '/warehouse/warehouse-view.php';
        break;
    case 'commissions':
        includeWithVariables($headerDir, array('title' => 'Zlecenia'));
        require $componentsDir . '/commissions/commissions-view.php';
        break;
    case 'notification':
        includeWithVariables($headerDir, array('title' => 'Powiadomienie'));
        require $componentsDir . '/notification/notification-view.php';
        break;
    case 'profile':
        includeWithVariables($headerDir, array('title' => 'Mój Profil'));
        require $componentsDir . '/profile/profile-view.php';
        break;
    case 'profile/warehouse':
        includeWithVariables($headerDir, array('title' => 'Mój Magazyn'));
        require $componentsDir . '/profile/warehouse/warehouse-view.php';
        break;
    case 'profile/devices-produced':
        includeWithVariables($headerDir, array('title' => 'Produkowane Urządzenia'));
        require $componentsDir . '/profile/devicesproduced/devices-produced-view.php';
        break;
    case 'admin/bom/upload':
        includeWithVariables($headerDir, array('title' => 'Wczytywanie BOM'));
        require $componentsDir . '/admin/bom/upload/upload-bom-view.php';
        break;
    case 'admin/bom/edit':
        includeWithVariables($headerDir, array('title' => 'Edycja BOM'));
        require $componentsDir . '/admin/bom/edit/edit-bom-view.php';
        break;
    case 'admin/bom/dictionary':
        includeWithVariables($headerDir, array('title' => 'Edycja słownika ValuePackage'));
        require $componentsDir . '/admin/bom/dictionary/edit-dictionary-view.php';
        break;
    case 'admin/profiles/edit':
        includeWithVariables($headerDir, array('title' => 'Edycja Profili'));
        require $componentsDir . '/admin/profiles/edit/edit-profile-view.php';
        break;
    case 'admin/components/edit':
        includeWithVariables($headerDir, array('title' => 'Edycja Komponentów'));
        require $componentsDir . '/admin/components/edit/edit-component-view.php';
        break;
    case 'admin/components/detect-new-parts':
        includeWithVariables($headerDir, array('title' => 'Aktualizuj Parts'));
        require $componentsDir . '/admin/components/detectnewparts/detect-parts-view.php';
        break;
    case 'admin/components/from-orders':
        includeWithVariables($headerDir, array('title' => 'Pobierz z zamówień'));
        require $componentsDir . '/admin/components/fromorders/from-orders-view.php';
        break;
    case 'admin/magazines/edit':
        includeWithVariables($headerDir, array('title' => 'Edycja Magazynów'));
        require $componentsDir . '/admin/magazines/edit/edit-magazines-view.php';
        break;
    
    case 'test':
//        includeWithVariables($headerDir, array('title' => 'Test'));
        require $componentsDir . '/tests/test1.php';
        break;

    case 'login':
        includeWithVariables($headerDir, array('title' => 'Logowanie', 'skip' => true));
        if(!isset($_SESSION["userid"])) require $componentsDir . '/login/login-view.php';
        else echo '<h1 class="text-center"> Jesteś już zalogowany </h1>';
        break;
    case 'logout':
        require $componentsDir . '/login/logout.php';
        break;

    default:
        includeWithVariables($headerDir, array('title' => 'Nie znaleziono takiej witryny.', 'skip' => true));
        http_response_code(404);
        require $componentsDir . '/error/404.php';
        break;
}
?>

</body>
</html>