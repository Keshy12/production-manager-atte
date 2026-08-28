<?php
/**
 * Dedicated edit/create page for a single vendor.
 *
 * Wired at /admin/purchase/vendors/edit?id=N (mirrors the
 * producer-edit / vendor-parts/edit convention). Renders the form
 * on GET and lets vendor-edit-save.php handle the POST submission.
 *
 * Modes:
 *   - `?id=N` (N > 0) → EDIT existing vendor (pre-populated fields)
 *   - absent / id<=0  → CREATE new vendor (empty form)
 *
 * Vendor form fields (mirror list__vendor):
 *   - name (required), address, additional_data, lead_time_days,
 *     comment, isActive radio.
 *
 * In EDIT mode the page also renders:
 *   - Inline supplier CRUD (form + list) — replaces the old
 *     "Szczegóły" modal. "Dodaj osobę kontaktową" + per-row
 *     "Edytuj" repurpose a single shared form.
 *   - Read-only list of vendor parts using this vendor (mirrors
 *     the producer edit page's read-only VPs).
 *
 * On a successful CREATE the JS redirects to ?id=N so the operator
 * lands on the edit page for the freshly-created vendor. On a
 * successful UPDATE the JS stays on the page and shows an inline
 * success alert. On validation errors the save endpoint returns
 * JSON and the JS shows an inline alert at the top of the form.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;
use Atte\Utils\Purchase\Master\VendorSupplierRepository;
use Atte\Utils\Purchase\Master\VendorPartRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$isEditMode = $id > 0;
$vendor = null;
$vendorName           = '';
$vendorAddress        = '';
$vendorAdditionalData = '';
$vendorLeadTimeDays   = '';
$vendorComment        = '';
$vendorIsActive       = true;   // default: Aktywny (only meaningful in create mode)
$suppliers            = [];     // supplier rows for inline CRUD
$vendorPartCount      = 0;      // for the edit-mode hint
$vendorParts          = [];     // read-only VP list (active + inactive)

if ($isEditMode) {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorRepository($MsaDB);
    $vendor = $repo->getById($id);
    if ($vendor === null) {
        echo '<div class="container-fluid w-75 mt-3">'
           .     '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> '
           .         'Dostawca #' . htmlspecialchars((string)$id) . ' nie istnieje.'
           .     '</div>'
           . '</div>';
        return;
    }
    $vendorName           = $vendor->name;
    $vendorAddress        = $vendor->address        ?? '';
    $vendorAdditionalData = $vendor->additionalData ?? '';
    $vendorLeadTimeDays   = $vendor->leadTimeDays   ?? '';
    $vendorComment        = $vendor->comment        ?? '';
    $vendorIsActive       = $vendor->isActive;
    $vendorPartCount      = $repo->countVendorParts($id);

    // Inline supplier CRUD — fetch all suppliers (active + inactive)
    // and hydrate them as JS-readable JSON so the edit-page JS can
    // populate the shared form when "Edytuj" is clicked on a row.
    $vsRepo    = new VendorSupplierRepository($MsaDB);
    $suppliers = $vsRepo->getByVendor($id, false);

    // Read-only vendor-parts list (active + inactive). The repo's
    // LEFT JOINs fill in vendorName, producerName, partName, unitName.
    $vpRepo     = new VendorPartRepository($MsaDB);
    $vendorParts = $vpRepo->getByVendor($id, false);
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/vendors';

// Format a float without trailing zeros (e.g. 10.000 -> "10").
// Mirrors fmtQty() in vendor-parts-view.js / producer-edit-view.php.
function vr_fmt_qty($n) {
    $f = is_numeric($n) ? (float)$n : null;
    if ($f === null) return htmlspecialchars((string)($n ?? '—'), ENT_QUOTES);
    return (float)number_format($f, 4, '.', '') == (float)(int)$f
        ? (string)(int)$f
        : rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

function vr_render_packs(array $packs): string {
    if ($packs === []) {
        return '<span class="text-muted">—</span>';
    }
    $badges = '';
    foreach ($packs as $p) {
        $badges .= '<span class="vr-pack-badge">' . htmlspecialchars(vr_fmt_qty($p), ENT_QUOTES) . '</span>';
    }
    return $badges;
}
?>
<style>
    /* Match the rest of the admin cards — alert-primary header +
       card body for the form panel (same pattern as vp-edit-card /
       pr-edit-card). */
    .vr-edit-card .card-header {
        background-color: #cfe2ff;
    }
    /* Downstream-usage hint: shown only on edit mode. */
    .vr-usage-hint {
        font-size: 0.875rem;
        color: #6c757d;
        margin-top: 0.25rem;
    }
    /* Pack badges for the read-only vendor-parts table. */
    .vr-pack-badge {
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
    /* Inactive vendor-part rows: dim the row text. */
    tr.vr-vp-row--inactive {
        color: #6c757d;
    }
    /* ID column tabular figures so digits line up. */
    #vrVpTable td.vr-vp-col-id, #vrVpTable th.vr-vp-col-id {
        font-variant-numeric: tabular-nums;
    }
    .vr-vp-comment {
        font-size: 0.875rem;
        color: #6c757d;
    }
    /* Inactive supplier rows: dim the row text. */
    tr.vr-supplier-row--inactive {
        color: #6c757d;
    }
    /* Supplier inline form lives in a bordered block; visually
       distinct from the per-row buttons to avoid accidental clicks. */
    .vr-supplier-form-card {
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
        padding: 1rem;
        background-color: #f8f9fa;
    }
    .vr-supplier-form-card legend {
        font-size: 0.95rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        padding: 0 0.25rem;
    }
    /* Editing mode visually hints which row the form is targeting. */
    tr.vr-supplier-row--editing {
        background-color: #fff3cd !important;
    }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <?php if ($isEditMode): ?>
                <i class="bi bi-pencil-square"></i>
                Edytuj dostawcę
                <span class="text-muted">#<?= (int)$vendor->id ?></span>
            <?php else: ?>
                <i class="bi bi-plus-circle"></i>
                Dodaj dostawcę
            <?php endif; ?>
        </h4>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do listy
        </a>
    </div>

    <?php // AJAX-driven: errors come back as JSON and the JS shows
          // an inline alert at the top of the form. No flash needed. ?>

    <form method="post"
          id="vrEditForm"
          autocomplete="off">
        <input type="hidden" name="id" value="<?= $isEditMode ? (int)$vendor->id : 0 ?>">

        <div class="card vr-edit-card">
            <div class="card-body">

                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label for="vr_edit_name">Nazwa: <span class="text-danger">*</span></label>
                        <input type="text" id="vr_edit_name" name="name"
                               class="form-control" required maxlength="255"
                               value="<?= htmlspecialchars($vendorName, ENT_QUOTES) ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="vr_edit_lead_time">Lead time (dni):</label>
                        <input type="number" id="vr_edit_lead_time" name="lead_time_days"
                               class="form-control" min="0" step="1"
                               value="<?= htmlspecialchars((string)$vendorLeadTimeDays, ENT_QUOTES) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="vr_edit_address">Adres:</label>
                    <input type="text" id="vr_edit_address" name="address"
                           class="form-control"
                           value="<?= htmlspecialchars($vendorAddress, ENT_QUOTES) ?>">
                </div>

                <div class="form-group">
                    <label for="vr_edit_additional_data">Dodatkowe dane:</label>
                    <input type="text" id="vr_edit_additional_data" name="additional_data"
                           class="form-control"
                           value="<?= htmlspecialchars($vendorAdditionalData, ENT_QUOTES) ?>">
                </div>

                <div class="form-group">
                    <label for="vr_edit_comment">Komentarz:</label>
                    <textarea id="vr_edit_comment" name="comment" class="form-control" rows="3"
                              placeholder="opcjonalne uwagi wewnętrzne"><?= htmlspecialchars($vendorComment, ENT_QUOTES) ?></textarea>
                </div>

                <div class="form-group">
                    <label>Status:</label>
                    <div>
                        <label class="mr-3 mb-0">
                            <input type="radio" name="isActive" value="1" <?= $vendorIsActive ? 'checked' : '' ?>>
                            Aktywny
                        </label>
                        <label class="mb-0">
                            <input type="radio" name="isActive" value="0" <?= !$vendorIsActive ? 'checked' : '' ?>>
                            Nieaktywny
                        </label>
                    </div>
                    <small class="form-text text-muted">
                        Nieaktywni dostawcy nie pojawiają się w domyślnym widoku listy ani w wyszukiwarkach
                        artykułów u dostawców.
                    </small>
                </div>

                <?php if ($isEditMode): ?>
                    <div class="vr-usage-hint">
                        <i class="bi bi-info-circle"></i>
                        Ten dostawca ma przypisane <strong><?= (int)$vendorPartCount ?></strong>
                        artykuł<?= $vendorPartCount === 1 ? '' : 'ów' ?> u dostawców i <strong><?= count($suppliers) ?></strong>
                        osób<?= count($suppliers) === 1 ? 'ę' : '' ?> kontaktow<?= count($suppliers) === 1 ? 'ą' : 'ych' ?>.
                    </div>
                <?php endif; ?>

            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted">Pola oznaczone <span class="text-danger">*</span> są wymagane.</small>
                <div>
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Powrót
                    </a>
                    <button type="submit" class="btn btn-primary" id="vrSubmitBtn" disabled>
                        <?php if ($isEditMode): ?>
                            <i class="bi bi-check2-circle"></i> Zapisz zmiany
                        <?php else: ?>
                            <i class="bi bi-plus-circle"></i> Dodaj dostawcę
                        <?php endif; ?>
                    </button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($isEditMode): ?>
        <!-- ================================================================
             Inline supplier CRUD
             A single shared form at the top handles both "add new"
             and "edit existing". Clicking "Edytuj" on a row populates
             the form and highlights the row; "Anuluj" clears it. Saves
             via the existing supplier-add.php / supplier-update.php
             endpoints — no new backend required.
             ================================================================ -->
        <div class="card mt-4" id="vrSupplierCard">
            <div class="card-header alert-secondary vr-supplier-toggle" data-toggle="collapse" data-target="#vrSupplierCollapse" aria-expanded="false" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="bi bi-person-lines-fill"></i>
                        Osoby kontaktowe
                        <span class="badge badge-info ml-1"><?= count($suppliers) ?></span>
                    </h6>
                    <i class="bi bi-chevron-down"></i>
                </div>
            </div>
            <div id="vrSupplierCollapse" class="collapse">
                <div class="card-body">

                <div class="mb-3">
                    <button type="button" id="vrSupplierAddBtn" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> Dodaj osobę kontaktową
                    </button>
                </div>

                <form id="vrSupplierForm" autocomplete="off" class="vr-supplier-form-card mb-3 d-none">
                    <input type="hidden" name="id"        id="vrSupplierId"        value="">
                    <input type="hidden" name="vendor_id" id="vrSupplierVendorId" value="<?= (int)$vendor->id ?>">
                    <legend id="vrSupplierFormTitle">Dodaj osobę kontaktową</legend>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="vr_supplier_name">Imię i nazwisko: <span class="text-danger">*</span></label>
                            <input type="text" id="vr_supplier_name" name="name"
                                   class="form-control" required maxlength="255">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="vr_supplier_job_title">Stanowisko:</label>
                            <input type="text" id="vr_supplier_job_title" name="job_title"
                                   class="form-control" maxlength="255">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="vr_supplier_phone">Telefon:</label>
                            <input type="text" id="vr_supplier_phone" name="phone"
                                   class="form-control" maxlength="64">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="vr_supplier_email">Email:</label>
                            <input type="email" id="vr_supplier_email" name="email"
                                   class="form-control" maxlength="255">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="vr_supplier_comment">Komentarz:</label>
                        <textarea id="vr_supplier_comment" name="comment" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group mb-2">
                        <label>Status:</label>
                        <div>
                            <label class="mr-3 mb-0">
                                <input type="radio" name="isActive" value="1" checked> Aktywna
                            </label>
                            <label class="mb-0">
                                <input type="radio" name="isActive" value="0"> Wyłączona
                            </label>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end align-items-center">
                        <button type="button" id="vrSupplierCancelBtn" class="btn btn-outline-secondary btn-sm mr-2 d-none">
                            <i class="bi bi-x-circle"></i> Anuluj
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm" id="vrSupplierSubmitBtn" disabled>
                            <i class="bi bi-check2-circle"></i> Zapisz osobę
                        </button>
                    </div>
                </form>

                <?php if ($suppliers === []): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i>
                        Ten dostawca nie ma jeszcze przypisanych osób kontaktowych.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="vrSupplierTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Nazwa</th>
                                    <th>Stanowisko</th>
                                    <th>Telefon</th>
                                    <th>Email</th>
                                    <th style="min-width: 160px;">Komentarz</th>
                                    <th class="text-center" style="width: 110px;">Status</th>
                                    <th style="width: 100px;">Akcje</th>
                                </tr>
                            </thead>
                            <tbody id="vrSupplierTBody">
                            <?php foreach ($suppliers as $s):
                                $isActive = $s->isActive === true || $s->isActive === 1 || $s->isActive === '1';
                                $rowClass = $isActive ? '' : 'vr-supplier-row--inactive table-secondary';
                                $statusBadge = $isActive
                                    ? '<span class="badge badge-success">Aktywna</span>'
                                    : '<span class="badge badge-danger">Wyłączona</span>';

                                // Encode the supplier as JSON for the JS
                                // "Edytuj" handler to pick up without a
                                // second round-trip.
                                $supplierJson = htmlspecialchars(json_encode([
                                    'id'       => (int)$s->id,
                                    'name'     => $s->name,
                                    'jobTitle' => $s->jobTitle ?? '',
                                    'phone'    => $s->phone ?? '',
                                    'email'    => $s->email ?? '',
                                    'comment'  => $s->comment ?? '',
                                    'isActive' => $isActive,
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            ?>
                                <tr class="<?= $rowClass ?>" data-supplier-json="<?= $supplierJson ?>">
                                    <td><?= htmlspecialchars($s->name, ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($s->jobTitle ?? '', ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($s->phone ?? '', ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($s->email ?? '', ENT_QUOTES) ?></td>
                                    <td>
                                        <?php if (($s->comment ?? '') !== ''): ?>
                                            <small class="text-muted">
                                                <i class="bi bi-journal-text"></i>
                                                <?= htmlspecialchars($s->comment, ENT_QUOTES) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= $statusBadge ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-warning vr-supplier-edit-btn" title="Edytuj">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-<?= $isActive ? 'danger' : 'success' ?> vr-supplier-toggle-btn"
                                                    data-id="<?= (int)$s->id ?>"
                                                    data-is-active="<?= $isActive ? '1' : '0' ?>"
                                                    data-name="<?= htmlspecialchars($s->name, ENT_QUOTES) ?>"
                                                    title="<?= $isActive ? 'Wyłącz' : 'Włącz' ?>">
                                                <i class="bi bi-<?= $isActive ? 'x-circle' : 'check-circle' ?>"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </div>

        <!-- ================================================================
             Read-only list of vendor parts using this vendor. Same shape
             as the producer edit page's read-only VPs. Collapsed by
             default — operator is here to manage the vendor record, not
             the vendor-part catalog (that's a separate module).
             ================================================================ -->
        <div class="card mt-4" id="vrVpCard">
            <div class="card-header alert-secondary vr-vp-toggle" data-toggle="collapse" data-target="#vrVpCollapse" aria-expanded="false" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="bi bi-box-seam"></i>
                        Artykuły u dostawców
                        <span class="badge badge-info ml-1"><?= (int)$vendorPartCount ?></span>
                    </h6>
                    <i class="bi bi-chevron-down"></i>
                </div>
            </div>
            <div id="vrVpCollapse" class="collapse">
                <div class="card-body">
                    <small class="text-muted d-block mb-2">
                        Tylko podgląd — edycja artykułów odbywa się w module „Artykuły u dostawców".
                    </small>

                    <?php if ($vendorParts === []): ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i>
                            Ten dostawca nie ma jeszcze przypisanych artykułów.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0" id="vrVpTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th class="vr-vp-col-id text-center" style="width: 70px;">ID</th>
                                        <th>Artykuł</th>
                                        <th>Producent</th>
                                        <th>JM</th>
                                        <th>Opak.</th>
                                        <th style="min-width: 180px;">Komentarz</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vendorParts as $vp):
                                    $isActive = $vp->isActive === true || $vp->isActive === 1 || $vp->isActive === '1';
                                    $rowClass = $isActive ? '' : 'vr-vp-row--inactive table-secondary';

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

                                    $COMMENT_TRUNCATE_AT = 200;
                                    $rawCmt = trim((string)($vp->comment ?? ''));
                                    if ($rawCmt === '') {
                                        $komentarzCell = '<span class="text-muted">—</span>';
                                    } elseif (mb_strlen($rawCmt) > $COMMENT_TRUNCATE_AT) {
                                        $visible = htmlspecialchars(mb_substr($rawCmt, 0, $COMMENT_TRUNCATE_AT), ENT_QUOTES) . '…';
                                        $komentarzCell = '<small class="vr-vp-comment" title="'
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '">'
                                                       . '<i class="bi bi-journal-text"></i> ' . $visible . '</small>';
                                    } else {
                                        $komentarzCell = '<small class="vr-vp-comment">'
                                                       . '<i class="bi bi-journal-text"></i> '
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '</small>';
                                    }
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="vr-vp-col-id text-center"><?= (int)$vp->id ?></td>
                                        <td><?= $artykulCell ?></td>
                                        <td><?= htmlspecialchars($vp->producerName ?? '—', ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars($vp->unitName ?? '—', ENT_QUOTES) ?></td>
                                        <td><?= vr_render_packs($vp->packQuantities) ?></td>
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

        <!-- ================================================================
             Toggle supplier confirm modal (rendered once, embedded in the
             page so the supplier-list JS can show it without a second
             include). Mirrors the legacy inline toggle UX (button + tiny
             confirm modal) — keeps the "is this destructive?" friction
             intentional.
             ================================================================ -->
        <div class="modal fade" id="vrToggleSupplierModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <input type="hidden" id="vrToggleSupplierId">
                    <input type="hidden" id="vrToggleSupplierIsActive">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Potwierdź</h5>
                        <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body" id="vrToggleSupplierBody">
                        Czy na pewno chcesz zmienić status tej osoby kontaktowej?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                        <button type="button" id="vrConfirmToggleSupplier" class="btn btn-primary">Tak</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Vendors/edit/vendor-edit-view.js') ?>"></script>