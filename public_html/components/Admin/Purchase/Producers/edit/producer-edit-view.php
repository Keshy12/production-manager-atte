<?php
/**
 * Dedicated edit/create page for a single producer.
 *
 * Wired at /admin/purchase/producers/edit?id=N (mirrors the
 * vendor-parts/edit convention). Renders the form on GET and lets
 * producer-edit-save.php handle the POST submission.
 *
 * Modes:
 *   - `?id=N` (N > 0) → EDIT existing producer (pre-populated fields)
 *   - absent / id<=0  → CREATE new producer (empty form)
 *
 * Form fields mirror list__producer:
 *   - name (text, required)
 *   - comment (textarea, optional)
 *   - isActive (radio: Aktywny / Nieaktywny, default Aktywny on create)
 *
 * On a successful CREATE the JS redirects to ?id=N so the operator
 * lands on the edit page for the freshly-created producer. On a
 * successful UPDATE the JS stays on the page and shows an inline
 * success alert. On validation errors the save endpoint returns
 * JSON and the JS shows an inline alert at the top of the form.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\ProducerRepository;
use Atte\Utils\Purchase\Master\VendorPartRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$isEditMode = $id > 0;
$producer = null;
$producerName = '';
$producerComment = '';
$producerIsActive = true;   // default: Aktywny (only meaningful in create mode)
$vendorPartCount = 0;        // for the edit-mode hint under the form
$vendorParts = [];           // read-only display: vendor parts using this producer

if ($isEditMode) {
    $MsaDB = MsaDB::getInstance();
    $repo  = new ProducerRepository($MsaDB);
    $producer = $repo->getById($id);
    if ($producer === null) {
        echo '<div class="container-fluid w-75 mt-3">'
           .     '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> '
           .         'Producent #' . htmlspecialchars((string)$id) . ' nie istnieje.'
           .     '</div>'
           . '</div>';
        return;
    }
    $producerName     = $producer->name;
    $producerComment  = $producer->comment ?? '';
    $producerIsActive = $producer->isActive;
    $vendorPartCount  = $repo->countVendorParts($id);

    // Fetch the actual rows for the read-only table below the form.
    // Both active and inactive rows so the operator sees the full
    // picture (matches the vendor-parts listing's behaviour with
    // $onlyActive=false). The repo's LEFT JOINs fill in vendorName,
    // partName, unitName so we don't need extra queries.
    $vpRepo      = new VendorPartRepository($MsaDB);
    $vendorParts = $vpRepo->getByProducer($id, false);
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/producers';

// Format a float without trailing zeros (e.g. 10.000 -> "10").
// Mirrors fmtQty() in vendor-parts-view.js so server- and client-
// rendered pack badges read identically.
function pr_fmt_qty($n) {
    $f = is_numeric($n) ? (float)$n : null;
    if ($f === null) return htmlspecialchars((string)($n ?? '—'), ENT_QUOTES);
    return (float)number_format($f, 4, '.', '') == (float)(int)$f
        ? (string)(int)$f
        : rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

// Render the packs cell — small inline badges, comma-separated. Empty
// state shows a muted em-dash so the column doesn't collapse.
function pr_render_packs(array $packs): string {
    if ($packs === []) {
        return '<span class="text-muted">—</span>';
    }
    $badges = '';
    foreach ($packs as $p) {
        $badges .= '<span class="pr-pack-badge">' . htmlspecialchars(pr_fmt_qty($p), ENT_QUOTES) . '</span>';
    }
    return $badges;
}
?>
<style>
    /* Match the rest of the admin cards — alert-primary header +
       card body for the form panel (same pattern as vp-edit-card). */
    .pr-edit-card .card-header {
        background-color: #cfe2ff;
    }
    /* Downstream-usage hint: shown only on edit mode. */
    .pr-usage-hint {
        font-size: 0.875rem;
        color: #6c757d;
        margin-top: 0.25rem;
    }
    /* Packs cell — small inline badges, muted, comma-separated. Same
       visual language as the vendor-parts listing. */
    .pr-pack-badge {
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 500;
        padding: 0.15rem 0.45rem;
        margin-right: 0.2rem;
        margin-bottom: 0.15rem;
        background: #e9ecef;
        color: #495057;
        border-radius: 0.25rem;
        line-height: 1.4;
    }
    /* Inactive vendor-part rows: dim the row text so the status reads
       at a glance. Mirrors vp-row--inactive on the main listing. */
    tr.pr-vp-row--inactive {
        color: #6c757d;
    }
    /* ID column tabular figures so digits line up across rows. */
    #prVpTable td.pr-vp-col-id, #prVpTable th.pr-vp-col-id {
        font-variant-numeric: tabular-nums;
    }
    /* Komentarz truncation — same shape as the listing's renderRow. */
    .pr-vp-comment {
        font-size: 0.875rem;
        color: #6c757d;
    }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <?php if ($isEditMode): ?>
                <i class="bi bi-pencil-square"></i>
                Edytuj producenta
                <span class="text-muted">#<?= (int)$producer->id ?></span>
            <?php else: ?>
                <i class="bi bi-plus-circle"></i>
                Dodaj producenta
            <?php endif; ?>
        </h4>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do listy
        </a>
    </div>

    <?php // AJAX-driven: errors come back as JSON and the JS shows
          // an inline alert at the top of the form. No flash needed. ?>

    <form method="post"
          id="prEditForm"
          autocomplete="off">
        <input type="hidden" name="id" value="<?= $isEditMode ? (int)$producer->id : 0 ?>">

        <div class="card pr-edit-card">
            <div class="card-body">

                <div class="form-group">
                    <label for="pr_edit_name">Nazwa: <span class="text-danger">*</span></label>
                    <input type="text" id="pr_edit_name" name="name"
                           class="form-control" required maxlength="255"
                           value="<?= htmlspecialchars($producerName, ENT_QUOTES) ?>">
                </div>

                <div class="form-group">
                    <label for="pr_edit_comment">Komentarz:</label>
                    <textarea id="pr_edit_comment" name="comment" class="form-control" rows="3"
                              placeholder="opcjonalne uwagi wewnętrzne"><?= htmlspecialchars($producerComment, ENT_QUOTES) ?></textarea>
                </div>

                <div class="form-group">
                    <label>Status:</label>
                    <div>
                        <label class="mr-3 mb-0">
                            <input type="radio" name="isActive" value="1" <?= $producerIsActive ? 'checked' : '' ?>>
                            Aktywny
                        </label>
                        <label class="mb-0">
                            <input type="radio" name="isActive" value="0" <?= !$producerIsActive ? 'checked' : '' ?>>
                            Nieaktywny
                        </label>
                    </div>
                    <small class="form-text text-muted">
                        Nieaktywni producenci nie pojawiają się w domyślnym widoku listy ani w wyszukiwarkach
                        artykułów u dostawców.
                    </small>
                </div>

                <?php if ($isEditMode): ?>
                    <div class="pr-usage-hint">
                        <i class="bi bi-info-circle"></i>
                        Ten producent jest przypisany do <strong><?= (int)$vendorPartCount ?></strong>
                        artykuł<?= $vendorPartCount === 1 ? 'a' : 'ów' ?> u dostawców.
                    </div>
                <?php endif; ?>

            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted">Pola oznaczone <span class="text-danger">*</span> są wymagane.</small>
                <div>
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Powrót
                    </a>
                    <button type="submit" class="btn btn-primary" id="prSubmitBtn" disabled>
                        <?php if ($isEditMode): ?>
                            <i class="bi bi-check2-circle"></i> Zapisz zmiany
                        <?php else: ?>
                            <i class="bi bi-plus-circle"></i> Dodaj producenta
                        <?php endif; ?>
                    </button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($isEditMode): ?>
        <!-- Read-only list of vendor parts using this producer. Mirrors
             the vendor-parts listing columns (sans Akcje — this is a
             side panel, not an editing surface). Both active and
             inactive VPs are shown so the operator can see the full
             downstream impact. Collapsed by default — the operator is
             here to edit the producer, not the vendor parts. -->
        <div class="card mt-4" id="prVpCard">
            <div class="card-header alert-secondary pr-vp-toggle" data-toggle="collapse" data-target="#prVpCollapse" aria-expanded="false" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="bi bi-box-seam"></i>
                        Artykuły u dostawców
                        <span class="badge badge-info ml-1"><?= (int)$vendorPartCount ?></span>
                    </h6>
                    <i class="bi bi-chevron-down"></i>
                </div>
            </div>
            <div id="prVpCollapse" class="collapse">
                <div class="card-body">
                    <small class="text-muted d-block mb-2">
                        Tylko podgląd — edycja artykułów odbywa się w module „Artykuły u dostawców".
                    </small>

                    <?php if ($vendorParts === []): ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i>
                            Ten producent nie jest jeszcze przypisany do żadnego artykułu u dostawców.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0" id="prVpTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th class="pr-vp-col-id text-center" style="width: 70px;">ID</th>
                                        <th>Artykuł</th>
                                        <th>Dostawca</th>
                                        <th>JM</th>
                                        <th>Opak.</th>
                                        <th style="min-width: 180px;">Komentarz</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vendorParts as $vp):
                                    $isActive = $vp->isActive === true || $vp->isActive === 1 || $vp->isActive === '1';
                                    $rowClass = $isActive ? '' : 'pr-vp-row--inactive table-secondary';

                                    // Artykuł cell — part name + merged-or-separate
                                    // vendor/producer part numbers. Same shape as
                                    // vendor-parts-view.js renderRow().
                                    $artykulCell = htmlspecialchars($vp->partName ?? '', ENT_QUOTES);
                                    $vn = $vp->vendorPartNo ?? '';
                                    $pn = $vp->producerPartNo ?? '';
                                    if ($vn !== '' && $pn !== '' && $vn === $pn) {
                                        $artykulCell .= '<div><small class="text-muted">Nr dost./prod.: <span class="font-weight-bold">'
                                                      . htmlspecialchars($vn, ENT_QUOTES) . '</span></small></div>';
                                    } else {
                                        if ($vn !== '') {
                                            $artykulCell .= '<div><small class="text-muted">Nr dost.: <span class="font-weight-bold">'
                                                          . htmlspecialchars($vn, ENT_QUOTES) . '</span></small></div>';
                                        }
                                        if ($pn !== '') {
                                            $artykulCell .= '<div><small class="text-muted">Nr prod.: <span class="font-weight-bold">'
                                                          . htmlspecialchars($pn, ENT_QUOTES) . '</span></small></div>';
                                        }
                                    }

                                    // Dostawca cell — vendor name only. Sub-line
                                    // "Producent" is suppressed because we're
                                    // already on this producer's page (no point
                                    // repeating it on every row).
                                    $dostawcaCell = htmlspecialchars($vp->vendorName ?? '—', ENT_QUOTES);

                                    // Komentarz cell — same truncate-at-200 + title
                                    // tooltip as the listing.
                                    $COMMENT_TRUNCATE_AT = 200;
                                    $rawCmt = trim((string)($vp->comment ?? ''));
                                    if ($rawCmt === '') {
                                        $komentarzCell = '<span class="text-muted">—</span>';
                                    } elseif (mb_strlen($rawCmt) > $COMMENT_TRUNCATE_AT) {
                                        $visible = htmlspecialchars(mb_substr($rawCmt, 0, $COMMENT_TRUNCATE_AT), ENT_QUOTES) . '…';
                                        $komentarzCell = '<small class="pr-vp-comment" title="'
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '">'
                                                       . '<i class="bi bi-journal-text"></i> ' . $visible . '</small>';
                                    } else {
                                        $komentarzCell = '<small class="pr-vp-comment">'
                                                       . '<i class="bi bi-journal-text"></i> '
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '</small>';
                                    }
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="pr-vp-col-id text-center"><?= (int)$vp->id ?></td>
                                        <td><?= $artykulCell ?></td>
                                        <td><?= $dostawcaCell ?></td>
                                        <td><?= htmlspecialchars($vp->unitName ?? '—', ENT_QUOTES) ?></td>
                                        <td><?= pr_render_packs($vp->packQuantities) ?></td>
                                        <td><?= $komentarzCell ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Producers/edit/producer-edit-view.js') ?>"></script>