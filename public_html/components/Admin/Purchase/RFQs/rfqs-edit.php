<?php
/**
 * Minimal RFQ edit page (read-only for now).
 *
 * Wired at /admin/purchase/rfqs/edit?id=N. Mirrors orders-edit.php;
 * will grow into a real edit form later (vendor / dates / items /
 * state transitions).
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id zapytania.</div></div>';
    return;
}

$MsaDB = MsaDB::getInstance();
$rfqRepo = new RFQRepository($MsaDB);
$rfiRepo = new RFQItemRepository($MsaDB);

$rfq = $rfqRepo->getById($id);
if ($rfq === null) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Zapytanie #' . htmlspecialchars((string)$id) . ' nie istnieje.</div></div>';
    return;
}
$items = $rfiRepo->getByRfq($id);

$stateBadgeClass = [
    'draft'     => 'badge-secondary',
    'sent'      => 'badge-primary',
    'responded' => 'badge-info',
    'cancelled' => 'badge-danger',
    'converted' => 'badge-success',
];
$stateBadgeLabel = [
    'draft'     => 'szkic',
    'sent'      => 'wysłane',
    'responded' => 'odpowiedź',
    'cancelled' => 'anulowane',
    'converted' => 'skonwertowane na PO',
];

function formatQty($v) {
    if ($v === null || $v === '') return '—';
    return rtrim(rtrim(number_format((float)$v, 4, '.', ' '), '0'), '.');
}
?>
<div class="container-fluid w-75 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">
            <i class="bi bi-file-earmark-text"></i>
            Zapytanie ofertowe
            <span class="font-weight-bold"><?= htmlspecialchars($rfq->rfqNumber ?? ('#' . $rfq->id)) ?></span>
            <span class="badge <?= $stateBadgeClass[$rfq->state] ?? 'badge-secondary' ?> ml-2"><?= $stateBadgeLabel[$rfq->state] ?? $rfq->state ?></span>
        </h2>
        <a href="http://<?= BASEURL ?>/purchase/cart" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do koszyka
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Nagłówek</strong></div>
        <div class="card-body py-2">
            <dl class="row mb-0">
                <dt class="col-sm-2">Dostawca:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->vendorName ?? '—') ?></dd>
                <dt class="col-sm-2">Numer RFQ:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->rfqNumber ?? '—') ?></dd>

                <dt class="col-sm-2">Oczekiwana odpowiedź:</dt>
                <dd class="col-sm-4"><?= $rfq->expectedReplyDate ? htmlspecialchars($rfq->expectedReplyDate) : '—' ?></dd>
                <dt class="col-sm-2">Wysłane:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->sentAt ?? '—') ?></dd>

                <dt class="col-sm-2">Utworzył:</dt>
                <dd class="col-sm-4"><?= (int)$rfq->createdBy ?></dd>
                <dt class="col-sm-2">Utworzone:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->createdAt ?? '—') ?></dd>

                <?php if ($rfq->comment): ?>
                    <dt class="col-sm-2">Komentarz:</dt>
                    <dd class="col-sm-10"><?= nl2br(htmlspecialchars($rfq->comment)) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Pozycje</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Część</th>
                            <th>Nr u dostawcy</th>
                            <th>Producent</th>
                            <th class="text-right">Ilość</th>
                            <th>JM</th>
                            <th class="text-right">Cena / szt</th>
                            <th>Waluta</th>
                            <th>Komentarz</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="8" class="text-center text-muted">Brak pozycji.</td></tr>
                    <?php else: foreach ($items as $i): ?>
                        <tr>
                            <td><?= htmlspecialchars($i->partName ?? '—') ?></td>
                            <td><?= htmlspecialchars($i->vendorPartNo ?? '—') ?></td>
                            <td><?= htmlspecialchars($i->producerName ?? '—') ?></td>
                            <td class="text-right"><?= htmlspecialchars(formatQty($i->quantity)) ?></td>
                            <td><?= htmlspecialchars($i->unitName ?? '—') ?></td>
                            <td class="text-right"><?= $i->unitPrice !== null ? htmlspecialchars(number_format((float)$i->unitPrice, 4, '.', ' ') . ' / szt') : '—' ?></td>
                            <td><?= htmlspecialchars($i->currency ?? '—') ?></td>
                            <td><?= $i->comment ? htmlspecialchars($i->comment) : '' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small">
        Widok minimalny — pola edycji i akcje (wyślij / konwertuj na PO) pojawią się w kolejnych iteracjach.
    </p>
</div>
