// Koszyk — cascading vendor/part pickers plus a search modal that land
// items in a shared cart grouped by vendor (client-side state).
//
// Flow:
//   1. Pick a vendor → parts list narrows to what that vendor carries.
//      Pick a part → vendors list narrows accordingly.
//   2. "Numer u dostawcy" scopes by whatever constraint exists: vendor
//      picked → that vendor's items; part picked → that part's items
//      across vendors; both → combo alternates; neither → empty.
//   3. The magnifier icon next to it opens a search modal (AJAX endpoint
//      vendor-part-search.php) for finding items by vendor/producer part
//      no or part/vendor name across the whole catalog; picking a result
//      auto-selects its vendor AND part.
//   4. Qty / price / currency row is always visible; "Dodaj" enables once
//      a valid VendorPart is selected. Same vendor_part_id merges in qty
//      instead of duplicating.
//   5. Each vendor group in the cart has its own "Utwórz zapytanie"
//      / "Utwórz zamówienie" buttons — submits only that group's
//      items to POST /cart-action.php.
//   6. The cart persists across page refreshes via localStorage
//      (7-day expiry, cleared on submit and on "Wyczyść koszyk").

(function () {
    'use strict';

    let cart = {
        items:       [],     // {vendor_part_id, vendor_id, vendor_name, vendor_part_no,
                             //  producer_part_no, part_name, producer_name, unit_name,
                             //  vendor_jm_id, full_pack_quantity, quantity, unit_price,
                             //  currency}
        activeDocs:  {},     // vendor_part_id (string) → [doc, …]
    };

    // DOM refs
    let $vendorSelect        = $('#vendorSelect');
    let $partSelect          = $('#partSelect');
    let $vendorPartNoSelect  = $('#vendorPartNoSelect');
    let $cartPackages        = $('#cartPackages');
    let $cartQty             = $('#cartQty');
    let $cartPrice           = $('#cartPrice');
    let $cartCurrency        = $('#cartCurrency');
    let $variantInfoRow      = $('#variantInfoRow');
    let $variantCommentDisplay = $('#variantCommentDisplay');
    let $variantCommentText  = $('#variantCommentText');
    let $variantCommentEdit  = $('#variantCommentEdit');
    let $variantCommentEditBox = $('#variantCommentEditBox');
    let $variantCommentInput = $('#variantCommentInput');
    let $editItemModal       = $('#editItemModal');
    let $editItemHeader      = $('#editItemHeader');
    let $editItemPackages    = $('#editItemPackages');
    let $editItemQty         = $('#editItemQty');
    let $editItemPrice       = $('#editItemPrice');
    let $editItemCurrency    = $('#editItemCurrency');
    let $editItemSave        = $('#editItemSave');
    let $addToCartBtn        = $('#addToCartBtn');
    let $cartCard            = $('#cartCard');
    let $cartBody            = $('#cartBody');
    let $cartCount           = $('#cartCount');
    let $clearBtn            = $('#clearCartBtn');
    let $selectionDocsCard   = $('#selectionDocsCard');
    let $selectionDocsHeader = $('#selectionDocsHeader');
    let $selectionDocsBody   = $('#selectionDocsBody');
    let $selectionDocsContent = $('#selectionDocsContent');
    let $selectionDocsCount  = $('#selectionDocsCount');
    let $vpSearchModal  = $('#vpSearchModal');
    let $vpSearchInput  = $('#vpSearchInput');
    let $vpSearchStatus = $('#vpSearchStatus');
    let $vpSearchResults = $('#vpSearchResults');
    let vpSearchRows = [];  // last endpoint response, indexed for row buttons
    let vpSearchTimer = null;
    let lastResolvedVpId = null;   // variant id the amount inputs belong to
    // Last fetched strip payload: { options: [vp,…], docs: {vpId: [doc,…]} }
    let selectionDocsCache   = { options: [], docs: {} };

    // ---- cart persistence (localStorage, survives refresh) ----
    let CART_STORAGE_KEY = 'atte_purchase_cart_v1';
    let CART_MAX_AGE_MS  = 7 * 24 * 60 * 60 * 1000;   // 7 days

    function saveCart() {
        try {
            window.localStorage.setItem(CART_STORAGE_KEY, JSON.stringify({
                savedAt: Date.now(),
                items: cart.items
            }));
        } catch (e) { /* storage unavailable — in-memory only */ }
    }

    function clearSavedCart() {
        try { window.localStorage.removeItem(CART_STORAGE_KEY); } catch (e) { /* noop */ }
    }

    function loadSavedCart() {
        try {
            let raw = window.localStorage.getItem(CART_STORAGE_KEY);
            if (!raw) return;
            let data = JSON.parse(raw);
            if (!data || !Array.isArray(data.items)) return;
            if (!data.savedAt || (Date.now() - data.savedAt) > CART_MAX_AGE_MS) {
                clearSavedCart();
                return;
            }
            // Light sanity filter — drop malformed entries.
            cart.items = data.items.filter(function (i) {
                return i && (parseInt(i.vendor_part_id, 10) || 0) > 0
                    && (parseFloat(i.quantity) || 0) > 0;
            });
        } catch (e) { /* corrupt/unavailable — start empty */ }
    }

    // ---- helpers ----

    function refreshSelectpicker($el) {
        if (typeof $el.selectpicker === 'function') {
            try { $el.selectpicker('refresh'); } catch (e) { /* noop */ }
        }
    }

    function setAlert(msg, kind) {
        let $box = $('#alertContainer');
        if (!msg) { $box.empty(); return; }
        $box.html(
            '<div class="alert alert-' + (kind || 'info') + ' alert-dismissible fade show" role="alert">' +
            msg +
            '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
            '</div>'
        );
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatQty(n) {
        let v = parseFloat(n);
        if (isNaN(v)) return '';
        return v.toString();
    }

    function formatPrice(n) {
        if (n === null || n === undefined || n === '') return '—';
        let v = parseFloat(n);
        if (isNaN(v)) return '—';
        return v.toFixed(4).replace(/\.?0+$/, '');
    }

    function stateBadgeClass(state) {
        switch (state) {
            case 'draft':              return 'badge-secondary';
            case 'sent':               return 'badge-primary';
            case 'responded':          return 'badge-info';
            case 'confirmed':          return 'badge-info';
            case 'partially_received': return 'badge-warning';
            case 'received':           return 'badge-success';
            case 'cancelled':          return 'badge-danger';
            default:                   return 'badge-secondary';
        }
    }

    function stateBadgeLabel(state) {
        return ({
            draft: 'szkic', sent: 'wysłane', responded: 'odpowiedź',
            confirmed: 'potwierdzone', partially_received: 'częściowo odebrane',
            received: 'odebrane', cancelled: 'anulowane'
        })[state] || state;
    }

    function docTypeIcon(docType) {
        return docType === 'rfq' ? '⚠' : '📦';
    }

    function safeJsonArray(raw) {
        if (!raw) return [];
        try {
            let v = JSON.parse(raw);
            // Defensive: GROUP_CONCAT output double-encoded as a JSON string
            // ("1,2,3") parses to a string, not an array — split it.
            if (typeof v === 'string') {
                return v.split(',').map(function (n) { return parseInt(n, 10); }).filter(function (n) { return !isNaN(n); });
            }
            return Array.isArray(v) ? v : [];
        } catch (e) { return []; }
    }

    // ---- cascading-filter logic ----

    function applyVendorFilter() {
        let vendorId = parseInt($vendorSelect.val(), 10) || null;
        let partsIds = [];
        if (vendorId) {
            let $opt = $vendorSelect.find('option:selected');
            partsIds = safeJsonArray($opt.attr('data-parts'));
        }
        let partId = parseInt($partSelect.val(), 10) || null;

        $partSelect.find('option').each(function () {
            let id = parseInt($(this).val(), 10) || 0;
            if (id === 0) return; // skip the placeholder
            let hide = !!(vendorId && partsIds.indexOf(id) === -1);
            $(this).prop('hidden', hide);
        });
        refreshSelectpicker($partSelect);
        // If currently selected part is now hidden, clear it
        if (partId && $partSelect.find('option[value="' + partId + '"]').prop('hidden')) {
            $partSelect.val('');
            refreshSelectpicker($partSelect);
        }
    }

    function applyPartFilter() {
        let partId = parseInt($partSelect.val(), 10) || null;
        let vendorsIds = [];
        if (partId) {
            let $opt = $partSelect.find('option:selected');
            vendorsIds = safeJsonArray($opt.attr('data-vendors'));
        }
        let vendorId = parseInt($vendorSelect.val(), 10) || null;

        $vendorSelect.find('option').each(function () {
            let id = parseInt($(this).val(), 10) || 0;
            if (id === 0) return;
            let hide = !!(partId && vendorsIds.indexOf(id) === -1);
            $(this).prop('hidden', hide);
        });
        refreshSelectpicker($vendorSelect);
        if (vendorId && $vendorSelect.find('option[value="' + vendorId + '"]').prop('hidden')) {
            $vendorSelect.val('');
            refreshSelectpicker($vendorSelect);
        }
    }

    // ---- three-way picker sync ----
    // Vendor/part filter each other's options. "Numer u dostawcy" starts
    // empty and scopes by whatever constraint exists: vendor picked →
    // that vendor's items; part picked → that part's items across
    // vendors; both → combo alternates. Catalog-wide lookup lives in
    // the search modal.

    function updateAddBtnState() {
        let $sel = $vendorPartNoSelect.find('option:selected');
        let ok = $sel.length > 0
            && !$sel.prop('hidden')
            && (parseInt($sel.attr('data-vp-id'), 10) || 0) > 0;
        $addToCartBtn.prop('disabled', !ok);
    }

    // Full-pack quantity of the currently selected variant (null when the
    // variant has no usable pack size or nothing is selected).
    function selectedFullPackQty() {
        let raw = $vendorPartNoSelect.find('option:selected').attr('data-full-pack-quantity');
        let v = parseFloat(raw);
        return (!isNaN(v) && v > 0) ? v : null;
    }

    // Full-pack quantity stored on a cart item (null when unusable).
    // Modal-safe counterpart of selectedFullPackQty() — the edit modal
    // operates on an arbitrary cart line, not the picker row's selection.
    function itemFullPackQty(item) {
        if (!item || item.full_pack_quantity === null || item.full_pack_quantity === undefined) return null;
        let v = parseFloat(item.full_pack_quantity);
        return (!isNaN(v) && v > 0) ? v : null;
    }

    // Clears the packages input and enables it only when the selected
    // variant has a usable full_pack_quantity.
    function resetPackagesInput() {
        let fpq = selectedFullPackQty();
        $cartPackages.val('');
        $cartPackages.removeClass('packages-uneven');
        $cartPackages.prop('disabled', fpq === null);
    }

    // Recomputes the Opak. field from Ilość (qty / full pack quantity)
    // and flags fractional package counts in yellow — non-blocking.
    function updatePackagesDisplay() {
        let fpq = selectedFullPackQty();
        let qty = parseFloat($cartQty.val());
        if (fpq === null || isNaN(qty) || qty < 0) {
            $cartPackages.removeClass('packages-uneven');
            return;
        }
        let pkgs = qty / fpq;
        let even = Math.abs(pkgs - Math.round(pkgs)) < 1e-9;
        $cartPackages.val(parseFloat(pkgs.toFixed(2)));
        $cartPackages.toggleClass('packages-uneven', !even);
        $cartPackages.attr('title', even ? 'Ilość = opakowania × ilość w opakowaniu'
                                         : 'Uwaga: ilość nie odpowiada pełnej liczbie opakowań');
    }

    // Qty placeholder mirrors the selected variant's unit (JM) from DB.
    function syncQtyPlaceholder() {
        let $sel = $vendorPartNoSelect.find('option:selected');
        let unit = ($sel.length > 0 ? $sel.attr('data-unit-name') : '') || 'szt.';
        $cartQty.attr('placeholder', unit);
    }

    // Ilość/Cena/Waluta are editable only once a concrete variant is
    // selected; while ambiguous they're disabled and qty/price cleared.
    function syncAmountsInputs() {
        let $sel = $vendorPartNoSelect.find('option:selected');
        let resolved = $sel.length > 0
            && !$sel.prop('hidden')
            && (parseInt($sel.attr('data-vp-id'), 10) || 0) > 0;
        $cartQty.prop('disabled', !resolved);
        $cartPrice.prop('disabled', !resolved);
        $cartCurrency.prop('disabled', !resolved);
        if (!resolved) {
            $cartQty.val('');
            $cartPrice.val('');
            $cartPackages.val('').prop('disabled', true).removeClass('packages-uneven')
                .attr('placeholder', 'opak.');
            $variantInfoRow.hide();
        } else {
            // Informative placeholder: how many units one package holds.
            let fpq = selectedFullPackQty();
            $cartPackages.attr('placeholder', fpq !== null ? formatQty(fpq) + '/opak.' : 'opak.');
            $variantInfoRow.show();
            renderVariantComment();
        }
        syncQtyPlaceholder();
    }

    function getScopedVpOptions(vendorId, partId) {
        let out = [];
        Object.keys(VENDOR_PARTS_INDEX).forEach(function (key) {
            let parts = key.split(':');
            let vid = parseInt(parts[0], 10);
            let pid = parseInt(parts[1], 10);
            if (vendorId && vid !== vendorId) return;
            if (partId && pid !== partId) return;
            VENDOR_PARTS_INDEX[key].forEach(function (vp) { out.push(vp); });
        });
        return out;
    }

    // Live lookup of a VendorPart entry by id (comment edits update the
    // index in place, so cart rows re-render fresh).
    function getVpById(vpId) {
        let found = null;
        Object.keys(VENDOR_PARTS_INDEX).forEach(function (key) {
            if (found) return;
            VENDOR_PARTS_INDEX[key].forEach(function (vp) { if (vp.id === vpId) found = vp; });
        });
        return found;
    }

    function selectedVpEntry() {
        let $sel = $vendorPartNoSelect.find('option:selected');
        let vpId = parseInt($sel.attr('data-vp-id'), 10) || null;
        return vpId !== null ? getVpById(vpId) : null;
    }

    // Read-only private comment line for the selected variant.
    function renderVariantComment() {
        let vp = selectedVpEntry();
        let cmt = vp ? (vp.private_comment || '') : '';
        $variantCommentText.text(cmt !== '' ? cmt : 'Brak komentarza');
        $variantCommentDisplay.show();
        $variantCommentEditBox.hide();
    }

    // Reads the currently selected variant option and auto-fills whichever
    // of vendor/part is still unset or mismatched. Returns true when the
    // combo changed; the variant list then narrows via a deferred rebuild.
    function completeComboFromSelection() {
        let $opt = $vendorPartNoSelect.find('option:selected');
        if ($opt.length === 0) return false;
        let vid = parseInt($opt.attr('data-vendor-id'), 10) || null;
        let pid = parseInt($opt.attr('data-part-id'), 10) || null;
        let vpid = parseInt($opt.attr('data-vp-id'), 10) || null;
        let curVid = parseInt($vendorSelect.val(), 10) || null;
        let curPid = parseInt($partSelect.val(), 10) || null;
        let changed = false;
        if (vid && vid !== curVid) {
            $vendorSelect.val(vid);
            refreshSelectpicker($vendorSelect);
            applyVendorFilter();   // scope parts to this vendor
            changed = true;
        }
        if (pid && pid !== curPid) {
            $partSelect.val(pid);
            refreshSelectpicker($partSelect);
            applyPartFilter();     // scope vendors to this part
            changed = true;
        }
        if (changed) {
            // Defer the rebuild until bootstrap-select finishes its own
            // change dispatch — mutating the select mid-dispatch lets
            // bs-select's post-change render wipe the fresh selection.
            setTimeout(function () { refreshVendorPartRow(vpid); }, 0);
        }
        return changed;
    }

    function refreshVendorPartRow(preferredVpId) {
        let vendorId = parseInt($vendorSelect.val(), 10) || null;
        let partId = parseInt($partSelect.val(), 10) || null;
        // Nothing constrained → keep the picker empty.
        if (!vendorId && !partId) {
            $vendorPartNoSelect.empty();
            refreshSelectpicker($vendorPartNoSelect);
            lastResolvedVpId = null;
            syncAmountsInputs();
            $addToCartBtn.prop('disabled', true);
            return;
        }
        let options = getScopedVpOptions(vendorId, partId);
        if (options.length === 0) {
            $vendorPartNoSelect.empty();
            refreshSelectpicker($vendorPartNoSelect);
            lastResolvedVpId = null;
            syncAmountsInputs();
            $addToCartBtn.prop('disabled', true);
            return;
        }
        // Group by producer (bootstrap-select renders optgroup headers),
        // preserving first-seen order.
        let groups = {};
        let groupOrder = [];
        options.forEach(function (vp) {
            let g = vp.producer_name || 'Bez producenta';
            if (!groups[g]) { groups[g] = []; groupOrder.push(g); }
            groups[g].push(vp);
        });
        let html = '';
        groupOrder.forEach(function (g) {
            html += '<optgroup label="' + escapeHtml(g) + '">';
            groups[g].forEach(function (vp) {
                // Producer part no rides as subtext (searchable) unless
                // identical to the vendor part no.
                let subtext = (vp.producer_part_no && vp.producer_part_no !== vp.vendor_part_no)
                    ? ' data-subtext="' + escapeHtml(vp.producer_part_no) + '"'
                    : '';
                html += '<option value="' + vp.id + '"' +
                    subtext +
                    ' data-vp-id="' + vp.id + '"' +
                    ' data-vendor-id="' + vp.vendor_id + '"' +
                    ' data-part-id="' + vp.parts_id + '"' +
                    ' data-vendor-part-no="' + escapeHtml(vp.vendor_part_no) + '"' +
                    ' data-producer-part-no="' + escapeHtml(vp.producer_part_no || '') + '"' +
                    ' data-vendor-jm-id="' + vp.vendor_jm_id + '"' +
                    ' data-unit-name="' + escapeHtml(vp.unit_name) + '"' +
                    ' data-full-pack-quantity="' + vp.full_pack_quantity + '">' +
                    escapeHtml(vp.vendor_part_no) +
                    '</option>';
            });
            html += '</optgroup>';
        });
        $vendorPartNoSelect.html(html);
        // Pre-select ONLY on an explicit hand-off (search modal) or when
        // exactly one option exists — otherwise the user chooses.
        let preferred = null;
        if (preferredVpId) {
            options.forEach(function (vp) { if (vp.id === preferredVpId) preferred = vp.id; });
        }
        let targetId = null;
        if (preferred !== null) {
            targetId = String(preferred);
        } else if (options.length === 1) {
            targetId = String(options[0].id);
        }
        // Explicit selectpicker('val') + double refresh — plain .val()
        // alone proved unreliable on freshly-built option lists.
        refreshSelectpicker($vendorPartNoSelect);
        if (targetId !== null && typeof $vendorPartNoSelect.selectpicker === 'function') {
            try { $vendorPartNoSelect.selectpicker('val', targetId); } catch (e) { /* noop */ }
            refreshSelectpicker($vendorPartNoSelect);
        }
        // Amount inputs belong to a concrete device — clear them when it
        // changed, keep them otherwise.
        let resolvedId = targetId !== null ? parseInt(targetId, 10) : null;
        if (resolvedId !== lastResolvedVpId) {
            $cartQty.val('');
            $cartPrice.val('');
            resetPackagesInput();
            lastResolvedVpId = resolvedId;
        } else {
            // Same device — just re-evaluate enable/disable by fpq.
            $cartPackages.prop('disabled', selectedFullPackQty() === null);
        }
        syncAmountsInputs();
        updateAddBtnState();
        // A programmatic pre-selection doesn't fire change — complete the
        // combo (auto-pick vendor/part) manually.
        if (targetId !== null) {
            completeComboFromSelection();
        }
    }

    // ---- modal search flow ----

    function renderVpSearchResults(rows) {
        vpSearchRows = rows || [];
        let html = '';
        vpSearchRows.forEach(function (r, i) {
            html += '<tr>' +
                '<td>' + escapeHtml(r.vendor_part_no) + '</td>' +
                '<td>' + (r.producer_part_no ? escapeHtml(r.producer_part_no) : '—') + '</td>' +
                '<td>' + (r.producer_name ? escapeHtml(r.producer_name) : '—') + '</td>' +
                '<td>' + escapeHtml(r.vendor_name) + '</td>' +
                '<td>' + escapeHtml(r.part_name) + '</td>' +
                '<td>' + escapeHtml(r.unit_name) + ' · opak. ' + formatQty(r.full_pack_quantity) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-primary vp-pick-btn" data-idx="' + i + '">Wybierz</button></td>' +
                '</tr>';
        });
        $vpSearchResults.html(html);
    }

    function runVpSearch() {
        let q = $.trim($vpSearchInput.val());
        if (q.length < 2) {
            $vpSearchStatus.removeClass('text-danger').text('Wpisz co najmniej 2 znaki…').show();
            renderVpSearchResults([]);
            return;
        }
        $vpSearchStatus.removeClass('text-danger').text('Szukam…').show();
        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart//vendor-part-search.php?q=' + encodeURIComponent(q),
            method: 'GET',
            dataType: 'json'
        }).done(function (rows) {
            rows = rows || [];
            renderVpSearchResults(rows);
            $vpSearchStatus
                .removeClass('text-danger')
                .text(rows.length ? rows.length + ' wynik(ów).' : 'Brak wyników dla „' + q + '”.')
                .show();
        }).fail(function () {
            renderVpSearchResults([]);
            $vpSearchStatus.addClass('text-danger').text('Błąd pobierania danych.').show();
        });
    }

    // Picking a search result drives the other two pickers, then hands
    // the chosen id to refreshVendorPartRow so the populated alternates
    // list selects it (programmatic .val() does NOT fire change).
    function pickVp(r) {
        $vendorSelect.val(r.vendor_id);
        refreshSelectpicker($vendorSelect);
        $partSelect.val(r.parts_id);
        refreshSelectpicker($partSelect);
        applyVendorFilter();   // scope parts to this vendor
        applyPartFilter();     // scope vendors to this part
        refreshVendorPartRow(r.id);
        updateSelectionDocsBadge();
        loadSelectionDocs();
        $vpSearchModal.modal('hide');
    }

    // ---- active-docs / cart rendering ----

    // Active docs for the *currently picked* vendor+part combo — shown
    // in the strip card above the cart as soon as both pickers hold a
    // value (no need to add anything to the cart first).
    function loadSelectionDocs() {
        let vendorId = parseInt($vendorSelect.val(), 10) || null;
        let partId = parseInt($partSelect.val(), 10) || null;
        if (!vendorId || !partId) {
            $selectionDocsCard.hide();
            $selectionDocsBody.collapse('hide');
            $selectionDocsContent.empty();
            selectionDocsCache = { options: [], docs: {} };
            return;
        }
        let options = VENDOR_PARTS_INDEX[vendorId + ':' + partId] || [];
        let vpIds = options.map(function (vp) { return vp.id; });
        if (vpIds.length === 0) {
            $selectionDocsCard.hide();
            return;
        }
        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart//cart-active-docs.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(vpIds),
            dataType: 'json'
        }).done(function (docs) {
            renderSelectionDocs(options, docs || {});
        }).fail(function () {
            renderSelectionDocs(options, {});
        });
    }

    function renderSelectionDocs(options, docs) {
        selectionDocsCache = { options: options, docs: docs };
        let html = '';
        let total = 0;
        options.forEach(function (vp) {
            let list = docs[vp.id] || [];
            if (list.length === 0) return;
            total += list.length;
            html += '<div class="mb-1"><strong>' + escapeHtml(vp.vendor_part_no) + '</strong>' +
                ' <span class="text-muted">(' + list.length + ')</span>: ';
            list.forEach(function (d) {
                html += '<span class="badge ' + stateBadgeClass(d.state) + ' mr-1" title="' +
                    stateBadgeLabel(d.state) + ' · ' + formatQty(d.quantity) + ' szt. · ' + formatPrice(d.unit_price) + '">' +
                    docTypeIcon(d.doc_type) + ' ' + escapeHtml(d.number) + ' · ' + stateBadgeLabel(d.state) +
                    '</span>';
            });
            html += '</div>';
        });
        if (total === 0) {
            html = '<span class="text-muted">Brak aktywnych dokumentów dla tej pozycji.</span>';
        }
        $selectionDocsContent.html(html);
        $selectionDocsCard.show();
        updateSelectionDocsBadge();
    }

    // Header badge reflects the *specific* VendorPart currently picked
    // in "Numer u dostawcy" — not the combo-wide total.
    function updateSelectionDocsBadge() {
        let $opt = $vendorPartNoSelect.find('option:selected');
        let vpId = parseInt($opt.attr('data-vp-id'), 10) || null;
        let list = (vpId !== null && selectionDocsCache.docs[vpId]) ? selectionDocsCache.docs[vpId] : [];
        if (list.length > 0) {
            $selectionDocsCount.text(list.length).show();
        } else {
            $selectionDocsCount.hide();
        }
    }

    function loadActiveDocs() {
        if (cart.items.length === 0) {
            cart.activeDocs = {};
            renderCart();
            return;
        }
        let vpIds = cart.items.map(function (i) { return i.vendor_part_id; });
        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart//cart-active-docs.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(vpIds),
            dataType: 'json'
        }).done(function (docs) {
            cart.activeDocs = docs || {};
            renderCart();
        }).fail(function () {
            cart.activeDocs = {};
            renderCart();
        });
    }

    function renderCart() {
        if (cart.items.length === 0) {
            $cartCard.hide();
            return;
        }
        $cartCard.show();
        $cartCount.text(cart.items.length);

        let byVendor = {};
        cart.items.forEach(function (item, idx) {
            if (!byVendor[item.vendor_id]) {
                byVendor[item.vendor_id] = { name: item.vendor_name, items: [] };
            }
            byVendor[item.vendor_id].items.push({ item: item, idx: idx });
        });

        let html = '';
        Object.keys(byVendor).forEach(function (vid) {
            let group = byVendor[vid];
            let groupValue = group.items.reduce(function (sum, x) {
                return sum + (x.item.unit_price ? x.item.unit_price * x.item.quantity : 0);
            }, 0);

            html += '<div class="vendor-group mb-4" data-vendor-id="' + vid + '">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                '<h6 class="mb-0">' +
                    '<a href="#" class="text-muted select-vendor-link select-vendor-btn mr-1" data-vendor-id="' + vid + '" title="Wybierz tego dostawcę w selektorze, aby dodać kolejne pozycje"><i class="bi bi-plus-circle"></i></a>' +
                    escapeHtml(group.name) +
                    ' <small class="text-muted">(' + group.items.length + ' poz. · łącznie ' + formatPrice(groupValue) + ')</small>' +
                '</h6>' +
                '<div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger clear-vendor-items-btn" data-vendor-id="' + vid + '" title="Usuń wszystkie pozycje tego dostawcy z koszyka">' +
                        '<i class="bi bi-trash"></i></button>' +
                '</div>' +
                '</div>' +
                '<div class="table-responsive">' +
                '<table class="table table-sm table-striped">' +
                '<thead class="thead-light">' +
                '<tr>' +
                    '<th>Część</th>' +
                    '<th>JM</th>' +
                    '<th>Ilość</th>' +
                    '<th>Cena</th>' +
                    '<th>Waluta</th>' +
                    '<th>Wartość</th>' +
                    '<th>Aktywne dok.</th>' +
                    '<th>Akcje</th>' +
                '</tr>' +
                '</thead><tbody>';

            group.items.forEach(function (x) {
                let item = x.item;
                let lineTotal = (item.unit_price ? item.unit_price * item.quantity : 0);
                let docs = cart.activeDocs[item.vendor_part_id] || [];
                let docBadges = '';
                docs.forEach(function (d) {
                    docBadges += '<span class="badge ' + stateBadgeClass(d.state) + ' mr-1" title="' +
                        stateBadgeLabel(d.state) + ' · ' + formatQty(d.quantity) + ' · ' + formatPrice(d.unit_price) + '">' +
                        docTypeIcon(d.doc_type) + ' ' + escapeHtml(d.number) + ' · ' + stateBadgeLabel(d.state) +
                        '</span>';
                });
                if (!docBadges) {
                    docBadges = '<span class="text-muted">—</span>';
                }

                // Część cell carries prefixed sub-lines: vendor/producer
                // numbers (merged when identical) and the description.
                let vn = item.vendor_part_no || '';
                let pn = item.producer_part_no || '';
                let partCell = escapeHtml(item.part_name);
                if (vn && pn && vn === pn) {
                    partCell += '<div><small class="text-muted">Nr dost./prod.: ' + escapeHtml(vn) + '</small></div>';
                } else {
                    if (vn) partCell += '<div><small class="text-muted">Nr dost.: ' + escapeHtml(vn) + '</small></div>';
                    if (pn) partCell += '<div><small class="text-muted">Nr prod.: ' + escapeHtml(pn) + '</small></div>';
                }
                if (item.description) {
                    partCell += '<div><small class="text-muted">' + escapeHtml(item.description) + '</small></div>';
                }
                // Comment is variant-level — read live so pen-edits show up
                // in cart rows immediately.
                let vpLive = getVpById(item.vendor_part_id);
                let liveCmt = vpLive ? (vpLive.private_comment || '') : '';
                if (liveCmt) {
                    partCell += '<div><small class="text-muted"><i class="bi bi-journal-text"></i> Komentarz: ' + escapeHtml(liveCmt) + '</small></div>';
                }
                // Ilość cell carries the package count as a sub-line
                // (yellow when qty doesn't match whole packages).
                let qtyCell = formatQty(item.quantity);
                if (item.full_pack_quantity && item.full_pack_quantity > 0) {
                    let pkgs = item.quantity / item.full_pack_quantity;
                    let evenPkgs = Math.abs(pkgs - Math.round(pkgs)) < 1e-9;
                    qtyCell += '<div><small class="' + (evenPkgs ? 'text-muted' : 'text-warning') + '">' +
                        parseFloat(pkgs.toFixed(2)) + ' opak.</small></div>';
                }
                html += '<tr>' +
                    '<td>' + partCell + '</td>' +
                    '<td>' + escapeHtml(item.unit_name) + '</td>' +
                    '<td>' + qtyCell + '</td>' +
                    '<td>' + formatPrice(item.unit_price) + '</td>' +
                    '<td>' + escapeHtml(item.currency) + '</td>' +
                    '<td>' + formatPrice(lineTotal) + '</td>' +
                    '<td style="white-space: nowrap;">' + docBadges + '</td>' +
                    '<td style="white-space: nowrap;">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary edit-item-btn mr-1" data-idx="' + x.idx + '" title="Edytuj ilość / cenę / walutę"><i class="bi bi-pencil"></i></button>' +
                        '<button type="button" class="btn btn-sm btn-danger remove-item-btn" data-idx="' + x.idx + '" title="Usuń pozycję">' +
                            '<i class="bi bi-trash"></i></button>' +
                    '</td>' +
                    '</tr>';
            });

            html += '</tbody></table></div>' +
                '<div class="d-flex justify-content-end">' +
                    '<button type="button" class="btn btn-primary mr-2 create-rfq-btn" data-vendor-id="' + vid + '">' +
                    '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie</button>' +
                    '<button type="button" class="btn btn-success create-po-btn" data-vendor-id="' + vid + '">' +
                    '<i class="bi bi-bag-check"></i> Utwórz zamówienie</button>' +
                '</div>' +
                '</div>';
        });
        $cartBody.html(html);
    }

    function addCurrentSelectionToCart() {
        if ($addToCartBtn.prop('disabled')) return;
        let vendorId = parseInt($vendorSelect.val(), 10) || null;
        let partId = parseInt($partSelect.val(), 10) || null;
        let $opt = $vendorPartNoSelect.find('option:selected');
        let vendorPartId = parseInt($opt.attr('data-vp-id'), 10) || null;
        let vendorPartNo = $opt.attr('data-vendor-part-no') || '';
        let producerPartNo = $opt.attr('data-producer-part-no') || null;
        let qty = parseFloat($cartQty.val());
        let priceRaw = $cartPrice.val() === '' ? NaN : parseFloat($cartPrice.val());
        let unitPrice = (!isNaN(priceRaw) && priceRaw >= 0) ? priceRaw : null;
        let currency = $cartCurrency.val() || 'PLN';
        if (!vendorId || !partId || !vendorPartId || isNaN(qty) || qty <= 0) {
            setAlert('Podaj prawidłową ilość.', 'warning');
            return;
        }
        if (!isNaN(priceRaw) && priceRaw < 0) {
            setAlert('Cena nie może być ujemna.', 'warning');
            return;
        }
        let $vendorOpt = $vendorSelect.find('option:selected');
        let $partOpt = $partSelect.find('option:selected');
        // Merge rule: same vendor-part, same currency, same unit price
        // (including both unpriced). A different price OR currency means
        // the user intends a separate line — do NOT silently overwrite
        // the previous line's price, just push a new row.
        let existing = cart.items.find(function (i) {
            return i.vendor_part_id === vendorPartId
                && i.currency === currency
                && (i.unit_price === unitPrice ||
                    (i.unit_price === null && unitPrice === null));
        });
        if (existing) {
            existing.quantity += qty;
            setAlert('Dodano ' + qty + ' do istniejącej pozycji (id ' + vendorPartId + ').', 'success');
        } else {
            cart.items.push({
                vendor_part_id    : vendorPartId,
                vendor_id         : vendorId,
                vendor_name       : $vendorOpt.attr('data-name') || '',
                vendor_part_no    : vendorPartNo,
                producer_part_no  : producerPartNo,
                part_name         : $partOpt.attr('data-name') || '',
                description       : $partOpt.attr('data-subtext') || '',
                producer_name     : '',
                unit_name         : $opt.attr('data-unit-name') || '',
                vendor_jm_id      : parseInt($opt.attr('data-vendor-jm-id'), 10) || null,
                full_pack_quantity: parseFloat($opt.attr('data-full-pack-quantity')) || null,
                quantity          : qty,
                unit_price        : unitPrice,
                currency          : currency
            });
            setAlert('Dodano pozycję do koszyka.', 'success');
        }
        // Reset qty + price, clear the part picker (vendor stays selected
        // so the user can queue the next part from the same vendor).
        // Currency stays sticky — usually several items in a row share it.
        $cartQty.val('');
        $cartPrice.val('');
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        applyPartFilter();          // part cleared → restore full vendor list
        refreshVendorPartRow();     // combo incomplete → empties picker, add disabled
        loadSelectionDocs();
        renderCart();
        loadActiveDocs();
        saveCart();
    }

    function buildPayload(docType, vendorId, items) {
        return {
            doc_type  : docType,
            vendor_id : vendorId,
            date      : '',
            comment   : '',
            items     : JSON.stringify(items.map(function (i) {
                return {
                    vendor_part_id : i.vendor_part_id,
                    quantity       : i.quantity,
                    unit_price     : i.unit_price === null ? '' : i.unit_price,
                    currency       : i.currency
                };
            }))
        };
    }

    function submit(docType, btn) {
        let $btn = $(btn);
        let vendorId = parseInt($btn.data('vendor-id'), 10);
        if (!vendorId) { setAlert('Brak identyfikatora dostawcy.', 'danger'); return; }
        let items = cart.items.filter(function (i) { return i.vendor_id === vendorId; });
        if (items.length === 0) { setAlert('Brak pozycji dla tego dostawcy.', 'warning'); return; }
        $btn.prop('disabled', true).text('Tworzę...');
        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart//cart-action.php',
            method: 'POST',
            data: buildPayload(docType, vendorId, items),
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success) {
                setAlert('Utworzono dokument. Przekierowuję...', 'success');
                clearSavedCart();   // submitted — don't restore these items
                window.location.href = response.redirect;
            } else {
                setAlert('Błąd: ' + (response && response.error ? response.error : 'nieznany'), 'danger');
                $btn.prop('disabled', false).html(docType === 'po'
                    ? '<i class="bi bi-bag-check"></i> Utwórz zamówienie'
                    : '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie');
            }
        }).fail(function (xhr) {
            setAlert('Błąd HTTP ' + xhr.status, 'danger');
            $btn.prop('disabled', false).html(docType === 'po'
                ? '<i class="bi bi-bag-check"></i> Utwórz zamówienie'
                : '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie');
        });
    }

    // ---- event handlers ----

    $vendorSelect.on('change', function () {
        applyVendorFilter();
        refreshVendorPartRow();
        loadSelectionDocs();
    });

    $partSelect.on('change', function () {
        applyPartFilter();
        refreshVendorPartRow();
        loadSelectionDocs();
    });

    // Picking a variant from a partially-scoped list completes the combo.
    $vendorPartNoSelect.on('change', function () {
        completeComboFromSelection();
        updateSelectionDocsBadge();
        loadSelectionDocs();
    });

    // Magnifier next to the vp picker opens the global search modal.
    $('#vpSearchBtn').on('click', function (e) {
        e.preventDefault();
        $vpSearchModal.modal('show');
    });

    $vpSearchModal.on('shown.bs.modal', function () {
        $vpSearchInput.trigger('focus');
    });

    $vpSearchInput.on('input', function () {
        if (vpSearchTimer) { clearTimeout(vpSearchTimer); }
        vpSearchTimer = setTimeout(runVpSearch, 300);
    });

    $vpSearchResults.on('click', '.vp-pick-btn', function () {
        let idx = parseInt($(this).data('idx'), 10);
        let row = vpSearchRows[idx];
        if (!row) return;
        pickVp(row);
    });

    // Chevron flip driven by real collapse events (CSS aria-expanded
    // selector kept as backup).
    $selectionDocsBody.on('show.bs.collapse', function () {
        $selectionDocsHeader.addClass('is-open');
    }).on('hide.bs.collapse', function () {
        $selectionDocsHeader.removeClass('is-open');
    });

    // "Wyczysc" (next to Dodaj) clears the whole selection; the variant
    // picker empties and both pickers' full option lists are restored.
    $('#clearSelectionBtn').on('click', function () {
        $vendorSelect.val('');
        refreshSelectpicker($vendorSelect);
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        applyVendorFilter();   // vendor cleared → restore full parts list
        applyPartFilter();     // part cleared → restore full vendors list
        refreshVendorPartRow();
        loadSelectionDocs();
    });

    // Opak. → Ilość: qty = packages × full pack quantity.
    $cartPackages.on('input', function () {
        let fpq = selectedFullPackQty();
        let pkgs = parseFloat($(this).val());
        if (fpq === null || isNaN(pkgs) || pkgs < 0) {
            $(this).removeClass('packages-uneven');
            return;
        }
        $cartQty.val(parseFloat((pkgs * fpq).toFixed(6)));
        let even = Math.abs(pkgs - Math.round(pkgs)) < 1e-9;
        $(this).toggleClass('packages-uneven', !even);
        $(this).attr('title', even ? 'Ilość = opakowania × ilość w opakowaniu'
                                   : 'Uwaga: ilość nie odpowiada pełnej liczbie opakowań');
    });

    // Inline edit of the variant's private comment — saves immediately
    // to list__vendor_part.comment via vendor-part-comment.php.
    $variantCommentEdit.on('click', function (e) {
        e.preventDefault();
        let vp = selectedVpEntry();
        if (!vp) return;
        $variantCommentDisplay.hide();
        $variantCommentInput.val(vp.private_comment || '');
        $variantCommentEditBox.show();
        $variantCommentInput.trigger('focus');
    });

    function cancelVariantCommentEdit() {
        $variantCommentEditBox.hide();
        $variantCommentDisplay.show();
    }

    function saveVariantComment() {
        let vp = selectedVpEntry();
        if (!vp) { cancelVariantCommentEdit(); return; }
        let val = $.trim($variantCommentInput.val());
        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart//vendor-part-comment.php',
            method: 'POST',
            data: { vp_id: vp.id, comment: val },
            dataType: 'json'
        }).done(function (resp) {
            if (resp && resp.success) {
                vp.private_comment = (val === '' ? null : val);   // cache in place
                renderCart();                                     // refresh table sub-line
                setAlert('Komentarz zapisany.', 'success');
            } else {
                setAlert('Błąd zapisu komentarza: ' + (resp && resp.error ? resp.error : 'nieznany'), 'danger');
            }
        }).fail(function () {
            setAlert('Błąd zapisu komentarza.', 'danger');
        });
        cancelVariantCommentEdit();
    }

    $('#variantCommentSave').on('click', saveVariantComment);
    $('#variantCommentCancel').on('click', cancelVariantCommentEdit);
    $variantCommentInput.on('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); saveVariantComment(); }
        else if (ev.key === 'Escape') { cancelVariantCommentEdit(); }
    });

    // Ilość → Opak.: packages = qty / full pack quantity.
    $cartQty.on('input', function () {
        updatePackagesDisplay();
    });

    $addToCartBtn.on('click', function () {
        addCurrentSelectionToCart();
    });

    // Remove cart item
    $cartBody.on('click', '.remove-item-btn', function () {
        let idx = parseInt($(this).data('idx'), 10);
        cart.items.splice(idx, 1);
        renderCart();
        loadActiveDocs();
        saveCart();
        setAlert('Usunięto pozycję.', 'info');
    });

    // Open the quick-edit modal pre-filled with the current line values.
    $cartBody.on('click', '.edit-item-btn', function () {
        let idx = parseInt($(this).data('idx'), 10);
        let item = cart.items[idx];
        if (!item) return;
        $editItemHeader.text((item.vendor_name || '') + ' — ' + (item.vendor_part_no || '') +
            (item.producer_part_no ? ' (prod: ' + item.producer_part_no + ')' : ''));
        $editItemQty.val(item.quantity || '');
        $editItemPrice.val(item.unit_price === null || item.unit_price === undefined ? '' : item.unit_price);
        $editItemCurrency.val(item.currency || 'PLN');
        // Opak. pre-fill: derived from the stored quantity; disabled when
        // the variant has no usable full_pack_quantity.
        let fpq = itemFullPackQty(item);
        $editItemPackages.prop('disabled', fpq === null).removeClass('packages-uneven');
        if (fpq !== null) {
            let pkgs = (parseFloat(item.quantity) || 0) / fpq;
            $editItemPackages.val(parseFloat(pkgs.toFixed(2)));
            $editItemPackages.toggleClass('packages-uneven', Math.abs(pkgs - Math.round(pkgs)) >= 1e-9);
            $editItemPackages.attr('placeholder', formatQty(fpq) + '/opak.');
        } else {
            $editItemPackages.val('').attr('placeholder', 'opak.');
        }
        $editItemModal.data('edit-idx', idx);
        $editItemModal.modal('show');
    });

    // Modal two-way sync, mirroring the picker row's Opak.↔Ilość pair:
    //   Opak. input → Ilość = packages × full_pack_quantity
    //   Ilość input → Opak. = quantity / full_pack_quantity
    // Fractional package counts flag the Opak. field yellow — non-blocking.
    function editItemPackagesFromQty() {
        let item = cart.items[$editItemModal.data('edit-idx')];
        let fpq = itemFullPackQty(item);
        let qty = parseFloat($editItemQty.val());
        if (fpq === null || isNaN(qty) || qty < 0) {
            $editItemPackages.removeClass('packages-uneven');
            return;
        }
        let pkgs = qty / fpq;
        let even = Math.abs(pkgs - Math.round(pkgs)) < 1e-9;
        $editItemPackages.val(parseFloat(pkgs.toFixed(2)));
        $editItemPackages.toggleClass('packages-uneven', !even);
        $editItemPackages.attr('title', even ? 'Ilość = opakowania × ilość w opakowaniu'
                                             : 'Uwaga: ilość nie odpowiada pełnej liczbie opakowań');
    }

    $editItemPackages.on('input', function () {
        let item = cart.items[$editItemModal.data('edit-idx')];
        let fpq = itemFullPackQty(item);
        let pkgs = parseFloat($(this).val());
        if (fpq === null || isNaN(pkgs) || pkgs < 0) {
            $(this).removeClass('packages-uneven');
            return;
        }
        $editItemQty.val(parseFloat((pkgs * fpq).toFixed(6)));
        let even = Math.abs(pkgs - Math.round(pkgs)) < 1e-9;
        $(this).toggleClass('packages-uneven', !even);
        $(this).attr('title', even ? 'Ilość = opakowania × ilość w opakowaniu'
                                   : 'Uwaga: ilość nie odpowiada pełnej liczbie opakowań');
    });

    $editItemQty.on('input', editItemPackagesFromQty);

    // Save the edited values back into the cart, persist, re-render.
    $editItemSave.on('click', function () {
        let idx = $editItemModal.data('edit-idx');
        if (typeof idx !== 'number') idx = parseInt(idx, 10);
        let item = cart.items[idx];
        if (!item) { $editItemModal.modal('hide'); return; }
        let qty = parseFloat($editItemQty.val());
        let priceRaw = $editItemPrice.val() === '' ? NaN : parseFloat($editItemPrice.val());
        let price = (!isNaN(priceRaw) && priceRaw >= 0) ? priceRaw : null;
        if (isNaN(qty) || qty <= 0) {
            setAlert('Ilość musi być większa od zera.', 'warning'); return;
        }
        if (price === null) {
            setAlert('Cena/Szt. jest wymagana (wprowadź wartość).', 'warning'); return;
        }
        item.quantity = qty;
        item.unit_price = price;
        item.currency = $editItemCurrency.val() || 'PLN';
        $editItemModal.modal('hide');
        renderCart();
        loadActiveDocs();
        saveCart();
        setAlert('Pozycja zaktualizowana.', 'success');
    });

    // Remove the Enter-key submit binding on the qty/price inputs —
    // pressing Enter inside a modal input can dismiss the modal via
    // Bootstrap's default keyhandler; the user clicks the Zapisz button
    // explicitly.

    // Select this vendor in the picker so more items can be queued.
    $cartBody.on('click', '.select-vendor-btn', function (e) {
        e.preventDefault();
        let vid = parseInt($(this).data('vendor-id'), 10) || null;
        if (!vid) return;
        $vendorSelect.val(vid);
        refreshSelectpicker($vendorSelect);
        applyVendorFilter();       // scope parts to this vendor
        refreshVendorPartRow();
        loadSelectionDocs();
        // Small visual confirmation on the picker button.
        try {
            let sp = $vendorSelect.data('selectpicker');
            let $btn = (sp && sp.$newElement) ? sp.$newElement.find('button.dropdown-toggle').first() : $();
            if ($btn.length) {
                $btn.removeClass('flash-selected');
                void $btn[0].offsetWidth;   // restart animation if mid-flight
                $btn.addClass('flash-selected');
                $btn.one('animationend', function () { $(this).removeClass('flash-selected'); });
            }
        } catch (err) { /* cosmetic only */ }
        let card = document.getElementById('vendorPartRow');
        if (card) { card.closest('.card').scrollIntoView({ behavior: 'smooth' }); }
    });

    // Remove all cart items of one vendor (with confirmation).
    $cartBody.on('click', '.clear-vendor-items-btn', function () {
        let vid = parseInt($(this).data('vendor-id'), 10) || null;
        if (!vid) return;
        let count = cart.items.filter(function (i) { return parseInt(i.vendor_id, 10) === vid; }).length;
        if (count === 0) return;
        let name = '';
        for (let i = 0; i < cart.items.length; i++) {
            if (parseInt(cart.items[i].vendor_id, 10) === vid) { name = cart.items[i].vendor_name; break; }
        }
        if (!window.confirm('Usunąć ' + count + ' poz. dostawcy ' + name + ' z koszyka?')) return;
        cart.items = cart.items.filter(function (i) { return parseInt(i.vendor_id, 10) !== vid; });
        renderCart();
        loadActiveDocs();
        saveCart();
        setAlert('Usunięto pozycje dostawcy ' + name + '.', 'info');
    });

    // Per-vendor-group create buttons
    $cartBody.on('click', '.create-rfq-btn, .create-po-btn', function () {
        let docType = $(this).hasClass('create-rfq-btn') ? 'rfq' : 'po';
        submit(docType, this);
    });

    // Clear cart
    $clearBtn.on('click', function () {
        if (cart.items.length === 0) { return; }
        if (!window.confirm('Wyczyścić koszyk? ' + cart.items.length + ' pozycji zostanie usuniętych.')) { return; }
        cart.items = [];
        cart.activeDocs = {};
        clearSavedCart();
        renderCart();
        setAlert('Koszyk wyczyszczony.', 'info');
    });

    // Initial render — deferred to ready() so it runs AFTER header.js's
    // ready handler has initialized the selectpickers. A refresh() called
    // pre-init is swallowed, leaving the vp picker's menu stale.
    $(function () {
        loadSavedCart();
        renderCart();
        refreshVendorPartRow();
        loadActiveDocs();   // badges for restored items
    });
})();
