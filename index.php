<?php

$headerDir = 'public_html/assets/layout/header.php';
$request = str_replace('/atte_ms_new/','',$_SERVER['REQUEST_URI']);
$viewDir = ROOT_DIRECTORY .'/public_html/components';

switch ($request) {
    case '':
    case '/':
        includeWithVariables($headerDir, array('title' => 'Strona Główna', 'skip' => true));
        if(isset($_SESSION["userid"])) require $viewDir . '/index/active-commissions-view.php';
        break;

    case 'production/tht':
        includeWithVariables($headerDir, array('title' => 'Produkcja THT'));
        require $viewDir . '/production/tht/tht-view.php';
        break;
    case 'production/smd':
        includeWithVariables($headerDir, array('title' => 'Produkcja SMD'));
        require $viewDir . '/production/smd/smd-view.php';
        break;
    case 'verification':
        includeWithVariables($headerDir, array('title' => 'Weryfikacja'));
        require $viewDir . '/verification/verification-view.php';
        break;
    case 'login':
        includeWithVariables($headerDir, array('title' => 'Logowanie', 'skip' => true));
        require $viewDir . '/login/login-view.php';
        break;
    case 'logout':
        require $viewDir . '/login/logout.php';
        break;

    default:
        includeWithVariables($headerDir, array('title' => 'Nie znaleziono takiej witryny.', 'skip' => true));
        http_response_code(404);
        require $viewDir . '/error/404.php';
        break;
}
?>

</body>
</html>