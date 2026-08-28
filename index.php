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
    case 'admin/purchase/vendors/edit':
        // Dedicated edit/create page for a single vendor. The id is
        // passed via ?id=N (mirrors the producer-edit / vendor-parts/edit
        // convention). Absent or zero id → CREATE mode (empty form);
        // positive id → EDIT mode (pre-populated fields + inline
        // supplier CRUD + read-only vendor-parts list). The form posts
        // to a separate endpoint file (edit/vendor-edit-save.php)
        // because the .htaccess POST rule blocks POSTs to router-only
        // URLs; only real files under components/ are served directly.
        // See AGENTS.md "AJAX endpoints (component-side)".
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edytuj dostawcę'));
        require $componentsDir . '/Admin/Purchase/Vendors/edit/vendor-edit-view.php';
        break;
    case 'admin/purchase/producers':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Producenci'));
        require $componentsDir . '/Admin/Purchase/Producers/producers-view.php';
        break;
    case 'admin/purchase/producers/edit':
        // Dedicated edit/create page for a single producer. The id is
        // passed via ?id=N (mirrors the vendor-parts/edit convention).
        // Absent or zero id → CREATE mode (empty form); positive id →
        // EDIT mode (pre-populated fields). The form posts to a separate
        // endpoint file (edit/producer-edit-save.php) because the
        // .htaccess POST rule blocks POSTs to router-only URLs; only
        // real files under components/ are served directly. See
        // AGENTS.md "AJAX endpoints (component-side)".
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edytuj producenta'));
        require $componentsDir . '/Admin/Purchase/Producers/edit/producer-edit-view.php';
        break;
    case 'admin/purchase/vendor-parts':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Artykuły u dostawców'));
        require $componentsDir . '/Admin/Purchase/VendorParts/vendor-parts-view.php';
        break;
    case 'admin/purchase/vendor-parts/edit':
        // Dedicated edit/create page for a single vendor-part. The id is
        // passed via ?id=N (mirrors orders-edit.php, rfqs-edit.php, and
        // the producers/edit dual-mode convention — no wildcard /
        // numeric-segment in the switch). Absent or zero id → CREATE
        // mode (empty form); positive id → EDIT mode (pre-populated
        // fields). The form posts to a separate endpoint file
        // (vendor-part-edit-save.php) because the .htaccess POST rule
        // (lines 7-9) blocks POSTs to router-only URLs; only real files
        // under components/ are served directly. See AGENTS.md "AJAX
        // endpoints (component-side)".
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Artykuł u dostawcy'));
        require $componentsDir . '/Admin/Purchase/VendorParts/edit/vendor-part-edit-view.php';
        break;
    case 'admin/purchase/orders/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja zamówienia'));
        require $componentsDir . '/Admin/Purchase/Orders/orders-edit.php';
        break;
    case 'admin/purchase/rfqs/edit':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Edycja zapytania'));
        require $componentsDir . '/Admin/Purchase/RFQs/rfqs-edit.php';
        break;
    case 'purchase/cart':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Koszyk zakupowy'));
        require $componentsDir . '/purchases/cart/cart-view.php';
        break;
    case 'purchase/receipts':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Przyjęcia'));
        require $componentsDir . '/purchases/receipts/receipts-view.php';
        break;
    case 'purchase/documents':
        requireAdmin();
        includeWithVariables($headerDir, array('title' => 'Zapytania i zamówienia'));
        require $componentsDir . '/purchases/documents/documents-view.php';
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