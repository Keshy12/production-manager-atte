<?php

$headerDir = 'public_html/assets/layout/header.php';
$request = strtok(str_replace('/atte_ms_new/', '', $_SERVER['REQUEST_URI']), '?');
$componentsDir = ROOT_DIRECTORY .'/public_html/components';

// Auth gate for /admin/* routes. Must be called BEFORE includeWithVariables()
// so header() can redirect without 'headers already sent' warnings.
function requireAdmin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: http://' . BASEURL . '/login');
        exit;
    }
    if (empty($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        header('Location: http://' . BASEURL . '/unauthorized');
        exit;
    }
}

// Serve Admin AJAX endpoints (URL pattern: /admin/<module>/<feature>/.../file.php)
// directly from their actual filesystem location. The .htaccess catch-all rewrite
// would otherwise send these to index.php, where the switch has no matching case.
// The switch below still handles extensionless URLs like /admin/purchase/vendors.
if (preg_match('#^admin/(.+\.php)$#', $request, $m)) {
    $componentFile = ROOT_DIRECTORY . '/public_html/components/Admin/' . str_replace('/', DIRECTORY_SEPARATOR, $m[1]);
    if (is_file($componentFile)) {
        require $componentFile;
        exit;
    }
}

switch ($request) {
    case '':
    case '/':
        includeWithVariables($headerDir, array('title' => 'Strona Główna', 'skip' => true));
        if(isset($_SESSION['user_id'])) require $componentsDir . '/index/active-commissions-view.php';
        break;

    case 'production/tht':
        includeWithVariables($headerDir, array('title' => 'Produkcja THT'));
        $_GET['type'] = 'tht';
        require $componentsDir . '/production/production-view.php';
        break;
    case 'production/smd':
        includeWithVariables($headerDir, array('title' => 'Produkcja SMD'));
        $_GET['type'] = 'smd';
        require $componentsDir . '/production/production-view.php';
        break;
    case 'transfer':
        includeWithVariables($headerDir, array('title' => 'Transfer'));
        require $componentsDir . '/transfer/transfer-view.php';
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
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Wczytywanie BOM'));
        require $componentsDir . '/admin/bom/upload/upload-bom-view.php';
        break;
    case 'admin/bom/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja BOM'));
        require $componentsDir . '/admin/bom/edit/edit-bom-view.php';
        break;
    case 'admin/bom/dictionary':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja słownika ValuePackage'));
        require $componentsDir . '/admin/bom/dictionary/edit-dictionary-view.php';
        break;
    case 'admin/profiles/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja Profili'));
        require $componentsDir . '/admin/profiles/edit/edit-profile-view.php';
        break;
    case 'admin/components/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja Komponentów'));
        require $componentsDir . '/admin/components/edit/edit-component-view.php';
        break;
    case 'admin/components/detect-new-parts':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Aktualizuj Parts'));
        require $componentsDir . '/admin/components/detectnewparts/detect-parts-view.php';
        break;
    case 'admin/components/from-orders':
        requireAdmin();
        header('Location: http://' . BASEURL . '/admin/synchronization/sheets#import-orders');
        exit;
        break;
    case 'admin/components/update-prices':
        requireAdmin();
        header('Location: http://' . BASEURL . '/admin/synchronization/sheets#update-prices');
        exit;
        break;
    case 'admin/magazines/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja Magazynów'));
        require $componentsDir . '/admin/magazines/edit/edit-magazines-view.php';
        break;
    case 'admin/purchase/vendors':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Dostawcy'));
        require $componentsDir . '/Admin/Purchase/Vendors/vendors-view.php';
        break;
    case 'admin/purchase/producers':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Producenci'));
        require $componentsDir . '/Admin/Purchase/Producers/producers-view.php';
        break;
    case 'admin/purchase/vendor-parts':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Artykuły u dostawców'));
        require $componentsDir . '/Admin/Purchase/VendorParts/vendor-parts-view.php';
        break;
    case 'admin/purchase/rfqs':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Zapytania ofertowe'));
        require $componentsDir . '/Admin/Purchase/Rfqs/rfqs-view.php';
        break;
    case 'admin/purchase/rfqs/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja zapytania'));
        require $componentsDir . '/Admin/Purchase/Rfqs/Edit/edit-rfq-view.php';
        break;
    case 'admin/purchase/orders':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Zamówienia'));
        require $componentsDir . '/Admin/Purchase/Orders/orders-view.php';
        break;
    case 'admin/purchase/orders/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja zamówienia'));
        require $componentsDir . '/Admin/Purchase/Orders/Edit/edit-order-view.php';
        break;
    case 'admin/purchase/cart':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Koszyk zakupowy'));
        require $componentsDir . '/Admin/Purchase/Cart/cart-view.php';
        break;
    case 'admin/purchase/receipts':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Przyjęcia'));
        require $componentsDir . '/Admin/Purchase/Receipts/receipts-view.php';
        break;
    case 'admin/purchase/orders/receive':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Przyjmij towar'));
        require $componentsDir . '/Admin/Purchase/Orders/Receive/receive-view.php';
        break;

    case 'admin/synchronization/flowpin':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Flowpin - Status Aktualizacji'));
        require $componentsDir . '/admin/Synchronization/flowpin/flowpin-status-view.php';
        break;

    case 'admin/synchronization/sheets':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Flowpin - Arkusze'));
        require $componentsDir . '/admin/Synchronization/sheets/flowpin-sheets-view.php';
        break;

    case 'test':
        require $componentsDir . '/tests/test1.php';
        break;

    case 'flowpin/test':
        includeWithVariables($headerDir, array('title' => 'Flowpin Warehouse State'));
        require $componentsDir . '/tests/warehouse_state_view.php';
        break;

    case 'login':
        includeWithVariables($headerDir, array('title' => 'Logowanie', 'skip' => true));
        if(!isset($_SESSION['user_id'])) require $componentsDir . '/login/login-view.php';
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