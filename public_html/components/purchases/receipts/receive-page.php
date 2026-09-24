<?php
/**
 * Standalone receive page — /purchase/receipts/receive?po_id=N.
 *
 * Wyodrębnione z receipts-view, gdzie do tej pory formularz był
 * panelem przełączanym klasą `d-none` wewnątrz tej samej strony.
 * Powód wyodrębnienia: formularz ma własną szerokość (pełna, bo 9
 * kolumn z 4 polami do wpisywania) i własny pasek akcji przyklejony
 * do dołu okna — układ, który w panelu zakładki zmagał się z
 * paddingiem (container → row → col → tab-content → tab-pane) i
 * wymuszał dynamiczne zdejmowanie `w-75` z kontenera strony.
 *
 * Konwencja mirroruje admin/purchase/documents/edit?id=N&type=…:
 *   - requireAdmin() zanim włączymy header (żeby redirect zrobił
 *     się przed jakimkolwiek outputem)
 *   - includeWithVariables() z title 'Przyjęcie towaru'
 *   - jeśli ?po_id nie jest dodatnim intem → inline alert-danger
 *     zamiast przekierowania (to samo podejście co documents-edit.php)
 *   - $magazines oraz $currentUserName zbieramy tu, bo plik
 *     receive-form.php jest czystym fragmentem HTML i sam z siebie
 *     nie odpytuje bazy
 *
 * Dane PO pobiera JS z receipt-po-get.php (POST) — ten plik PHP
 * nie musi nic wiedzieć o PO, wystarczy mu `?po_id`.
 *
 * Po zapisie receive-page.js przekierowuje na
 *   /purchase/receipts?saved=<doc>&n=<items>&po=<po>&po_state=<state>
 * a receipts-view.js czyta to przy starcie, wyświetla alert i
 * podświetla nowy wiersz historii (wizard: prawdziwy reload → DOM
 * zawiera już wiersz; JS tylko dokłada klasę .receipt-row-new,
 * której animację definiuje receipts.css).
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();

// Aktywne magazyny — ten sam SELECT co receipts-view.php używał dla
// pickera w nagłówku formularza. Listy per-pozycja klonuje JS
// (zwykłe .custom-select zamiast 20+ widgetów bootstrap-select).
$magazines = $MsaDB->query(
    "SELECT sub_magazine_id, sub_magazine_name
       FROM `magazine__list`
      WHERE isActive = 1
      ORDER BY sub_magazine_name"
);

// Aktualnie zalogowany — wyświetlany w prawym górnym rogu headera
// ("Przyjmuje: <imię nazwisko>"), żeby operator widział, pod czyim
// loginem zapisze się PZ, jeszcze zanim kliknie Zapisz.
$currentUserStmt = $MsaDB->db->prepare(
    "SELECT CONCAT(`name`, ' ', `surname`) FROM `user` WHERE `user_id` = ?"
);
$currentUserStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
$currentUserName = (string)($currentUserStmt->fetchColumn() ?: ('#' . (int)($_SESSION['user_id'] ?? 0)));

$poId = (int)($_GET['po_id'] ?? 0);
?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/purchases/cart/purchases.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/purchases/receipts/receipts.css'), ENT_QUOTES) ?>">

<?php if ($poId <= 0): ?>
    <div class="container-fluid w-75 mt-3">
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            Brak identyfikatora zamówienia (wymagane <code>?po_id=N</code>).
        </div>
        <a href="http://<?= BASEURL ?>/purchase/receipts" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do listy
        </a>
    </div>
<?php else: ?>
    <div class="container-fluid w-75 mt-3 mx-auto" id="receiptsReceiveView"
         data-po-id="<?= (int)$poId ?>"
         data-current-user="<?= htmlspecialchars($currentUserName, ENT_QUOTES) ?>">
        <?php include __DIR__ . '/receive-form.php'; ?>
    </div>

    <script src="<?= htmlspecialchars(asset('public_html/components/purchases/receipts/receive-page.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>