<?php
/**
 * Dedicated edit/create page for a single vendor-part.
 *
 * Wired at /admin/purchase/vendor-parts/edit (mirrors the
 * documents-edit.php convention; see also the
 * producers/edit dual-mode page). Renders the form on GET and lets
 * vendor-part-edit-save.php handle the POST submission.
 *
 * Modes:
 *   - `?id=N` (N > 0) → EDIT existing vendor-part (pre-populated fields)
 *   - absent / id<=0  → CREATE new vendor-part (empty form)
 *
 * Form fields mirror list__vendor_part + list__vendor_part_pack:
 *   - vendor / producer / part / JM (live-search selectpickers,
 *     full lists pre-rendered server-side — no AJAX search needed
 *     since this page is a single-record form)
 *   - vendor_part_no, producer_part_no (text)
 *   - multi-pack editing: one number input per pack size, with
 *     add/remove buttons; pre-populated from list__vendor_part_pack
 *     (empty in CREATE mode)
 *   - isActive (radio: Aktywny / Nieaktywny; defaults to Aktywny in
 *     CREATE mode)
 *   - comment (textarea)
 *
 * The form is AJAX-driven. On a successful CREATE the JS follows the
 * `edit_url` returned by the save endpoint so the operator lands on
 * the edit page for the freshly-created vendor-part (mirrors the
 * cart-view.js "open in new tab" pattern). On a successful UPDATE
 * the JS stays on the page and shows an inline success alert. On a
 * validation error the save handler returns JSON and the JS shows an
 * inline alert at the top of the form.
 */
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
use Atte\Utils\Purchase\Master\VendorPartRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$id        = (int)($_GET['id'] ?? 0);
$isEditMode = $id > 0;
$vp        = null;

$MsaDB = MsaDB::getInstance();
$repo  = new VendorPartRepository($MsaDB);

if ($isEditMode) {
    $vp = $repo->getById($id);
    if ($vp === null) {
        echo '<div class="container-fluid w-75 mt-3">'
           .     '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> '
           .         'Artykuł #' . htmlspecialchars((string)$id) . ' nie istnieje.'
           .     '</div>'
           . '</div>';
        return;
    }
}

$selectRenderer = new SelectRenderer($MsaDB);

// Source lists for the FK dropdowns. Full lists — this page doesn't
// cascade (single-record form), and bootstrap-select's live-search
// gives the operator a quick filter even with hundreds of options.
$vendor_list   = $MsaDB->readIdName('list__vendor',   'id', 'name', 'ORDER BY name ASC');
$producer_list = $MsaDB->readIdName('list__producer', 'id', 'name', 'ORDER BY name ASC');
$unit_list     = $MsaDB->readIdName('part__unit',     'id', 'name', 'ORDER BY name ASC');

// Część dropdown needs the description next to every part so each
// <option> can render a bootstrap-select `data-subtext` (the canonical
// second-line under each option label). Same pattern as the listing.
$parts_rows = $MsaDB->db->query(
    "SELECT id, name, description FROM `list__parts` ORDER BY name ASC",
    \PDO::FETCH_ASSOC
)->fetchAll();
$part_names        = [];
$part_descriptions = [];
foreach ($parts_rows as $pr) {
    $idKey = (int)$pr['id'];
    $part_names[$idKey]        = $pr['name'];
    $part_descriptions[$idKey] = (string)($pr['description'] ?? '');
}

// Field defaults. In EDIT mode they pull from the loaded row; in
// CREATE mode the strings are empty and the radio defaults to active.
$vendorId       = $isEditMode ? $vp->vendorId       : null;
$producerId     = $isEditMode ? $vp->producerId     : null;
$partsId        = $isEditMode ? $vp->partsId        : null;
$vendorJmId     = $isEditMode ? $vp->vendorJmId     : null;
$vendorPartNo   = $isEditMode ? $vp->vendorPartNo   : '';
$producerPartNo = $isEditMode ? ($vp->producerPartNo ?? '') : '';
$isActive       = $isEditMode ? $vp->isActive       : true;  // default Aktywny on create
$comment        = $isEditMode ? ($vp->comment ?? '') : '';
$packs          = $isEditMode
    ? array_map(
        // The repo returns the full set sorted ASC; render each as a
        // trimmed numeric string for the HTML input.
        fn($p) => rtrim(rtrim(number_format((float)$p, 10, '.', ''), '0'), '.'),
        $vp->packQuantities
    )
    : [];
