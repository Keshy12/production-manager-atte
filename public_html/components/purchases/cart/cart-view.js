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
//      / "Utwórz zamówienie" buttons — POST cart-create-document.php
//      with the group's items; server creates a draft RFQ/PO and returns
//      the edit URL which the client opens in a new tab. Used items
//      are removed from the cart on success.
//   6. The cart persists across page refreshes via localStorage
//      (7-day expiry, cleared on submit and on "Wyczyść koszyk").

(function () {
    'use strict';

    let cart = {
        items:       [],     // {vendor_part_id, vendor_id, vendor_name, vendor_part_no,
                             //  producer_part_no, part_name, producer_name, unit_name,
                             //  vendor_jm_id, full_pack_quantity, pack_quantities,
                             //  picked_pack_size, quantity, unit_price, currency}
        activeDocs:  {},     // vendor_part_id (string) → [doc, …]
        activeDocsLoaded: false,  // flipped true after the first AJAX fetch resolves (or fails). While false, inline edit is blocked so the load's re-render can't blow away an open edit session.
    };

    // ---- picker-mode state ----
    // Picked pack size (the variant's chosen pack size for the current
    // pick-mode form / edit modal / new cart line). `null` when no usable
    // pack size is selected. `full_pack_quantity` (the smallest pack)
    // remains the default — set in syncAmountsInputs / on edit-modal open.
    let state = {
        pickedPackSize: null,
        // { packages, pack } when the cartQty value was auto-derived from
        // (cartPackages × pickedPackSize); null after the user types into
        // cartQty directly (or before any quantity has been entered).
        // Drives the picker-change handler: re-derive quantity only when
        // we know it came from packages×pack; otherwise the user wrote a
        // raw quantity and changing pack only re-evaluates the warning.
        lastDerived: null,
        // Last-edited input between $cartPackages and $cartQty — receives
        // the `packages-uneven` warning class when qty is uneven vs the
        // chosen pack size. Set in the respective input handlers.
        lastEdited: null
    };

    // DOM refs
    let $vendorSelect        = $('#vendorSelect');
    let $partSelect          = $('#partSelect');
    let $vendorPartNoSelect  = $('#vendorPartNoSelect');
    let $cartPackSizePickerWrap  = $('#cartPackSizePickerWrap');
    let $cartPackSizeStepper     = $('#cartPackSizeStepper');
    let $cartPackSizePrev        = $('#cartPackSizePrev');
    let $cartPackSizeValue       = $('#cartPackSizeValue');
    let $cartPackSizeNext        = $('#cartPackSizeNext');
    let $cartPackSizeAdd         = $('#cartPackSizeAdd');
    let $cartPackSizeEditBox     = $('#cartPackSizeEditBox');
    let $cartPackSizeInput       = $('#cartPackSizeInput');
    let $cartPackSizeSave        = $('#cartPackSizeSave');
    let $cartPackSizeCancel      = $('#cartPackSizeCancel');
    let $cartPackages        = $('#cartPackages');
    let $cartQty             = $('#cartQty');
    let $cartPrice           = $('#cartPrice');
    let $cartCurrency        = $('#cartCurrency');
    let $cartLineComment     = $('#cartLineComment');
    let $cartPriceRow        = $('#cartPriceRow');
    let $variantInfoRow      = $('#variantInfoRow');
    let $variantCommentDisplay = $('#variantCommentDisplay');
    let $variantCommentText  = $('#variantCommentText');
    let $variantCommentEdit  = $('#variantCommentEdit');
    let $variantCommentEditBox = $('#variantCommentEditBox');
    let $variantCommentInput = $('#variantCommentInput');
    let $addToCartBtn        = $('#addToCartBtn');
    let $toggleAddVariantBtn       = $('#toggleAddVariantBtn');
    let $addVariantHelpModal       = $('#addVariantHelpModal');
    let $addVariantHelpContinue    = $('#addVariantHelpContinue');
    let $cartConfirmModal          = $('#cartConfirmModal');
    let $clearSelectionBtn         = $('#clearSelectionBtn');
    let $pickVariantRow      = $('#pickVariantRow');
    let $addVariantRow1      = $('#addVariantRow1');
    let $addVariantRow2      = $('#addVariantRow2');
    let $addVariantCommentRow= $('#addVariantCommentRow');
    let $addProducerSelect   = $('#addProducerSelect');
    let $addUnitSelect       = $('#addUnitSelect');
    let $addVendorPartNo     = $('#addVendorPartNo');
    let $addProducerPartNo   = $('#addProducerPartNo');
    let $addFullPackQuantity = $('#addFullPackQuantity');
    let $addComment          = $('#addComment');
    // Cart-add input wrappers — live inside #pickVariantRow next to
    // vendorPartCell in pick mode and are simply hidden along with that
    // row in add mode. They never move between rows anymore (the add
    // flow no longer shows Opak./Ilość/Cena/Waluta). Cena/Szt. and
    // Waluta live in a separate #cartPriceRow that becomes visible only
    // once a concrete variant is resolved.
    let $cartPackagesWrap    = $('#cartPackagesWrap');
    let $cartQtyWrap         = $('#cartQtyWrap');
    let $cartPriceWrap       = $('#cartPriceWrap');
    let $cartCurrencyWrap    = $('#cartCurrencyWrap');
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

    // ---- picker mode (pick | add) ----
    // 'pick' (default): user picks an EXISTING VendorPart and adds to cart.
    // 'add':  the form reshapes — Producent / JM / free-text Numer / opak.
    //         become visible and editable. "Dodaj" creates the new
    //         VendorPart AND adds the line to the cart in one click.
    let pickerMode = 'pick';

    // Add-mode field values may have been entered by the user; track
    // dirtiness so the Anuluj button can warn before discarding.
    let addModeDirty = false;

    function setPickerMode(mode) {
        pickerMode = mode === 'add' ? 'add' : 'pick';
        // Visual hint on the picker card body.
        let $card = $pickVariantRow.closest('.card');
        $card.toggleClass('picker-add-mode', pickerMode === 'add');
        if (pickerMode === 'add') {
            // The cart-add wrappers stay inside #pickVariantRow (where the
            // HTML places them next to vendorPartCell) — hiding the whole
            // row also hides Opak./Ilość/Cena/Szt./Waluta. The add flow
            // only edits catalog fields; the user fills qty/price/currency
            // AFTER save, in pick mode. Cena/Waluta live in their own
            // #cartPriceRow outside pickVariantRow, so we hide it here
            // explicitly — it's not meaningful during catalog creation.
            $pickVariantRow.hide();
            $cartPriceRow.hide();
            $addVariantRow1.show();
            $addVariantRow2.show();
            $addVariantCommentRow.show();
            $toggleAddVariantBtn
                .removeClass('btn-outline-info').addClass('btn-outline-warning')
                .html('<i class="bi bi-x-square"></i> Anuluj')
                .attr('title', 'Anuluj dodawanie nowego artykułu');
            // The Dodaj button becomes "Zapisz" — it only creates the new
            // VendorPart; the cart line is added afterwards in pick mode.
            $addToCartBtn
                .html('<i class="bi bi-check-lg"></i> Zapisz')
                .attr('title', 'Zapisz nowy artykuł do katalogu')
                .removeClass('btn-success').addClass('btn-primary');
            // Wyczyść has no meaningful target in add mode — the user is
            // filling a fresh form, not clearing a pick-mode selection.
            $clearSelectionBtn.hide();
            // Un-filter the Part picker so any active part is selectable.
            $partSelect.find('option').each(function () {
                let id = parseInt($(this).val(), 10) || 0;
                if (id === 0) return;
                $(this).prop('hidden', false);
            });
            refreshSelectpicker($partSelect);
        } else {
            $pickVariantRow.show();
            $addVariantRow1.hide();
            $addVariantRow2.hide();
            $addVariantCommentRow.hide();
            $toggleAddVariantBtn
                .removeClass('btn-outline-warning').addClass('btn-outline-info')
                .html('<i class="bi bi-plus-square"></i> Artykuł')
                .attr('title', 'Dodaj nowy artykuł dostawcy do katalogu');
            $addToCartBtn
                .html('<i class="bi bi-plus-circle"></i> Dodaj')
                .removeAttr('title')
                .removeClass('btn-primary').addClass('btn-success');
            $clearSelectionBtn.show();
            // Re-apply the pick-mode vendor/part filter that we skipped
            // while in add mode.
            applyVendorFilter();
            applyPartFilter();
            // syncAmountsInputs re-evaluates cartPriceRow visibility and
            // repopulates the pack-size picker against the (still or now)
            // selected variant. Called here so transitioning add → pick
            // restores the picker / price row to a consistent state
            // without waiting for the next variant change.
            syncAmountsInputs();
        }
        updateAddBtnState();
    }

    function resetAddModeFields() {
        $addProducerSelect.val('');
        refreshSelectpicker($addProducerSelect);
        $addUnitSelect.val('');
        refreshSelectpicker($addUnitSelect);
        $addVendorPartNo.val('');
        $addProducerPartNo.val('');
        $addFullPackQuantity.val('');
        $addComment.val('');
        addModeDirty = false;
    }

    function isAddModeDirty() {
        if (!addModeDirty) return false;
        // Pack input is optional — empty/blank means "no pack size"
        // and is NOT dirty. Any non-empty value (even just a `/`)
        // is treated as user input and therefore dirty.
        let packDirty = $addFullPackQuantity.val().toString().trim() !== '';
        return ($addProducerSelect.val()
            || $addUnitSelect.val()
            || $addVendorPartNo.val().trim()
            || $addProducerPartNo.val().trim()
            || packDirty
            || $addComment.val().trim());
    }

    // Entering add mode keeps the row-1 Vendor and Part pickers as-is
    // (their DOM values carry over from pick mode). We intentionally do
    // NOT auto-fill Producent / JM / Pełne opakowanie / Numer u producenta
    // from the selected pick-mode variant — the user is the source of
    // truth when creating a new VP, even if the variant they had picked
    // happens to share producer / JM with the new one. Just ensure the
    // free-text inputs start blank and the dirty flag is fresh.
    function prefillAddModeFromSelection() {
        $addVendorPartNo.val('');
        $addComment.val('');
        // Default the JM picker to the currently-picked Part's own unit
        // (list__parts.JM). Only meaningful when a Part is already
        // selected; if not, the user picks it inside the form (and
        // the change handler will set JM at that point).
        let $partOpt = $partSelect.find('option:selected');
        if ($partOpt.length > 0) {
            let jm = parseInt($partOpt.attr('data-jm'), 10) || null;
            if (jm) {
                $addUnitSelect.val(String(jm));
                refreshSelectpicker($addUnitSelect);
            }
        }
        addModeDirty = false;
    }

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
            // Migrate legacy items: pre-pick-pack-size carts didn't carry
            // `picked_pack_size` at all. Fall back to the variant's
            // full_pack_quantity (smallest pack) so the row badge and
            // even-pack math work without a re-edit.
            // Pre-line-comment carts didn't carry `line_comment` —
            // normalize to null so render checks (item.line_comment || '')
            // work uniformly.
            cart.items.forEach(function (item) {
                if (item.picked_pack_size === undefined || item.picked_pack_size === null) {
                    let fpq = parseFloat(item.full_pack_quantity);
                    item.picked_pack_size = (!isNaN(fpq) && fpq > 0) ? fpq : null;
                }
                if (typeof item.line_comment !== 'string') {
                    item.line_comment = null;
                }
            });
        } catch (e) { /* corrupt/unavailable — start empty */ }
    }

    // ---- helpers ----

    function refreshSelectpicker($el) {
        if (typeof $el.selectpicker === 'function') {
            try { $el.selectpicker('refresh'); } catch (e) { /* noop */ }
        }
    }

    // Force a clean rebuild of a bootstrap-select widget — needed when
    // options change wholesale (e.g. after refreshVendorPartRow rebuilds
    // vendorPartNoSelect, or after a mode swap toggles part-picker
    // filter state). Calling refresh() alone can leave the dropdown
    // menu rendering stale entries briefly; destroy+reinit tears the
    // wrapper down and recreates it with the current select state.
    // The select's event handlers and data-* options survive (they're
    // on the underlying <select>, not on the wrapper divs).
    function destroyAndReinitSelectpicker($el) {
        if ($el.length === 0 || !$el.hasClass('selectpicker')) return;
        let val = $el.val();
        try { $el.selectpicker('destroy'); } catch (e) { /* noop */ }
        $el.selectpicker();
        if (val !== null && val !== '') {
            try { $el.selectpicker('val', val); } catch (e) { /* noop */ }
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

    // Reusable themed confirmation dialog. Replaces native window.confirm
    // so the cart's destructive actions (remove-row, remove-vendor-items,
    // create-doc, clear-cart) use the same Bootstrap-styled prompt.
    //   opts.title      — modal title
    //   opts.body       — HTML allowed (escape text fragments before passing)
    //   opts.okLabel    — confirm button text (default 'Potwierdź')
    //   opts.okClass    — confirm button color (default 'btn-danger')
    //   opts.onConfirm  — function to run on confirm (modal auto-hides)
    function cartConfirm(opts) {
        $cartConfirmModal.find('#cartConfirmTitle').text(opts.title || 'Potwierdź');
        $cartConfirmModal.find('#cartConfirmBody').html(opts.body || '');
        const $ok = $cartConfirmModal.find('#cartConfirmOk');
        $ok.text(opts.okLabel || 'Potwierdź')
           .removeClass('btn-danger btn-success btn-primary btn-warning')
           .addClass(opts.okClass || 'btn-danger');
        // Detach any prior handler so repeated calls don't stack.
        $ok.off('click.cartConfirm').one('click.cartConfirm', function () {
            $cartConfirmModal.modal('hide');
            if (typeof opts.onConfirm === 'function') { opts.onConfirm(); }
        });
        $cartConfirmModal.modal('show');
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // escapeHtml + nl2br combined — safe to drop already-escaped HTML
    // straight into innerHTML. Used by the cart row's Uwagi cell so
    // multi-line notes (newlines the user typed in the input/textarea)
    // render on screen instead of collapsing to a single line.
    function nl2brSafe(escapedHtml) {
        return escapedHtml.replace(/\r\n|\r|\n/g, '<br>');
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

    // True when `qty` divides evenly by `pack` (within float epsilon).
    // Short-circuits to true for missing/zero/NaN — that's "nothing
    // entered yet" or "no usable pack size", not "user wrote a bad
    // value". The packages-uneven warning is non-blocking.
    function isEven(qty, pack) {
        if (!pack || pack <= 0 || qty == null || isNaN(qty)) return true;
        return Math.abs(qty / pack - Math.round(qty / pack)) < 1e-9;
    }

    // Parse the add-mode "Pełne opakowanie" free-text field into a list
    // of positive floats. Splits on `/`, trims each segment, parses each
    // as a float, dedupes, drops non-positive entries. Empty / null input
    // → [] (no pack size recorded; the VP simply has no pack tiers).
    // Used to derive both `full_pack_quantity` (smallest) and
    // `pack_quantities` for the new VendorPart.
    function parsePackInput(raw) {
        if (raw === null || raw === undefined) return [];
        let s = String(raw).trim();
        if (s === '') return [];
        let out = [];
        s.split('/').forEach(function (part) {
            let v = parseFloat(String(part).trim().replace(',', '.'));
            if (!isNaN(v) && v > 0) {
                // Dedup via stringified float — avoids 1.0 vs 1 confusion.
                let key = v.toString();
                if (!out.some(function (x) { return x.toString() === key; })) {
                    out.push(v);
                }
            }
        });
        return out;
    }

    // Build the option list for a pack-size picker (variant picker row
    // OR edit modal) from the variant's `pack_quantities` array. Sorts
    // ascending. Disabled when the variant has no usable pack sizes —
    // the placeholder is shown and any selection becomes `--`.
    // The `suppressRef` parameter is a 1-element array used as a
    // mutable boolean — bootstrap-select dispatches `changed.bs.select`
    // asynchronously after .val(), so the suppress flag must be held
    // for the duration of the dispatch. Each picker has its own flag
    // (cart vs edit modal) so they don't interfere with each other.
    // Cycle the picked pack size through the sorted list by ±1.
    // Returns the new picked value, or null if there is no list / nothing
    // to cycle to in the requested direction.
    function stepPackSize(packs, current, delta) {
        if (!Array.isArray(packs) || packs.length === 0) return null;
        let sorted = packs.slice().sort(function (a, b) { return parseFloat(a) - parseFloat(b); });
        let idx = sorted.findIndex(function (p) { return parseFloat(p) === parseFloat(current); });
        if (idx === -1) {
            // current not in list — jump to the boundary the user asked for
            return parseFloat(delta > 0 ? sorted[sorted.length - 1] : sorted[0]);
        }
        let nextIdx = idx + delta;
        if (nextIdx < 0 || nextIdx >= sorted.length) return parseFloat(sorted[idx]);
        return parseFloat(sorted[nextIdx]);
    }

    // Render the pack-size stepper. Sets the displayed value, enables
    // / disables prev/next based on position, and shows/hides the wrap
    // (and the stepper span inside it) depending on whether there are
    // pack tiers at all. The +/- stays visible at all times — only its
    // enabled state changes — so single-tier variants render the same
    // layout as multi-tier ones, just with both boundary buttons
    // disabled (the user can still see the resolved pack size and
    // click the pencil to add another tier). Returns the resolved
    // picked value (or null when no packs).
    function populatePackSizeStepper($value, $prev, $next, $wrap, $stepper, packQuantities, defaultValue) {
        let packs = Array.isArray(packQuantities) ? packQuantities.slice() : [];
        packs.sort(function (a, b) { return parseFloat(a) - parseFloat(b); });
        if (packs.length === 0) {
            $value.text('—');
            $prev.prop('disabled', true);
            $next.prop('disabled', true);
            $wrap.hide();
            $stepper.hide();
            return null;
        }
        let dv = parseFloat(defaultValue);
        let target = (!isNaN(dv) && dv > 0 && packs.indexOf(dv) !== -1) ? dv
                   : parseFloat(packs[0]);
        $value.text(formatQty(target));
        // Boundary disable: with a single tier, target equals both the
        // smallest and the largest pack, so both buttons end up
        // disabled — visually the same chip group, just greyed out.
        $prev.prop('disabled', target === parseFloat(packs[0]));
        $next.prop('disabled', target === parseFloat(packs[packs.length - 1]));
        $wrap.show();
        $stepper.show();
        return target;
    }

    // Visibility-only twin of populatePackSizeStepper — used by the
    // pencil cancel/save handlers to restore the picker state after
    // the inline edit box has been dismissed, without re-touching the
    // stepper value or prev/next buttons. Wrap + stepper span stay
    // visible whenever a pack size is defined; prev/next boundary
    // disabled state is left untouched (it was already correct when
    // the variant was picked).
    function applyPackSizePickerVisibility($wrap, $stepper, packQuantities) {
        let n = Array.isArray(packQuantities) ? packQuantities.length : 0;
        $wrap.toggle(n >= 1);
        $stepper.toggle(n >= 1);
    }

    // Apply or remove the `packages-uneven` warning class + title on the
    // last-edited input between the two amount fields. `lastEdited` is a
    // jQuery object ($cartPackages or $cartQty); the OTHER field's class
    // is cleared first so only the most recently typed field flags. When
    // `even === true` both fields are left clean.
    function applyEvenWarning(lastEdited, qty, pack) {
        // Always clear both — last-edited wins; the other stays clean.
        $cartPackages.removeClass('packages-uneven').removeAttr('title');
        $cartQty.removeClass('packages-uneven').removeAttr('title');
        let even = isEven(qty, pack);
        if (even) return;
        if (!lastEdited || !lastEdited.length) return;
        lastEdited.addClass('packages-uneven');
        lastEdited.attr('title',
            'Uwaga: ilość nie odpowiada pełnej liczbie opakowań (wielkość: ' + formatQty(pack) + ')');
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

    // After creating a new VendorPart, the cascade filters (vendor's
    // data-parts / part's data-vendors) are stale — they were computed
    // at page load and don't include the new (vendorId, partsId) pair.
    // Re-fetch vendor+part options from the server and rebuild the
    // two pickers via the standard bootstrap-select flow (.html() +
    // .selectpicker('refresh')). No destroy+reinit tricks, no manual
    // data-attribute updates: the server is the single source of truth.
    //
    // Preserves the currently selected vendor / part across the swap
    // (their ids still exist in the new options). The callback runs
    // after both pickers are refreshed — use it to trigger downstream
    // updates like refreshVendorPartRow().
    function loadVendorAndPartOptions(callback, prevVendorId, prevPartId) {
        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart/cart-vendors-refresh.php',
            type: 'POST',
            dataType: 'json'
        }).done(function (data) {
            if (!data || !Array.isArray(data.vendors) || !Array.isArray(data.parts)) {
                if (typeof callback === 'function') callback();
                return;
            }
            // Build vendor option list.
            let vendorHtml = '';
            data.vendors.forEach(function (v) {
                vendorHtml += '<option value="' + v.id + '"' +
                    ' data-name="' + escapeHtml(v.name) + '"' +
                    ' data-parts=\'' + JSON.stringify(v.parts_ids || []) + '\'>' +
                    escapeHtml(v.name) +
                    '</option>';
            });
            $vendorSelect.html(vendorHtml);
            // Canonical refresh -> val -> refresh sequence (matches
            // refreshVendorPartRow's working pattern). The widget keeps
            // its own option cache that becomes stale immediately after
            // .html(); calling selectpicker('val', x) against that stale
            // cache silently no-ops, leaving the picker empty. Sync the
            // widget to the new options first so val() finds its target,
            // then re-sync after to push the new display value through.
            refreshSelectpicker($vendorSelect);
            if (prevVendorId) {
                try { $vendorSelect.selectpicker('val', prevVendorId); } catch (e) { /* noop */ }
            }
            refreshSelectpicker($vendorSelect);
            // Build part option list (same pattern).
            let partHtml = '';
            data.parts.forEach(function (p) {
                let subtext = p.description
                    ? ' data-subtext="' + escapeHtml(p.description) + '"'
                    : '';
                partHtml += '<option value="' + p.id + '"' +
                    ' data-name="' + escapeHtml(p.name) + '"' +
                    ' data-jm="' + (p.jm || '') + '"' +
                    subtext +
                    ' data-vendors=\'' + JSON.stringify(p.vendors_ids || []) + '\'>' +
                    escapeHtml(p.name) +
                    '</option>';
            });
            $partSelect.html(partHtml);
            refreshSelectpicker($partSelect);
            if (prevPartId) {
                try { $partSelect.selectpicker('val', prevPartId); } catch (e) { /* noop */ }
            }
            refreshSelectpicker($partSelect);
            if (typeof callback === 'function') callback();
        }).fail(function () {
            setAlert('Błąd ładowania listy dostawców/części.', 'danger');
            if (typeof callback === 'function') callback();
        });
    }

    // ---- cascading-filter logic ----

    function applyVendorFilter() {
        // In add mode the part picker must stay un-filtered so the user
        // can pick ANY active part for the new VendorPart.
        if (pickerMode === 'add') return;
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
        // Same as above — skip while in add mode.
        if (pickerMode === 'add') return;
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
        if (pickerMode === 'add') {
            // Save button — enable only when every required catalog field
            // is valid. Qty/price/currency belong to the next step.
            // Pack input is OPTIONAL: empty means "no pack size recorded";
            // a non-empty value must parse to at least one positive number
            // (otherwise the createNewVariant submit will error out).
            let packs = parsePackInput($addFullPackQuantity.val());
            let rawPack = $addFullPackQuantity.val().toString().trim();
            let packOk = rawPack === '' || packs.length > 0;
            let ok = (parseInt($vendorSelect.val(), 10) || 0) > 0
                && (parseInt($partSelect.val(), 10) || 0) > 0
                && (parseInt($addProducerSelect.val(), 10) || 0) > 0
                && (parseInt($addUnitSelect.val(), 10) || 0) > 0
                && $addVendorPartNo.val().trim() !== ''
                && packOk;
            $addToCartBtn.prop('disabled', !ok);
        } else {
            // Pick mode — enable when a concrete variant is selected.
            let $sel = $vendorPartNoSelect.find('option:selected');
            let ok = $sel.length > 0
                && !$sel.prop('hidden')
                && (parseInt($sel.attr('data-vp-id'), 10) || 0) > 0;
            $addToCartBtn.prop('disabled', !ok);
        }
    }

    // Full-pack quantity of the currently selected variant (null when the
    // variant has no usable pack size or nothing is selected). Kept as
    // the "smallest pack" anchor for the cart row's "X opak." sub-line
    // and for backwards-compatibility with add-mode defaults.
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

    // Picked pack size of the currently selected variant — null when no
    // pack is selectable. The chip group sets state.pickedPackSize on
    // populate and on chip click; state is the truth (chips don't have
    // a selectpicker.val() to read).
    function selectedPickedPackSize() {
        return state.pickedPackSize;
    }

    // Picked pack size stored on a cart item (modal-safe counterpart).
    function itemPickedPackSize(item) {
        if (!item) return null;
        let v = parseFloat(item.picked_pack_size);
        return (!isNaN(v) && v > 0) ? v : itemFullPackQty(item);
    }

    // Clears the packages input and enables it only when the selected
    // variant has a usable picked pack size.
    function resetPackagesInput() {
        let pack = selectedPickedPackSize();
        $cartPackages.val('');
        $cartPackages.removeClass('packages-uneven');
        $cartPackages.prop('disabled', pack === null);
    }

    // Qty unit label + placeholder mirror the selected variant's unit
    // (JM) from DB. The label is shown as a fixed append to the input
    // (visible text); the placeholder is no longer used (kept for
    // back-compat with non-bootstrap-selectpicker themes).
    let $cartQtyUnit = $('#cartQtyUnit');
    function syncQtyPlaceholder() {
        let $sel = $vendorPartNoSelect.find('option:selected');
        let unit = ($sel.length > 0 ? $sel.attr('data-unit-name') : '') || 'szt.';
        $cartQty.attr('placeholder', unit);
        $cartQtyUnit.text(unit || 'szt.');
    }

    // Ilość/Cena/Waluta are editable only once a concrete variant is
    // selected; while ambiguous they're disabled and qty/price cleared.
    // The pack-size picker, the price/currency row, and the picker-row
    // state are all wired up here — this is the single source of truth
    // for "is the pick mode form actually usable?".
    function syncAmountsInputs() {
        let $sel = $vendorPartNoSelect.find('option:selected');
        let resolved = $sel.length > 0
            && !$sel.prop('hidden')
            && (parseInt($sel.attr('data-vp-id'), 10) || 0) > 0;
        let vp = resolved ? getVpById(parseInt($sel.attr('data-vp-id'), 10)) : null;

        $cartQty.prop('disabled', !resolved);
        $cartPrice.prop('disabled', !resolved);
        $cartCurrency.prop('disabled', !resolved);

        if (!resolved) {
            $cartQty.val('');
            $cartPrice.val('');
            $cartLineComment.val('');
            $cartPackages.val('').prop('disabled', true).removeClass('packages-uneven')
                .attr('placeholder', 'opak.');
            // Pack-size stepper: empty + hidden, no selection carried
            // over. Price/currency row hides. populatePackSizeStepper
            // returns null and hides the stepper wrap for an empty pack
            // list.
            populatePackSizeStepper($cartPackSizeValue, $cartPackSizePrev, $cartPackSizeNext,
                                    $cartPackSizePickerWrap, $cartPackSizeStepper, [], null);
            $cartPriceRow.hide();
            $cartPackagesWrap.hide();
            // Clear pick-mode picker state — a future variant shouldn't
            // inherit the previous one's chosen pack.
            state.pickedPackSize = null;
            state.lastDerived = null;
            state.lastEdited = null;
            $variantInfoRow.hide();
        } else {
            // Informative placeholder: how many units one picked pack holds.
            let fpq = selectedFullPackQty();
            $cartPackages.attr('placeholder', fpq !== null ? formatQty(fpq) + '/opak.' : 'opak.');
            // Populate the pack-size stepper from the variant's
            // pack_quantities; default to its full_pack_quantity
            // (smallest pack). The picker wrap stays visible whenever
            // a pack size is defined so the "+" pencil icon (add new
            // tier) remains reachable on single-tier variants; the
            // stepper +/- itself is suppressed when there's only one
            // tier (a single chip would be redundant).
            let packQuantities = vp ? (vp.pack_quantities || []) : [];
            let picked = populatePackSizeStepper($cartPackSizeValue, $cartPackSizePrev, $cartPackSizeNext,
                                                 $cartPackSizePickerWrap, $cartPackSizeStepper, packQuantities, fpq);
            state.pickedPackSize = picked;
            state.lastDerived = null;
            state.lastEdited = null;
            $cartPriceRow.show();
            // Packages-count input is only useful when a pack size is
            // actually defined; pack-less variants skip it as well.
            // Single-tier variants keep the count input — it derives
            // off state.pickedPackSize without needing the stepper.
            $cartPackagesWrap.toggle(picked !== null);
            // Disable the count input when no pack size is defined; clear
            // any stale value. Without this the input keeps the
            // `disabled=true` set by the !resolved branch and the user
            // can't type into it.
            $cartPackages.prop('disabled', picked === null).val('');
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
        // In add mode there's no existing variant to pick — skip the
        // pick-mode rebuild entirely.
        if (pickerMode === 'add') return;
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
        let targetIdNum = targetId !== null ? parseInt(targetId, 10) : null;
        let html = '';
        groupOrder.forEach(function (g) {
            html += '<optgroup label="' + escapeHtml(g) + '">';
            groups[g].forEach(function (vp) {
                // Producer part no rides as subtext (searchable) unless
                // identical to the vendor part no.
                let subtext = (vp.producer_part_no && vp.producer_part_no !== vp.vendor_part_no)
                    ? ' data-subtext="' + escapeHtml(vp.producer_part_no) + '"'
                    : '';
                // Mark the preferred option with the standard `selected`
                // HTML attribute — when the browser parses this HTML it
                // sets the underlying <select>'s value, which bootstrap-
                // select picks up on refresh. This is the canonical way
                // to set the initial value; calling selectpicker('val')
                // after destroy+reinit races with the widget's internal
                // init and silently loses the selection.
                let selectedAttr = (targetIdNum !== null && vp.id === targetIdNum) ? ' selected' : '';
                html += '<option value="' + vp.id + '"' +
                    selectedAttr +
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
        // The `selected` HTML attribute on the target option is parsed
        // by the browser during .html(), but bootstrap-select's widget
        // caches its own value/display state and refresh() alone doesn't
        // reliably re-read it (auto-select silently dropped after the
        // AJAX-rebuild path). The canonical fix is refresh → val →
        // refresh: re-sync the wrapper, explicitly set the value
        // through the plugin API, then re-sync again so the button text
        // and dropdown menu both reflect the new selection.
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
            $cartLineComment.val('');
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
            url: COMPONENTS_PATH + '/purchases/cart/vendor-part-search.php',
            method: 'POST',
            data: { q: q },
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
            cart.activeDocsLoaded = true;
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
            cart.activeDocsLoaded = true;
            renderCart();
        }).fail(function () {
            cart.activeDocs = {};
            cart.activeDocsLoaded = true;
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
                '<div class="btn-group btn-group-sm" role="group" aria-label="Utwórz dokument">' +
                    '<button type="button" class="btn btn-outline-primary create-doc-btn" data-doc-type="rfq" data-vendor-id="' + vid + '" title="Utwórz zapytanie ofertowe (RFQ) jako szkic i otwórz w nowej karcie">' +
                        '<i class="bi bi-question-square"></i> Utwórz zapytanie</button>' +
                    '<button type="button" class="btn btn-outline-success create-doc-btn" data-doc-type="po" data-vendor-id="' + vid + '" title="Utwórz zamówienie (PO) jako szkic i otwórz w nowej karcie">' +
                        '<i class="bi bi-cart-check"></i> Utwórz zamówienie</button>' +
                    '<button type="button" class="btn btn-outline-danger clear-vendor-items-btn" data-vendor-id="' + vid + '" title="Usuń wszystkie pozycje tego dostawcy z koszyka">' +
                        '<i class="bi bi-trash"></i></button>' +
                '</div>' +
                '</div>' +
                '<div class="table-responsive">' +
                '<table class="table table-sm table-striped">' +
                '<thead class="thead-light">' +
                '<tr>' +
                    '<th>Część</th>' +
                    '<th>Ilość</th>' +
                    '<th>Cena</th>' +
                    '<th>Wartość</th>' +
                    '<th>Aktywne dok.</th>' +
                    '<th>Uwagi</th>' +
                    '<th>Akcje</th>' +
                '</tr>' +
                '</thead><tbody>';

            group.items.forEach(function (x) {
                let item = x.item;
                let lineTotal = (item.unit_price ? item.unit_price * item.quantity : 0);
                let docs = cart.activeDocs[item.vendor_part_id] || [];
                let docBadges = '';
                if (!cart.activeDocsLoaded) {
                    // While the first AJAX fetch is in flight, show a
                    // muted placeholder so the user knows the cell will
                    // populate shortly. The badge group never lands in
                    // a "frozen" empty state.
                    docBadges = '<small class="text-muted"><i class="bi bi-hourglass-split"></i> ładowanie…</small>';
                } else {
                    docs.forEach(function (d) {
                        docBadges += '<span class="badge ' + stateBadgeClass(d.state) + ' mr-1" title="' +
                            stateBadgeLabel(d.state) + ' · ' + formatQty(d.quantity) + ' · ' + formatPrice(d.unit_price) + '">' +
                            docTypeIcon(d.doc_type) + ' ' + escapeHtml(d.number) + ' · ' + stateBadgeLabel(d.state) +
                            '</span>';
                    });
                    if (!docBadges) {
                        docBadges = '<span class="text-muted">—</span>';
                    }
                }

                // Część cell carries prefixed sub-lines: vendor/producer
                // numbers (merged when identical) and the description.
                let vn = item.vendor_part_no || '';
                let pn = item.producer_part_no || '';
                let partCell = escapeHtml(item.part_name);
                if (vn && pn && vn === pn) {
                    partCell += '<div><small class="text-muted">Nr dost./prod.: <span class="font-weight-bold">' + escapeHtml(vn) + '</span></small></div>';
                } else {
                    if (vn) partCell += '<div><small class="text-muted">Nr dost.: <span class="font-weight-bold">' + escapeHtml(vn) + '</span></small></div>';
                    if (pn) partCell += '<div><small class="text-muted">Nr prod.: <span class="font-weight-bold">' + escapeHtml(pn) + '</span></small></div>';
                }
                if (item.description) {
                    partCell += '<div><small class="text-muted">' + escapeHtml(item.description) + '</small></div>';
                }
                // Variant-level "Komentarz artykułu" — read live so pen-edits show
                // up in cart rows immediately. Pen icon swaps the line
                // into an inline editor.
                let vpLive = getVpById(item.vendor_part_id);
                let liveCmt = vpLive ? (vpLive.private_comment || '') : '';
                partCell += '<div class="cart-row-comment-line">' +
                    '<small class="text-muted">' +
                    '<i class="bi bi-journal-text"></i> Komentarz: ' +
                    (liveCmt ? escapeHtml(liveCmt) : '<em>Brak</em>') +
                    '</small> ' +
                    '<button type="button" class="btn btn-link btn-sm p-0 ml-1 cart-row-comment-edit" ' +
                    'data-vp-id="' + item.vendor_part_id + '" ' +
                    'title="' + (liveCmt ? 'Edytuj komentarz' : 'Dodaj komentarz') + '">' +
                    '<i class="bi bi-pencil"></i>' +
                    '</button>' +
                    '</div>';
                // Ilość cell carries the package count as a sub-line
                // (yellow when qty doesn't match whole packages) plus
                // the badge showing the chosen pack size for this line.
                let qtyCell = formatQty(item.quantity);
                let pickedPack = itemPickedPackSize(item);
                if (item.full_pack_quantity && item.full_pack_quantity > 0) {
                    let pkgs = item.quantity / item.full_pack_quantity;
                    let evenPkgs = Math.abs(pkgs - Math.round(pkgs)) < 1e-9;
                    qtyCell += '<div><small class="' + (evenPkgs ? 'text-muted' : 'text-warning') + '">' +
                        parseFloat(pkgs.toFixed(2)) + ' opak.</small>';
                    if (pickedPack !== null) {
                        qtyCell += '<span class="badge badge-light border text-monospace ml-1" title="Wybrana wielkość opakowania">opak. ' + formatQty(pickedPack) + '</span>';
                    }
                    qtyCell += '</div>';
                } else if (pickedPack !== null) {
                    qtyCell += '<div><span class="badge badge-light border text-monospace ml-1" title="Wybrana wielkość opakowania">opak. ' + formatQty(pickedPack) + '</span></div>';
                }
                html += '<tr class="cart-item-row" data-idx="' + x.idx + '">' +
                    '<td>' + partCell + '</td>' +
                    '<td class="cart-cell-qty">' + qtyCell + '</td>' +
                    '<td class="cart-cell-price">' + (item.unit_price === null || item.unit_price === undefined
                        ? '<span class="text-muted">—</span>'
                        : formatPrice(item.unit_price) +
                          '<div><small class="text-muted">' + escapeHtml(item.currency) + '/' + escapeHtml(item.unit_name) + '</small></div>') + '</td>' +
                    '<td class="cart-cell-line-total">' +
                        (item.unit_price === null || item.unit_price === undefined
                            ? '<span class="text-muted">—</span>'
                            : formatPrice(item.unit_price * item.quantity) +
                              (item.currency
                                  ? '<div><small class="text-muted">' + escapeHtml(item.currency) + '</small></div>'
                                  : '')) +
                    '</td>' +
                    '<td style="white-space: nowrap;">' + docBadges + '</td>' +
                    // Uwagi do pozycji — its own column (sits before Akcje).
                    // Travels to purchase__rfq_item.comment /
                    // purchase__order_item.comment. Editable inline like
                    // the RFQ pattern (qty/price/uwagi). nl2br so
                    // multi-line notes render on screen without losing
                    // newlines.
                    '<td class="cart-cell-comment cart-row-line-comment-cell">' +
                        (item.line_comment
                            ? nl2brSafe(escapeHtml(item.line_comment))
                            : '<span class="text-muted">—</span>') +
                    '</td>' +
                    '<td class="cart-cell-akcje" style="white-space: nowrap;">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary edit-item-btn mr-1" data-idx="' + x.idx + '"' +
                            (cart.activeDocsLoaded ? '' : ' disabled') +
                            ' title="' + (cart.activeDocsLoaded ? 'Edytuj ilość / cenę / uwagi' : 'Ładowanie aktywnych dokumentów…') + '"><i class="bi bi-pencil"></i></button>' +
                        '<button type="button" class="btn btn-sm btn-danger remove-item-btn" data-idx="' + x.idx + '" title="Usuń pozycję">' +
                            '<i class="bi bi-trash"></i></button>' +
                    '</td>' +
                    '</tr>';
            });

            html += '</tbody></table></div>' +
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
        // Capture the variant entry (from the page-load index) so the
        // cart row carries the same pack_quantities the picker used.
        let vp = getVpById(vendorPartId);
        let pickedPack = state.pickedPackSize;
        if (pickedPack === null || isNaN(pickedPack) || pickedPack <= 0) {
            pickedPack = selectedFullPackQty();
        }
        let packQuantities = vp ? (vp.pack_quantities || []) : [];
        // Merge rule: same vendor-part, same currency, same unit price,
        // same picked pack size (including both unpriced / both default
        // to the same pack). A different price, currency, OR pack size
        // means the user intends a separate line — do NOT silently
        // overwrite the previous line, just push a new row.
        let existing = cart.items.find(function (i) {
            let ip = parseFloat(i.picked_pack_size);
            let samePack = (ip === pickedPack) ||
                ((ip === null || isNaN(ip)) && (pickedPack === null || isNaN(pickedPack)));
            return i.vendor_part_id === vendorPartId
                && i.currency === currency
                && (i.unit_price === unitPrice ||
                    (i.unit_price === null && unitPrice === null))
                && samePack;
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
                pack_quantities   : packQuantities.slice(),
                picked_pack_size  : pickedPack,
                quantity          : qty,
                unit_price        : unitPrice,
                currency          : currency,
                // "Uwagi do pozycji" — captured from the picker at add
                // time; travels to purchase__rfq_item.comment /
                // purchase__order_item.comment. Trimmed + blank→null so
                // empty entries don't waste TEXT storage.
                line_comment      : ($.trim($cartLineComment.val() || '') || null)
            });
            setAlert('Dodano pozycję do koszyka.', 'success');
        }
        // Reset qty + price, clear the part picker (vendor stays selected
        // so the user can queue the next part from the same vendor).
        // Currency stays sticky — usually several items in a row share it.
        // Uwagi do pozycji is per-line, so it resets too — a comment
        // captured for the previous line shouldn't leak onto the next.
$cartQty.val('');
            $cartPrice.val('');
            $cartLineComment.val('');
        $cartLineComment.val('');
        $cartPackages.val('').removeClass('packages-uneven');
        state.lastDerived = null;
        state.lastEdited = null;
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        applyPartFilter();          // part cleared → restore full vendor list
        refreshVendorPartRow();     // combo incomplete → empties picker, add disabled
        loadSelectionDocs();
        renderCart();
        loadActiveDocs();
        saveCart();
    }

    // Q4 = B: in add mode, "Dodaj" creates a new VendorPart AND adds it
    // to the cart in one click. Validates add fields, POSTs vp-add.php,
    // then on success pushes the new variant into VENDOR_PARTS_INDEX in
    // memory so it shows up in the "Numer u dostawcy" picker the next
    // time the user re-picks the same vendor+part.
    // Two-step flow:
    //   1. User enters add mode, fills catalog fields, clicks Save →
    //      this function creates the new VendorPart only.
    //   2. Control snaps back to pick mode with the new variant
    //      pre-selected in "Numer u dostawcy"; the user then enters
    //      qty/price/currency and clicks Dodaj.
    // No cart push happens here — that's the next step.
    function createNewVariant() {
        // ---- validate catalog fields ----
        let vendorId   = parseInt($vendorSelect.val(), 10) || 0;
        let partsId    = parseInt($partSelect.val(), 10) || 0;
        let producerId = parseInt($addProducerSelect.val(), 10) || 0;
        let unitId     = parseInt($addUnitSelect.val(), 10) || 0;
        let vendorPartNo   = $addVendorPartNo.val().trim();
        let producerPartNo = $addProducerPartNo.val().trim() || null;
        // Pack input is OPTIONAL. Empty/blank = no pack size recorded
        // (no list__vendor_part_pack row). Non-empty input is parsed as
        // free-text "100/1000/5000"; we POST the list as pack_quantities
        // AND the smallest as full_pack_quantity for back-compat with
        // code paths that still read the singular field.
        let rawPackInput    = $addFullPackQuantity.val();
        let packInputGiven  = rawPackInput.trim() !== '';
        let packList        = packInputGiven ? parsePackInput(rawPackInput) : [];
        let fpq             = packList.length > 0 ? Math.min.apply(null, packList) : null;
        let comment  = $addComment.val().trim() || null;

        if (!vendorId)           { setAlert('Wybierz dostawcę.', 'warning'); return; }
        if (!partsId)            { setAlert('Wybierz część.', 'warning'); return; }
        if (!producerId)         { setAlert('Wybierz producenta.', 'warning'); return; }
        if (!unitId)             { setAlert('Wybierz jednostkę (JM).', 'warning'); return; }
        if (!vendorPartNo)       { setAlert('Numer u dostawcy jest wymagany.', 'warning'); return; }
        // Pack input is optional; only error when user typed something
        // but nothing parsed to a positive number.
        if (packInputGiven && packList.length === 0) {
            setAlert('Podaj co najmniej jedną wielkość opakowania > 0.', 'warning');
            return;
        }

        // ---- POST vp-add.php ----
        $.ajax({
            url: COMPONENTS_PATH + '/Admin/Purchase/VendorParts/vp-add.php',
            type: 'POST',
            dataType: 'json',
            data: {
                vendor_id:          vendorId,
                producer_id:        producerId,
                parts_id:           partsId,
                vendor_part_no:     vendorPartNo,
                producer_part_no:   producerPartNo || '',
                vendor_jm_id:       unitId,
                full_pack_quantity: fpq,
                pack_quantities:    packList,
                comment:            comment || ''
            }
        }).done(function (r) {
            if (!r || !r.success) {
                setAlert(r && r.error ? r.error : 'Błąd dodawania artykułu.', 'danger');
                return;   // stay in add mode with all fields intact (Q8)
            }
            let newId = r.id;

            // Server is the source of truth for the newly created
            // VendorPart's pack list. Use the response (r.pack_quantities,
            // r.full_pack_quantity) when present; fall back to the values
            // we just sent — keeps the in-memory index consistent with
            // the DB even if the server hasn't been updated yet. The
            // pack payload is OPTIONAL: r.full_pack_quantity may be null
            // and r.pack_quantities may be [], both meaning "no pack size
            // recorded for this VP".
            let respPacks = (r && Array.isArray(r.pack_quantities) && r.pack_quantities.length > 0)
                ? r.pack_quantities.slice()
                : packList.slice();
            let respFpqRaw = (r && r.full_pack_quantity !== null && r.full_pack_quantity !== undefined)
                ? parseFloat(r.full_pack_quantity)
                : NaN;
            let respFpq = (!isNaN(respFpqRaw) && respFpqRaw > 0)
                ? respFpqRaw
                : (respPacks.length > 0 ? Math.min.apply(null, respPacks) : null);
            // Sort ascending so the picker / even-pack math always see
            // a canonical order regardless of the order the user typed.
            respPacks.sort(function (a, b) { return parseFloat(a) - parseFloat(b); });

            // Build a VENDOR_PARTS_INDEX entry from the response + names
            // pulled from the selected option labels.
            let $vendorOpt = $vendorSelect.find('option:selected');
            let $partOpt   = $partSelect.find('option:selected');
            let $prodOpt   = $addProducerSelect.find('option:selected');
            let $unitOpt   = $addUnitSelect.find('option:selected');
            let entry = {
                id                 : newId,
                vendor_id          : vendorId,
                parts_id           : partsId,
                vendor_part_no     : vendorPartNo,
                producer_part_no   : producerPartNo,
                producer_name      : $prodOpt.attr('data-name') || '',
                private_comment    : comment || '',
                vendor_jm_id       : unitId,
                unit_name          : $unitOpt.attr('data-name') || '',
                full_pack_quantity : respFpq,
                pack_quantities    : respPacks,
                vendor_name        : $vendorOpt.attr('data-name') || '',
                part_name          : $partOpt.attr('data-name') || ''
            };
            let key = vendorId + ':' + partsId;
            if (!VENDOR_PARTS_INDEX[key]) VENDOR_PARTS_INDEX[key] = [];
            VENDOR_PARTS_INDEX[key].push(entry);

            // Capture picker state BEFORE the mode swap. setPickerMode('pick')
            // calls applyVendorFilter which can clear the part picker if
            // the new (vendorId, partsId) pair isn't in the page-load
            // data-parts (the new VP hasn't been pushed to the server's
            // view yet). If we captured AFTER, the prev part id would
            // already be empty and the AJAX refresh wouldn't restore it.
            let prevVendorId = $vendorSelect.val();
            let prevPartId   = $partSelect.val();

            // Reset qty/price so the user starts fresh in the cart step.
            $cartQty.val('');
            $cartPrice.val('');
            $cartLineComment.val('');
            $cartPackages.val('').removeClass('packages-uneven');
            resetAddModeFields();
            // Snap back to pick mode. setPickerMode re-applies the
            // vendor/part filter that add mode bypassed.
            setPickerMode('pick');
            // Re-fetch vendor+part picker options from the server so the
            // cascade data (data-parts / data-vendors) reflects the new
            // VP. This is the AJAX path that replaces the previous
            // attribute-update + destroy+reinit combo — the server is
            // the single source of truth and the standard .html() +
            // .selectpicker('refresh') flow works without destroy/reinit
            // races. After the fetch, refreshVendorPartRow rebuilds the
            // variant picker with the new VP pre-selected (via the
            // canonical refresh -> val -> refresh sequence).
            loadVendorAndPartOptions(function () {
                refreshVendorPartRow(newId);
                // Focus qty so the user can type immediately without
                // reaching for the mouse.
                $cartQty.trigger('focus');
            }, prevVendorId, prevPartId);
            setAlert('Artykuł dodany do katalogu — uzupełnij ilość i kliknij Dodaj.', 'success');
        }).fail(function () {
            setAlert('Błąd komunikacji z serwerem.', 'danger');
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
        // In add mode, default the JM picker to the Part's own unit
        // (list__parts.JM, surfaced as data-jm on each option). Saves
        // the user from picking it manually when the vendor uses the
        // same unit the part itself has.
        if (pickerMode === 'add') {
            let $opt = $partSelect.find('option:selected');
            let jm = parseInt($opt.attr('data-jm'), 10) || null;
            if (jm) {
                $addUnitSelect.val(String(jm));
                refreshSelectpicker($addUnitSelect);
            }
        }
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
    $clearSelectionBtn.on('click', function () {
        $vendorSelect.val('');
        refreshSelectpicker($vendorSelect);
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        applyVendorFilter();   // vendor cleared → restore full parts list
        applyPartFilter();     // part cleared → restore full vendors list
        refreshVendorPartRow();
        loadSelectionDocs();
    });

    // Toggle "Artykuł" / "Anuluj" — flips the picker card between
    // pick and add modes. On the pick→add transition we first show a
    // short help modal explaining the two-step flow (catalog first,
    // then cart line); the Continue button does the actual transition.
    $toggleAddVariantBtn.on('click', function () {
        if (pickerMode === 'add') {
            if (isAddModeDirty()) {
                cartConfirm({
                    title: 'Odrzucić wprowadzone dane?',
                    body: '<p>Wprowadzone dane nowego artykułu (producent, JM, numer, opakowanie, komentarz) zostaną utracone.</p>',
                    okLabel: 'Odrzuć',
                    okClass: 'btn-danger',
                    onConfirm: function () { cancelAddVariant(); }
                });
                return;
            }
            cancelAddVariant();
            return;
        }
        // Show the explanation modal — user dismisses / continues
        // from inside it. Vendor isn't required to enter add mode
        // (the user can pick it inside the form); Save validation in
        // updateAddBtnState and createNewVariant() still rejects
        // if vendor is empty.
        $addVariantHelpModal.modal('show');
    });

    // Cancel = full reset of every input the user touched before
    // pressing Anuluj. Distinct from save (Zapisz): a successful save
    // keeps vendor + qty/price cleared but leaves the part picker alone
    // so the user can keep queueing from the same vendor; cancel
    // discards the entire creation context.
    //   - vendor + part (the new-VP context)
    //   - add-mode catalog inputs (Producent, JM, Numer u dostawcy,
    //     Numer u producenta, Pełne opakowanie, Komentarz) — handled
    //     by resetAddModeFields()
    //   - cart-step leftovers (qty/price were hidden when add mode
    //     opened but still held values)
    //   - any leftover alert from a previous failed save
    function cancelAddVariant() {
        $vendorSelect.val('');
        refreshSelectpicker($vendorSelect);
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        $cartQty.val('');
        $cartPrice.val('');
        $cartLineComment.val('');
        $cartPackages.val('').removeClass('packages-uneven');
        setAlert('', '');
        resetAddModeFields();
        // setPickerMode('pick') re-applies the vendor/part filter
        // that add mode bypassed; with both pickers now empty it
        // restores full option lists and re-syncs the amount inputs.
        setPickerMode('pick');
    }

    // "Rozumiem, kontynuuj" inside the help modal — does the actual
    // pick → add transition that the toggle button would have done
    // directly if the modal weren't there.
    $addVariantHelpContinue.on('click', function () {
        $addVariantHelpModal.modal('hide');
        prefillAddModeFromSelection();
        setPickerMode('add');
    });

    // Track add-mode dirtiness so Anuluj can warn before discarding, and
    // re-evaluate the Save button's enabled state on every catalog field
    // change.
    $addProducerSelect.on('change', function () { addModeDirty = true; updateAddBtnState(); });
    $addUnitSelect.on('change',     function () { addModeDirty = true; updateAddBtnState(); });
    $addVendorPartNo.on('input',    function () { addModeDirty = true; updateAddBtnState(); });
    $addProducerPartNo.on('input',  function () { addModeDirty = true; });
    $addFullPackQuantity.on('input',function () { addModeDirty = true; updateAddBtnState(); });
    $addComment.on('input',         function () { addModeDirty = true; });


    // Opak. → Ilość: qty = packages × picked pack size. Mark this as the
    // last-edited field so the uneven warning lands here when the
    // resulting quantity doesn't divide evenly into the picked pack.
    $cartPackages.on('input', function () {
        let pack = selectedPickedPackSize();
        let pkgs = parseFloat($(this).val());
        if (pack === null || isNaN(pkgs) || pkgs < 0) {
            state.lastDerived = null;
            applyEvenWarning($cartPackages, NaN, pack);
            return;
        }
        let qty = pkgs * pack;
        $cartQty.val(parseFloat(qty.toFixed(6)));
        state.lastDerived = { packages: pkgs, pack: pack };
        state.lastEdited = $cartPackages;
        applyEvenWarning($cartPackages, qty, pack);
    });

    // Cart pack-size stepper: − cycles down, + cycles up, the trailing
    // + icon opens an inline edit box for adding a new tier on the fly.
    // All three handlers funnel through cartAfterPackChange() which owns
    // state.pickedPackSize, the visual stepper refresh, the qty
    // re-derivation logic, and the even-pack warning.
    function cartAfterPackChange(newPack) {
        if (!isNaN(newPack) && newPack > 0) {
            state.pickedPackSize = newPack;
        } else {
            state.pickedPackSize = null;
            return;
        }
        // The variant's pack list is what the stepper represents. Read
        // it from the currently selected option so a freshly-added tier
        // (added via the inline editor below) is visible immediately.
        let $sel = $vendorPartNoSelect.find('option:selected');
        let vp   = ($sel.length > 0 && parseInt($sel.attr('data-vp-id'), 10) > 0)
                   ? getVpById(parseInt($sel.attr('data-vp-id'), 10)) : null;
        let packs = vp ? (vp.pack_quantities || []) : [];
        // Refresh the stepper visual with the new value.
        populatePackSizeStepper($cartPackSizeValue, $cartPackSizePrev, $cartPackSizeNext,
                                $cartPackSizePickerWrap, $cartPackSizeStepper, packs, newPack);
        // Placeholder + disable mirror the syncAmountsInputs resolved branch.
        $cartPackagesWrap.toggle(state.pickedPackSize !== null);
        $cartPackages.prop('disabled', state.pickedPackSize === null).val('');
        $cartPackages.attr('placeholder',
            state.pickedPackSize !== null ? formatQty(state.pickedPackSize) + '/opak.' : 'opak.');
        let pkgs = parseFloat($cartPackages.val());
        let qty  = parseFloat($cartQty.val());
        // Re-derive the OTHER field from the user's source-of-truth
        // input, decided by state.lastEdited. If the user last typed
        // in pkgs (state.lastEdited === $cartPackages), the qty input
        // is derived and we recompute it. If the user last typed qty
        // (state.lastEdited === $cartQty), pkgs is derived and we
        // recompute it from qty / newPack. If neither has been touched
        // since the variant was picked, we leave both alone.
        if (state.lastEdited === $cartPackages
            && !isNaN(pkgs)
            && state.pickedPackSize !== null) {
            qty = pkgs * state.pickedPackSize;
            $cartQty.val(parseFloat(qty.toFixed(6)));
            state.lastDerived = { packages: pkgs, pack: state.pickedPackSize };
        } else if (state.lastEdited === $cartQty
            && !isNaN(qty) && qty > 0
            && state.pickedPackSize !== null) {
            let newPkgs = qty / state.pickedPackSize;
            $cartPackages.val(parseFloat(newPkgs.toFixed(2)));
            pkgs = newPkgs;
            state.lastDerived = { packages: pkgs, pack: state.pickedPackSize };
        }
        // Re-evaluate the warning on whichever field was last-edited.
        applyEvenWarning(state.lastEdited, qty, state.pickedPackSize);
    }

    $cartPackSizePrev.on('click', function () {
        if (!state.pickedPackSize) return;
        let $sel = $vendorPartNoSelect.find('option:selected');
        let vp   = ($sel.length > 0 && parseInt($sel.attr('data-vp-id'), 10) > 0)
                   ? getVpById(parseInt($sel.attr('data-vp-id'), 10)) : null;
        let packs = vp ? (vp.pack_quantities || []) : [];
        cartAfterPackChange(stepPackSize(packs, state.pickedPackSize, -1));
    });
    $cartPackSizeNext.on('click', function () {
        if (!state.pickedPackSize) return;
        let $sel = $vendorPartNoSelect.find('option:selected');
        let vp   = ($sel.length > 0 && parseInt($sel.attr('data-vp-id'), 10) > 0)
                   ? getVpById(parseInt($sel.attr('data-vp-id'), 10)) : null;
        let packs = vp ? (vp.pack_quantities || []) : [];
        cartAfterPackChange(stepPackSize(packs, state.pickedPackSize, +1));
    });

    // Inline add-tier editor — same pattern as the variant-comment
    // edit (pencil → input + save/cancel, AJAX on save). POSTs to
    // vendor-part-pack-add.php which INSERTs into list__vendor_part_pack
    // (UNIQUE (vendor_part_id, full_pack_quantity) makes duplicate
    // submissions idempotent) and returns the merged + sorted list.
    $cartPackSizeAdd.on('click', function (e) {
        e.preventDefault();
        let vp = selectedVpEntry();
        if (!vp) return;
        // Pencil: hide the stepper + pencil, show the inline edit box.
        // The edit box uses Bootstrap's d-none / d-flex toggle rather
        // than jQuery's `.show()` because the static markup has
        // `class="d-none …"` and `.d-none { display: none !important }`
        // would otherwise block `.show()` from taking effect.
        $cartPackSizeStepper.hide();
        $cartPackSizeAdd.hide();
        $cartPackSizeEditBox.removeClass('d-none').addClass('d-flex');
        $cartPackSizeInput.val('').trigger('focus');
    });
    $cartPackSizeCancel.on('click', function (e) {
        e.preventDefault();
        $cartPackSizeEditBox.removeClass('d-flex').addClass('d-none');
        $cartPackSizeInput.val('');
        // Restore picker visibility from the current variant. The stepper
        // +/- stay rendered (just disabled on single-tier) so the
        // layout matches multi-tier variants exactly; the helper only
        // needs to put the wrap + stepper span back into view.
        let vp = selectedVpEntry();
        applyPackSizePickerVisibility($cartPackSizePickerWrap, $cartPackSizeStepper,
                                      vp ? (vp.pack_quantities || []) : []);
        $cartPackSizeAdd.show();
    });
    $cartPackSizeInput.on('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); $cartPackSizeSave.trigger('click'); }
        if (ev.key === 'Escape') { ev.preventDefault(); $cartPackSizeCancel.trigger('click'); }
    });
    $cartPackSizeSave.on('click', function (e) {
        e.preventDefault();
        let vp = selectedVpEntry();
        if (!vp) return;
        let raw = $cartPackSizeInput.val().toString().replace(',', '.').trim();
        let qty = parseFloat(raw);
        if (isNaN(qty) || qty <= 0) {
            setAlert('Wielkość opakowania musi być > 0.', 'warning');
            return;
        }
        let $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart/vendor-part-pack-add.php',
            type: 'POST',
            dataType: 'json',
            data: { vp_id: vp.id, full_pack_quantity: qty }
        }).done(function (r) {
            if (!r || !r.success) {
                setAlert(r && r.error ? r.error : 'Błąd zapisu wielkości opakowania.', 'danger');
                return;
            }
            // Refresh in-memory catalog entry and re-render the stepper.
            vp.pack_quantities = Array.isArray(r.pack_quantities) ? r.pack_quantities.slice() : [];
            cartAfterPackChange(qty);
            $cartPackSizeEditBox.removeClass('d-flex').addClass('d-none');
            $cartPackSizeInput.val('');
            // cartAfterPackChange already drove visibility through
            // populatePackSizeStepper — just reveal the pencil.
            $cartPackSizeAdd.show();
        }).fail(function (xhr, status) {
            setAlert(xhr.responseJSON && xhr.responseJSON.error
                     ? xhr.responseJSON.error : status, 'danger');
        }).always(function () {
            $btn.prop('disabled', false);
        });
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
            url: COMPONENTS_PATH + '/purchases/cart/vendor-part-comment.php',
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

    // Per-row inline edit of the vendor-part comment shown in the cart
    // sub-line. The pen in cart-row-comment-line opens a tiny editor in
    // place; Save posts to vendor-part-comment.php (same endpoint the
    // picker-side edit uses), Cancel re-renders the row.
    $cartBody.on('click', '.cart-row-comment-edit', function (e) {
        e.preventDefault();
        let $btn = $(this);
        let vpId = parseInt($btn.attr('data-vp-id'), 10);
        if (!vpId) return;
        let vp = getVpById(vpId);
        if (!vp) return;
        let $line = $btn.closest('.cart-row-comment-line');
        let cur = vp.private_comment || '';
        $line.html(
            '<textarea rows="2" class="form-control form-control-sm cart-row-comment-input" placeholder="Komentarz do pozycji...">' +
            escapeHtml(cur) +
            '</textarea>' +
            '<div class="mt-1">' +
            '<button type="button" class="btn btn-sm btn-success cart-row-comment-save mr-1" data-vp-id="' + vpId + '" title="Zapisz (Ctrl+Enter)"><i class="bi bi-check-lg"></i> Zapisz</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary cart-row-comment-cancel" title="Anuluj (Esc)">Anuluj</button>' +
            '</div>'
        );
        let $ta = $line.find('textarea').trigger('focus');
        // Place caret at end so users can extend an existing comment.
        let v = $ta.val();
        if (v) { $ta[0].selectionStart = $ta[0].selectionEnd = v.length; }
    });

    $cartBody.on('click', '.cart-row-comment-cancel', function () {
        renderCart();
    });

    $cartBody.on('click', '.cart-row-comment-save', function () {
        let $btn = $(this);
        let vpId = parseInt($btn.attr('data-vp-id'), 10);
        if (!vpId) return;
        let $line = $btn.closest('.cart-row-comment-line');
        let val = $.trim($line.find('textarea').val() || '');
        $btn.prop('disabled', true);
        let $cancelBtn = $line.find('.cart-row-comment-cancel').prop('disabled', true);

        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart/vendor-part-comment.php',
            method: 'POST',
            data: { vp_id: vpId, comment: val },
            dataType: 'json'
        }).done(function (resp) {
            if (resp && resp.success) {
                let vp = getVpById(vpId);
                if (vp) vp.private_comment = (val === '' ? null : val);
                renderCart();
                setAlert('Komentarz zapisany.', 'success');
            } else {
                setAlert('Błąd zapisu komentarza: ' + (resp && resp.error ? resp.error : 'nieznany'), 'danger');
                $btn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
            }
        }).fail(function () {
            setAlert('Błąd zapisu komentarza.', 'danger');
            $btn.prop('disabled', false);
            $cancelBtn.prop('disabled', false);
        });
    });

    $cartBody.on('keydown', '.cart-row-comment-input', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            renderCart();
        } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            $(this).closest('.cart-row-comment-line').find('.cart-row-comment-save').trigger('click');
        }
    });

    // Ilość → Opak.: packages = qty / picked pack size. Marking
    // lastEdited = $cartQty tells the even-warning helper which field
    // should carry the uneven class.
    $cartQty.on('input', function () {
        let pack = selectedPickedPackSize();
        let qty = parseFloat($(this).val());
        if (pack === null || isNaN(qty) || qty < 0) {
            state.lastDerived = null;
            state.lastEdited = $cartQty;
            applyEvenWarning($cartQty, NaN, pack);
            return;
        }
        let pkgs = qty / pack;
        $cartPackages.val(parseFloat(pkgs.toFixed(2)));
        // Raw quantity entered — no longer derived from packages.
        state.lastDerived = null;
        state.lastEdited = $cartQty;
        applyEvenWarning($cartQty, qty, pack);
    });

    $addToCartBtn.on('click', function () {
        if (pickerMode === 'add') {
            createNewVariant();
        } else {
            addCurrentSelectionToCart();
        }
    });

    // Remove cart item
    $cartBody.on('click', '.remove-item-btn', function () {
        const idx = parseInt($(this).data('idx'), 10);
        const item = cart.items[idx];
        if (!item) return;
        const label = (item.vendor_part_no || 'pozycja') +
            (item.producer_part_no ? ' (' + item.producer_part_no + ')' : '');
        cartConfirm({
            title: 'Usunąć pozycję?',
            body: '<p>Pozycja <strong>' + escapeHtml(label) + '</strong> zostanie trwale usunięta z koszyka.</p>',
            okLabel: 'Usuń',
            okClass: 'btn-danger',
            onConfirm: function () {
                cart.items.splice(idx, 1);
                renderCart();
                loadActiveDocs();
                saveCart();
                setAlert('Usunięto pozycję.', 'info');
            }
        });
    });

    // ---- Inline edit for cart rows (matches /admin/purchase/rfqs/ pattern) ----
    //
    // Click pencil → qty / price / uwagi cells swap to inputs, the
    // Akcje cell shows Save + Cancel only. Esc cancels, Enter (or
    // Ctrl+Enter in the textarea) saves. Save writes to cart.items[idx]
    // + localStorage and re-renders. Currency and picked_pack_size are
    // locked — same shape as RFQ lines (set when the item is added,
    // editable only by removing and re-adding).

    $cartBody.on('click', '.edit-item-btn', function () {
        // Belt-and-suspenders: the button is rendered with the disabled
        // attribute while activeDocsLoaded is false, but a stale click
        // can still slip through (devtools, race conditions). Guard.
        if (!cart.activeDocsLoaded) return;
        const $tr = $(this).closest('tr.cart-item-row');
        if ($tr.data('cart-editing')) return;
        const idx = parseInt($tr.attr('data-idx'), 10);
        const item = cart.items[idx];
        if (!item) return;
        $tr.data('cart-editing', true);

        // Stash original cell HTML so Cancel can restore verbatim.
        $tr.data('orig-qty-html',     $tr.find('td.cart-cell-qty').html());
        $tr.data('orig-price-html',   $tr.find('td.cart-cell-price').html());
        $tr.data('orig-value-html',   $tr.find('td.cart-cell-line-total').html());
        $tr.data('orig-comment-html', $tr.find('td.cart-cell-comment').html());
        $tr.data('orig-akcje-html',   $tr.find('td.cart-cell-akcje').html());
        // Stash currency so the live value-cell updater can render the
        // sub-line during edit (the price input's currency hint shows
        // the same value, but reading it from a single source avoids
        // parsing HTML).
        $tr.data('cart-edit-currency', item.currency || '');

        // Swap qty cell. With-pack lines show the qty input (primary) PLUS
        // an Opak. input + pack badge underneath, two-way synced —
        // mirrors the picker's Opak.↔Ilość pair. No-pack lines fall
        // back to a bare qty input.
        const unitName = item.unit_name || '';
        const pickedPack = itemPickedPackSize(item);
        if (pickedPack !== null && pickedPack > 0) {
            const currentQty = parseFloat(item.quantity) || 0;
            const currentPkgs = currentQty / pickedPack;
            $tr.data('cart-edit-pack', pickedPack);
            $tr.find('td.cart-cell-qty').empty().append(
                '<input type="number" step="any" min="0" class="form-control form-control-sm text-right cart-edit-input cart-edit-qty" value="' + escapeHtml(currentQty) + '">' +
                '<div class="d-flex align-items-center flex-wrap mt-1" style="gap:.25rem">' +
                    '<input type="number" step="1" min="0" class="form-control form-control-sm text-right cart-edit-input cart-edit-packages" ' +
                    'style="width:6em" value="' + escapeHtml(parseFloat(currentPkgs.toFixed(2))) + '" ' +
                    'title="Opak. — qty jest wyliczane jako opak. × wielkość">' +
                    '<small class="text-muted">opak.</small>' +
                    '<span class="badge badge-light border text-monospace" title="Wielkość opakowania (stała)">× ' + escapeHtml(formatQty(pickedPack)) + '</span>' +
                '</div>' +
                (unitName ? '<div><small class="text-muted">' + escapeHtml(unitName) + '</small></div>' : '')
            );
        } else {
            $tr.find('td.cart-cell-qty').empty().append(
                '<input type="number" step="any" min="0" class="form-control form-control-sm text-right cart-edit-input cart-edit-qty" value="' + escapeHtml(item.quantity || '') + '">' +
                (unitName ? '<div><small class="text-muted">' + escapeHtml(unitName) + '</small></div>' : '')
            );
        }

        // Swap price cell. Currency / JM sub-line stays as a hint.
        const priceInputVal = item.unit_price === null || item.unit_price === undefined ? '' : item.unit_price;
        $tr.find('td.cart-cell-price').empty().append(
            '<input type="number" step="any" min="0" class="form-control form-control-sm text-right cart-edit-input cart-edit-price" value="' + escapeHtml(priceInputVal) + '">' +
            '<div><small class="text-muted">' + escapeHtml(item.currency || 'PLN') + '/' + escapeHtml(unitName) + '</small></div>'
        );

        // Swap comment cell with a textarea.
        $tr.find('td.cart-cell-comment').empty().append(
            '<textarea rows="2" class="form-control form-control-sm cart-edit-comment" placeholder="Uwagi do pozycji...">' + escapeHtml(item.line_comment || '') + '</textarea>'
        );

        // Swap actions cell — Save + Cancel only.
        $tr.find('td.cart-cell-akcje').html(
            '<button type="button" class="btn btn-sm btn-success cart-row-save mr-1" title="Zapisz (Enter / Ctrl+Enter)"><i class="bi bi-check-lg"></i></button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary cart-row-cancel" title="Anuluj (Esc)"><i class="bi bi-x-lg"></i></button>'
        );

        $tr.data('cart-edit-idx', idx);
        // Focus the first numeric input in the row (packages if shown,
        // otherwise qty).
        const $firstInput = $tr.find('.cart-edit-packages, .cart-edit-qty').first();
        $firstInput.trigger('focus').trigger('select');
    });

    // Cancel → restore original cell HTML, leave edit mode. The value
    // cell is restored too because the live updater may have rewritten
    // it during the session — without this the row would show stale
    // Wartość after Cancel.
    $cartBody.on('click', '.cart-row-cancel', function () {
        const $tr = $(this).closest('tr.cart-item-row');
        if (!$tr.data('cart-editing')) return;
        $tr.find('td.cart-cell-qty').html($tr.data('orig-qty-html'));
        $tr.find('td.cart-cell-price').html($tr.data('orig-price-html'));
        $tr.find('td.cart-cell-line-total').html($tr.data('orig-value-html'));
        $tr.find('td.cart-cell-comment').html($tr.data('orig-comment-html'));
        $tr.find('td.cart-cell-akcje').html($tr.data('orig-akcje-html'));
        $tr.removeData('cart-editing');
        $tr.removeData(['orig-qty-html', 'orig-price-html', 'orig-value-html', 'orig-comment-html', 'orig-akcje-html', 'cart-edit-idx', 'cart-edit-pack', 'cart-edit-currency']);
    });

    // Two-way sync between qty input and packages input — mirrors the
    // picker's Opak.↔Ilość pair. Whichever input was edited gets the
    // uneven-pack yellow flag; the other clears.
    function applyPackSync($source, isPackagesSource) {
        const $tr = $source.closest('tr.cart-item-row');
        const pack = parseFloat($tr.data('cart-edit-pack'));
        if (!pack || pack <= 0) return;
        const raw = ($source.val() || '').toString().replace(',', '.');
        const val = parseFloat(raw);
        const $qtyInput     = $tr.find('.cart-edit-qty');
        const $packagesInput = $tr.find('.cart-edit-packages');
        if (isNaN(val) || val < 0) {
            // Source is empty/invalid — leave the other input alone but
            // clear any stale warning on this one so the user can fix.
            $source.removeClass('packages-uneven').removeAttr('title');
            return;
        }
        const newQty   = isPackagesSource ? val * pack : val;
        const newPkgs  = isPackagesSource ? val       : val / pack;
        // Write to the OTHER input only — never bounce back into the
        // currently focused one (would steal caret + duplicate digits).
        if (isPackagesSource) {
            if (document.activeElement !== $qtyInput[0]) {
                $qtyInput.val(parseFloat(newQty.toFixed(6)));
            }
        } else {
            if (document.activeElement !== $packagesInput[0]) {
                $packagesInput.val(parseFloat(newPkgs.toFixed(2)));
            }
        }
        // Apply yellow flag to the source input when the resulting qty
        // doesn't evenly divide the pack size; clear from the other.
        const qtyForCheck = isPackagesSource ? newQty : val;
        const even = Math.abs(qtyForCheck - Math.round(qtyForCheck / pack) * pack) < 1e-9 * pack;
        if (even) {
            $source.removeClass('packages-uneven').removeAttr('title');
        } else {
            $source.addClass('packages-uneven').attr(
                'title',
                'Uwaga: ilość nie odpowiada pełnej liczbie opakowań (wielkość: ' + formatQty(pack) + ')'
            );
        }
        const $other = isPackagesSource ? $qtyInput : $packagesInput;
        $other.removeClass('packages-uneven').removeAttr('title');
    }

    // Live-update the Wartość cell (qty × price) as the user edits qty,
    // price, or packages during inline edit. Reads the current input
    // values, computes the new line total, and rewrites the cell
    // content. Shows "—" when qty or price is missing/invalid;
    // otherwise formatPrice(qty × price) + currency sub-line, matching
    // the read-mode render and the RFQ pattern.
    function updateCartLineTotalCell($tr) {
        const qty = parseFloat(($tr.find('.cart-edit-qty').val() || '').toString().replace(',', '.'));
        const priceRaw = ($tr.find('.cart-edit-price').val() || '').toString().trim();
        const price = (priceRaw === '') ? null : parseFloat(priceRaw.replace(',', '.'));
        const $cell = $tr.find('.cart-cell-line-total');
        if (isNaN(qty) || qty <= 0 || price === null || isNaN(price)) {
            $cell.html('<span class="text-muted">—</span>');
        } else {
            // The currency sub-line sits on the row's data-* via the
            // cart item. We pull from the price input's sibling
            // currency hint in pick mode; here we read it from the
            // current price cell's currency sub-line so switching
            // currencies mid-edit would still propagate. Simpler:
            // stash currency at edit-open time.
            const currency = $tr.data('cart-edit-currency') || '';
            $cell.html(
                escapeHtml(formatPrice(qty * price)) +
                (currency
                    ? '<div><small class="text-muted">' + escapeHtml(currency) + '</small></div>'
                    : '')
            );
        }
    }

    $cartBody.on('input', '.cart-edit-qty',     function () { applyPackSync($(this), false); updateCartLineTotalCell($(this).closest('tr.cart-item-row')); });
    $cartBody.on('input', '.cart-edit-price',   function () { updateCartLineTotalCell($(this).closest('tr.cart-item-row')); });
    $cartBody.on('input', '.cart-edit-packages', function () { applyPackSync($(this), true);  updateCartLineTotalCell($(this).closest('tr.cart-item-row')); });

    // Save → write to cart.items[idx], persist, re-render. With-pack
    // lines read packages × pack_size; no-pack lines read the qty input.
    $cartBody.on('click', '.cart-row-save', function () {
        const $tr = $(this).closest('tr.cart-item-row');
        if (!$tr.data('cart-editing')) return;
        const idx = parseInt($tr.data('cart-edit-idx'), 10);
        const item = cart.items[idx];
        if (!item) return;

        // qty is the source of truth — packages input is a derived
        // convenience kept in sync via applyPackSync, so by save time
        // both fields agree (or the user only edited qty).
        const qtyRaw = ($tr.find('.cart-edit-qty').val() || '').toString().replace(',', '.');
        const qty    = parseFloat(qtyRaw);
        if (isNaN(qty) || qty <= 0) {
            setAlert('Ilość musi być > 0.', 'warning');
            $tr.find('.cart-edit-qty').trigger('focus');
            return;
        }

        const priceRaw = ($tr.find('.cart-edit-price').val() || '').toString().trim();
        const price    = (priceRaw === '') ? null : parseFloat(priceRaw.replace(',', '.'));
        const lineCmt  = ($tr.find('.cart-edit-comment').val() || '').toString();

        if (price !== null && (isNaN(price) || price < 0)) {
            setAlert('Cena nie może być ujemna.', 'warning');
            $tr.find('.cart-edit-price').trigger('focus');
            return;
        }

        // Trim + empty→null for storage.
        const trimmedCmt = $.trim(lineCmt);
        item.quantity    = qty;
        item.unit_price  = price;
        item.line_comment = trimmedCmt === '' ? null : trimmedCmt;
        // picked_pack_size + currency stay locked — matches RFQ UX.

        $tr.removeData('cart-editing');
        $tr.removeData(['orig-qty-html', 'orig-price-html', 'orig-value-html', 'orig-comment-html', 'orig-akcje-html', 'cart-edit-idx', 'cart-edit-pack', 'cart-edit-currency']);

        renderCart();
        loadActiveDocs();
        saveCart();
        setAlert('Pozycja zaktualizowana.', 'success');
    });

    // Keyboard shortcuts: Esc cancels; Enter (in qty/price inputs) saves;
    // Ctrl+Enter (in the comment textarea) saves — Enter alone in the
    // textarea still inserts a newline, matching documents-edit.js.
    $cartBody.on('keydown', '.cart-edit-input', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            $(this).closest('tr.cart-item-row').find('.cart-row-cancel').trigger('click');
        } else if (e.key === 'Enter') {
            e.preventDefault();
            $(this).closest('tr.cart-item-row').find('.cart-row-save').trigger('click');
        }
    });
    $cartBody.on('keydown', '.cart-edit-comment', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            $(this).closest('tr.cart-item-row').find('.cart-row-cancel').trigger('click');
        } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            $(this).closest('tr.cart-item-row').find('.cart-row-save').trigger('click');
        }
    });

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
        if (!name) name = ('#' + vid);
        cartConfirm({
            title: 'Usunąć pozycje dostawcy?',
            body: '<p>' + count + ' poz. dostawcy <strong>' + escapeHtml(name) + '</strong> zostanie trwale usuniętych z koszyka.</p>',
            okLabel: 'Usuń ' + count + ' poz.',
            okClass: 'btn-danger',
            onConfirm: function () {
                cart.items = cart.items.filter(function (i) { return parseInt(i.vendor_id, 10) !== vid; });
                renderCart();
                loadActiveDocs();
                saveCart();
                setAlert('Usunięto pozycje dostawcy ' + name + '.', 'info');
            }
        });
    });

    // Per-vendor-group create buttons ("Utwórz zapytanie" / "Utwórz zamówienie").
    // POST cart-create-document.php → server creates draft + items in one tx
    // → on success, remove used items from cart and open the edit URL in a
    // new tab (window.open with _blank). The new tab follows the URL the
    // server returned; the original tab keeps showing the remaining cart.
    $cartBody.on('click', '.create-doc-btn', function () {
        const docType = $(this).data('doc-type');
        const $btn = $(this);
        const vid = parseInt($btn.data('vendor-id'), 10) || 0;
        if (!vid) return;
        const items = cart.items.filter(function (i) { return parseInt(i.vendor_id, 10) === vid; });
        if (items.length === 0) return;
        // Summary the user sees in the confirmation modal — vendor name,
        // item count, and the running total. unit_price is "Cena/Szt."
        // (price per piece) and quantity is in pieces, so line value is
        // simply qty × price — pack size is a display/UX grouping, not
        // a quantity multiplier (an earlier draft multiplied by pack
        // size and produced wildly wrong totals). Currency comes from
        // the first item (mixed currencies within one vendor group are
        // uncommon but if present the user is best served by knowing
        // the dominant one).
        const vendorName = items[0].vendor_name || ('#' + vid);
        const itemCount = items.length;
        let totalValue = 0;
        let currency   = items[0].currency || 'PLN';
        items.forEach(function (it) {
            const qty   = parseFloat(it.quantity) || 0;
            const price = parseFloat(it.unit_price) || 0;
            totalValue += qty * price;
        });
        const isRfq = (docType === 'rfq');
        cartConfirm({
            title: isRfq ? 'Utworzyć zapytanie?' : 'Utworzyć zamówienie?',
            body: '<p>Dostawca: <strong>' + escapeHtml(vendorName) + '</strong></p>' +
                  '<p>Pozycji: <strong>' + itemCount + '</strong><br>' +
                  'Łączna wartość: <strong>' + escapeHtml(formatPrice(totalValue)) + ' ' + escapeHtml(currency) + '</strong></p>' +
                  '<p class="text-muted small mb-0">Dokument zostanie utworzony jako szkic i otwarty w nowej karcie.</p>',
            okLabel: isRfq ? 'Utwórz zapytanie' : 'Utwórz zamówienie',
            okClass: 'btn-success',
            onConfirm: function () { createDocumentFromVendor(docType, vid, $btn); }
        });
    });

    function createDocumentFromVendor(docType, vid, $btn) {
        if (cart.items.length === 0) return;
        // Items for THIS vendor only — each vendor group has its own
        // create buttons, and a PO/RFQ is locked to one vendor.
        let items = cart.items.filter(function (i) { return parseInt(i.vendor_id, 10) === vid; });
        if (items.length === 0) return;

        // jQuery's $.ajax with a structured data object emits form-encoded
        // nested arrays automatically (PHP parses items[0][vendor_part_id]).
        let payload = {
            type:      docType,
            vendor_id: vid,
            items:     items.map(function (i) {
                return {
                    vendor_part_id  : i.vendor_part_id,
                    quantity        : i.quantity,
                    quantity_unit_id: i.vendor_jm_id || 0,
                    unit_price      : i.unit_price,
                    currency        : i.currency || 'PLN',
                    picked_pack_size: i.picked_pack_size !== undefined ? i.picked_pack_size : null,
                    // "Uwagi do pozycji" — travels to
                    // purchase__rfq_item.comment / purchase__order_item.comment.
                    // Null when blank (server trims empty → null).
                    comment         : i.line_comment || ''
                };
            })
        };

        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart/cart-create-document.php',
            type: 'POST',
            dataType: 'json',
            data: payload
        }).done(function (r) {
            if (!r || !r.success) {
                setAlert((r && r.error) ? r.error : 'Błąd tworzenia dokumentu.', 'danger');
                return;
            }
            // Drop the used items from the local cart. Server mirrors the
            // IDs back in used_vendor_part_ids so we don't need to re-derive.
            let usedIds = {};
            (r.used_vendor_part_ids || []).forEach(function (id) { usedIds[id] = true; });
            cart.items = cart.items.filter(function (i) { return !usedIds[i.vendor_part_id]; });

            // Open the edit page in a new tab. window.open returns null if
            // the browser blocked the popup — warn the user but keep the
            // cart cleared (the doc IS created; user can find it later).
            let win = window.open(r.edit_url, '_blank');
            if (!win) {
                setAlert(r.message + ' (Okno edycji zablokowane przez przeglądarkę — dokument istnieje pod adresem: ' + r.edit_url + ')', 'warning');
            } else {
                setAlert(r.message + ' Pozycje usunięte z koszyka.', 'success');
            }
            renderCart();
            loadActiveDocs();
            saveCart();
        }).fail(function (xhr, textStatus) {
            let msg = 'Błąd serwera (' + textStatus + ').';
            if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                msg = xhr.responseJSON.error;
            } else if (xhr && xhr.responseText) {
                let m = xhr.responseText.match(/"error"\s*:\s*"([^"]+)"/);
                if (m) msg = m[1];
            }
            setAlert(msg, 'danger');
        });
    }

    // Clear cart
    $clearBtn.on('click', function () {
        if (cart.items.length === 0) { return; }
        const count = cart.items.length;
        cartConfirm({
            title: 'Wyczyścić koszyk?',
            body: '<p>' + count + ' pozycji zostanie trwale usuniętych z koszyka. Tej operacji nie można cofnąć.</p>',
            okLabel: 'Wyczyść koszyk',
            okClass: 'btn-danger',
            onConfirm: function () {
                cart.items = [];
                cart.activeDocs = {};
                clearSavedCart();
                renderCart();
                setAlert('Koszyk wyczyszczony.', 'info');
            }
        });
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