// In CREATE mode no pack rows render; the operator can add the
// first pack via the "Dodaj opakowanie" button. Empty pack set is
// also valid — parts that aren't packaged.

// Inline option renderer that respects a `selected` id (the existing
// SelectRenderer doesn't — it always emits plain options). Same
// markup shape as renderArraySelectWithSubtext for the parts list.
function vp_render_options(array $array, ?int $selectedId, string $subtextKey = null): void {
    foreach ($array as $idKey => $value) {
        $sel    = ($selectedId !== null && (int)$idKey === (int)$selectedId) ? 'selected' : '';
        $valEsc = htmlspecialchars((string)$idKey, ENT_QUOTES);
        $valHtml = htmlspecialchars((string)$value, ENT_QUOTES);
        if ($subtextKey !== null) {
            $subEsc = htmlspecialchars((string)$subtextKey, ENT_QUOTES);
            echo '<option data-subtext="' . $subEsc . '" data-tokens="' . $valHtml . ' ' . $subEsc . '" value="' . $valEsc . '"' . $sel . '>' . $valHtml . '</option>';
        } else {
            echo '<option value="' . $valEsc . '"' . $sel . '>' . $valHtml . '</option>';
        }
    }
}
function vp_render_options_with_subtext(array $array, array $subText, ?int $selectedId): void {
    foreach ($array as $idKey => $value) {
        $sel    = ($selectedId !== null && (int)$idKey === (int)$selectedId) ? 'selected' : '';
        $valEsc = htmlspecialchars((string)$idKey, ENT_QUOTES);
        $valHtml = htmlspecialchars((string)$value, ENT_QUOTES);
        $sub     = htmlspecialchars((string)($subText[$idKey] ?? ''), ENT_QUOTES);
        echo '<option data-subtext="' . $sub . '" data-tokens="' . $valHtml . ' ' . $sub . '" value="' . $valEsc . '"' . $sel . '>' . $valHtml . '</option>';
    }
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/vendor-parts';
?>
<style>
    /* Pack-input row layout — flex so the × button stays glued to
       the input. The whole row is wrapped in `.input-group` so the
       BS4 styling matches the rest of the admin forms. */
    .vp-pack-row {
        display: flex;
        align-items: stretch;
        margin-bottom: 0.5rem;
    }
    .vp-pack-row input[type="number"] {
        flex: 1 1 auto;
        min-width: 0;
    }
    .vp-pack-row .vp-pack-remove {
        flex: 0 0 auto;
        margin-left: -1px;            /* overlap the input's right border */
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }
    .vp-pack-row .vp-pack-remove[disabled] {
        cursor: not-allowed;
    }
    /* Match the rest of the admin cards — alert-primary header +
       card body for the form panel. */
    .vp-edit-card .card-header {
        background-color: #cfe2ff;
    }
    .vp-pack-help {
        font-size: 0.8rem;
        color: #6c757d;
        margin-top: 0.25rem;
    }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <?php if ($isEditMode): ?>
                <i class="bi bi-pencil-square"></i>
                Edytuj artykuł u dostawcy
                <span class="text-muted">#<?= (int)$vp->id ?></span>
            <?php else: ?>
                <i class="bi bi-plus-circle"></i>
                Dodaj artykuł u dostawcy
            <?php endif; ?>
        </h4>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do listy
        </a>
    </div>

    <?php // AJAX-driven: errors come back as JSON and the JS shows
          // an inline alert at the top of the form. No flash needed. ?>

    <form method="post"
          id="vpEditForm"
          autocomplete="off">
        <input type="hidden" name="id" value="<?= $isEditMode ? (int)$vp->id : 0 ?>">

        <div class="card vp-edit-card">
            <div class="card-body">

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="vp_edit_vendor_id">Dostawca: <span class="text-danger">*</span></label>
                        <select id="vp_edit_vendor_id" name="vendor_id"
                                class="selectpicker form-control"
                                data-live-search="true"
                                data-size="10"
                                data-container="body"
                                data-width="100%"
                                data-title="Wybierz dostawcę..."
                                required>
                            <?php vp_render_options($vendor_list, $vendorId); ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="vp_edit_producer_id">Producent: <span class="text-danger">*</span></label>
                        <select id="vp_edit_producer_id" name="producer_id"
                                class="selectpicker form-control"
                                data-live-search="true"
                                data-size="10"
                                data-container="body"
                                data-width="100%"
                                data-title="Wybierz producenta..."
                                required>
                            <?php vp_render_options($producer_list, $producerId); ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label for="vp_edit_parts_id">Część (nasz katalog): <span class="text-danger">*</span></label>
                        <select id="vp_edit_parts_id" name="parts_id"
                                class="selectpicker form-control"
                                data-live-search="true"
                                data-size="10"
                                data-container="body"
                                data-width="100%"
                                data-title="Wybierz część..."
                                required>
                            <?php vp_render_options_with_subtext($part_names, $part_descriptions, $partsId); ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="vp_edit_vendor_jm_id">JM: <span class="text-danger">*</span></label>
                        <select id="vp_edit_vendor_jm_id" name="vendor_jm_id"
                                class="selectpicker form-control"
                                data-live-search="true"
                                data-size="10"
                                data-container="body"
                                data-width="100%"
                                data-title="Wybierz jednostkę miary..."
                                required>
                            <?php vp_render_options($unit_list, $vendorJmId); ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="vp_edit_vendor_part_no">Numer części u dostawcy: <span class="text-danger">*</span></label>
                        <input type="text" id="vp_edit_vendor_part_no" name="vendor_part_no"
                               class="form-control" required maxlength="255"
                               value="<?= htmlspecialchars($vendorPartNo, ENT_QUOTES) ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="vp_edit_producer_part_no">Numer części u producenta:</label>
                        <input type="text" id="vp_edit_producer_part_no" name="producer_part_no"
                               class="form-control" maxlength="255"
                               value="<?= htmlspecialchars($producerPartNo, ENT_QUOTES) ?>"
                               placeholder="opcjonalnie">
                        <small class="form-text text-muted">Pozostaw puste, jeżeli nie jest znany.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>Pełne opakowania:</label>
                    <div id="vpPackList">
                        <?php foreach ($packs as $idx => $packVal): ?>
                            <div class="vp-pack-row input-group">
                                <input type="number" name="pack_quantities[]"
                                       class="form-control"
                                       step="0.0001" min="0.0001"
                                       required
                                       value="<?= htmlspecialchars((string)$packVal, ENT_QUOTES) ?>"
                                       placeholder="np. 100">
                                <div class="input-group-append">
                                    <button type="button"
                                            class="btn btn-outline-danger vp-pack-remove"
                                            title="Usuń opakowanie">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="vpAddPackBtn" class="btn btn-outline-primary btn-sm mt-1">
                        <i class="bi bi-plus-circle"></i> Dodaj opakowanie
                    </button>
                    <div class="vp-pack-help">
                        Każde opakowanie to liczba sztuk w jednym pełnym opakowaniu u dostawcy
                        (np. <code>100</code> dla szpuli rezystorów). Wartości muszą być liczbami
                        dodatnimi i nie mogą się powtarzać. <strong>Pozostaw listę pustą,
                        jeżeli część nie jest pakowana</strong> (np. kupowana na sztuki).
                    </div>
                </div>

                <div class="form-group">
                    <label>Status:</label>
                    <div>
                        <label class="mr-3 mb-0">
                            <input type="radio" name="isActive" value="1" <?= $isActive ? 'checked' : '' ?>>
                            Aktywny
                        </label>
                        <label class="mb-0">
                            <input type="radio" name="isActive" value="0" <?= !$isActive ? 'checked' : '' ?>>
                            Nieaktywny
                        </label>
                    </div>
                    <small class="form-text text-muted">
                        Artykuły nieaktywne nie pojawiają się w domyślnym widoku listy ani w wyszukiwarkach
                        koszyka zakupowego.
                    </small>
                </div>

                <div class="form-group">
                    <label for="vp_edit_comment">Komentarz:</label>
                    <textarea id="vp_edit_comment" name="comment" class="form-control" rows="3"
                              placeholder="opcjonalne uwagi wewnętrzne"><?= htmlspecialchars($comment, ENT_QUOTES) ?></textarea>
                </div>

            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted">Pola oznaczone <span class="text-danger">*</span> są wymagane.</small>
                <div>
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Powrót
                    </a>
                    <button type="submit" class="btn btn-primary" id="vpSubmitBtn" disabled>
                        <?php if ($isEditMode): ?>
                            <i class="bi bi-check2-circle"></i> Zapisz zmiany
                        <?php else: ?>
                            <i class="bi bi-plus-circle"></i> Dodaj artykuł
                        <?php endif; ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/VendorParts/edit/vendor-part-edit-view.js') ?>"></script>
