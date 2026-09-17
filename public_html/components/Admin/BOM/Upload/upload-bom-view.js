/* ------------------------------------------------------------------
 *  BOM upload — DB vs CSV comparison view
 * ------------------------------------------------------------------ */

const BOM_SECTIONS = {
    tht: { tag: 'THT', accent: '#5a6876' },
    smd: { tag: 'SMD', accent: '#2f6f7e' }
};

/* ---------------------------------------------------------- Upload */

$("#uploadBomInput").change(function () {
    const fileName = $(this).val().split("\\").pop();
    $("#uploadBomLabel").text(fileName || "Wybierz plik...");

    const $form = $("#uploadBomForm");
    const formData = new FormData($form[0]);

    resetComparison();

    $.ajax({
        type: "POST",
        url: $form.attr('action'),
        data: formData,
        cache: false,
        contentType: false,
        processData: false,
        success: function (result) {
            const fatalErrors = result[0];
            const nonFatalErrors = result[1];
            const THTBomFlat = result[2];
            const SMDBomFlat = result[3];

            renderErrors(fatalErrors, 'alert-danger');
            renderErrors(nonFatalErrors, 'alert-warning');

            if (Object.keys(fatalErrors).length !== 0) return;

            const hasSMD = Object.keys(SMDBomFlat).length !== 0;

            $("#sendBom").attr("data-tht", JSON.stringify({
                bomId: THTBomFlat["bomId"],
                deviceId: THTBomFlat["deviceId"],
                deviceName: THTBomFlat["deviceName"],
                deviceVersion: THTBomFlat["deviceVersion"],
                defaultBomId: THTBomFlat["defaultBomId"],
                defaultBomVersion: THTBomFlat["defaultBomVersion"],
                bomFlat: THTBomFlat["csv"]
            }));
            $("#sendBom").attr("data-smd", JSON.stringify(hasSMD ? {
                bomId: SMDBomFlat["bomId"],
                deviceId: SMDBomFlat["deviceId"],
                laminateId: SMDBomFlat["laminateId"],
                laminateName: SMDBomFlat["laminateName"],
                deviceName: SMDBomFlat["deviceName"],
                deviceVersion: SMDBomFlat["deviceVersion"],
                defaultBomId: SMDBomFlat["defaultBomId"],
                defaultBomVersion: SMDBomFlat["defaultBomVersion"],
                defaultBomLaminate: SMDBomFlat["defaultBomLaminate"],
                bomFlat: SMDBomFlat["csv"]
            } : {}));

            renderComparison(THTBomFlat, hasSMD ? SMDBomFlat : null);
            $("#tableContainer").prop('hidden', false);
            syncStickyOffsets();
        }
    });
});

function resetComparison() {
    $("#errorsContainer, #bomTBody").empty();
    $("#tableContainer").prop('hidden', true);
    $("#bomTBody").addClass("bom-collapsed");
    $(".bom-filter__btn").removeClass("is-active")
        .filter('[data-filter="diff"]').addClass("is-active");
}

function renderErrors(errors, alertClass) {
    $.each(errors, function (index, message) {
        const $alert = $('<div class="alert alert-dismissible show fade" role="alert"></div>')
            .addClass(alertClass)
            .append('<button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>')
            .append(message);
        $('#errorsContainer').append($alert);
    });
}

/* ---------------------------------------------------------- Rendering */

function renderComparison(thtBom, smdBom) {
    const $tbody = $("#bomTBody");
    const totals = { same: 0, qty: 0, added: 0, removed: 0 };

    const thtEntries = diffBoms(thtBom['db'], thtBom['csv']);
    appendSection($tbody, 'tht', thtEntries, false, [
        { label: 'ver.', value: thtBom['deviceVersion'] }
    ], thtBom['deviceName']);
    appendEntries($tbody, thtEntries, totals);

    if (smdBom) {
        const smdEntries = diffBoms(smdBom['db'], smdBom['csv']);
        appendSection($tbody, 'smd', smdEntries, true, [
            { label: 'laminat', value: smdBom['laminateName'] },
            { label: 'ver.', value: smdBom['deviceVersion'] }
        ], smdBom['deviceName']);
        appendEntries($tbody, smdEntries, totals);
    }

    updateLegend(totals);
}

/**
 * Pairs DB components with their CSV counterparts and classifies each pair.
 * Returns [{ status, db, csv }] in source order (DB first, CSV-only last).
 */
function diffBoms(dbBomFlat, csvBomFlat) {
    const dbArr = Object.values(dbBomFlat || {});
    const csvArr = Object.values(csvBomFlat || {});
    const entries = [];

    dbArr.forEach(function (dbComp) {
        const csvComp = findAndRemoveMatchingComponent(dbComp, csvArr);
        if (!csvComp) {
            entries.push({ status: 'removed', db: dbComp, csv: null });
            return;
        }
        const sameQty = normalizeQty(dbComp.quantity) === normalizeQty(csvComp.quantity);
        entries.push({ status: sameQty ? 'same' : 'qty', db: dbComp, csv: csvComp });
    });

    csvArr.forEach(function (csvComp) {
        entries.push({ status: 'added', db: null, csv: csvComp });
    });

    return entries;
}

function appendSection($tbody, kind, entries, isSplit, metaParts, deviceName) {
    const meta = BOM_SECTIONS[kind];
    const counts = countStatuses(entries);

    const $inner = $('<div class="bom-section__inner">')
        .css('--bom-section-accent', meta.accent);

    $inner.append($('<span class="bom-section__tag">').text(meta.tag));
    $inner.append($('<span class="bom-section__title">').text(deviceName || '—'));

    metaParts.forEach(function (part) {
        if (!part.value) return;
        $inner.append(
            $('<span class="bom-section__meta">')
                .text(part.label + ' ')
                .append($('<b>').text(part.value))
        );
    });

    const $stats = $('<span class="bom-section__stats">');
    if (counts.qty)     $stats.append(statPill('qty', counts.qty + ' zmian ilości'));
    if (counts.added)   $stats.append(statPill('added', '+' + counts.added + ' nowych'));
    if (counts.removed) $stats.append(statPill('removed', '−' + counts.removed + ' brakujących'));
    if (!counts.qty && !counts.added && !counts.removed) {
        $stats.append(statPill('clean', 'zgodne — ' + entries.length + ' poz.'));
    }
    $inner.append($stats);

    const $row = $('<tr class="bom-section">').toggleClass('bom-section--split', !!isSplit);
    $row.append($('<td colspan="5">').append($inner));
    $tbody.append($row);

    // Placeholders for an otherwise empty view
    if (!entries.length) {
        $tbody.append(emptyRow('Brak pozycji w tej sekcji.', false));
    } else if (!counts.qty && !counts.added && !counts.removed) {
        $tbody.append(emptyRow('Brak różnic — wszystkie pozycje zgodne z bazą.', true));
    }
}

function statPill(kind, label) {
    return $('<span class="bom-section__stat">').addClass('bom-section__stat--' + kind).text(label);
}

function emptyRow(message, onlyWhenCollapsed) {
    const $row = $('<tr class="bom-empty">').toggleClass('bom-empty-diff', !!onlyWhenCollapsed);
    const $cell = $('<td colspan="5">');
    if (onlyWhenCollapsed) $cell.append('<i class="bi bi-check-circle-fill"></i>');
    $cell.append(document.createTextNode(message));
    return $row.append($cell);
}

function appendEntries($tbody, entries, totals) {
    entries.forEach(function (entry) {
        totals[entry.status] += 1;
        $tbody.append(buildRow(entry));
    });
}

function buildRow(entry) {
    const $row = $('<tr class="bom-row">').addClass('bom-row--' + entry.status);

    $row.append(componentCell(entry.db));
    $row.append(quantityCell(entry.db, null));
    $row.append(statusCell(entry.status));
    $row.append(componentCell(entry.csv));
    $row.append(quantityCell(entry.csv, entry.status === 'qty' ? entry.db : null));

    return $row;
}

function componentCell(component) {
    const $cell = $('<td class="bom-cell-name">');
    if (!component) {
        return $cell.append($('<span class="bom-void">').text('—'));
    }
    const description = component.componentDescription || '';
    $cell.append($('<span class="bom-code">').text(component.componentName || '—'));
    if (description) {
        $cell.append($('<span class="bom-desc">').attr('title', description).text(description));
    }
    return $cell;
}

function quantityCell(component, compareAgainst) {
    const $cell = $('<td class="bom-cell-qty">');
    if (!component || component.quantity === undefined || component.quantity === null || component.quantity === '') {
        return $cell.append($('<span class="bom-void">').text('—'));
    }

    $cell.append(document.createTextNode(String(component.quantity)));

    if (compareAgainst) {
        const delta = normalizeQty(component.quantity) - normalizeQty(compareAgainst.quantity);
        if (!isNaN(delta) && delta !== 0) {
            $cell.append(
                $('<small class="bom-delta">')
                    .addClass(delta > 0 ? 'bom-delta--up' : 'bom-delta--down')
                    .text((delta > 0 ? '+' : '−') + Math.abs(delta))
            );
        }
    }
    return $cell;
}

const STATUS_MARKS = {
    same:    { icon: '',                      title: 'Bez zmian' },
    qty:     { icon: 'bi-arrow-left-right',   title: 'Zmieniona ilość' },
    added:   { icon: 'bi-plus-lg',            title: 'Nowa pozycja — jest tylko w pliku CSV' },
    removed: { icon: 'bi-dash-lg',            title: 'Brak w pliku CSV — zostanie usunięta' }
};

function statusCell(status) {
    const mark = STATUS_MARKS[status];
    const $mark = $('<span class="bom-mark">')
        .addClass('bom-mark--' + status)
        .attr('title', mark.title);
    if (mark.icon) $mark.append($('<i>').addClass('bi ' + mark.icon));
    return $('<td class="bom-cell-status">').append($mark);
}

function countStatuses(entries) {
    const counts = { same: 0, qty: 0, added: 0, removed: 0 };
    entries.forEach(function (entry) { counts[entry.status] += 1; });
    return counts;
}

function updateLegend(totals) {
    const map = { changed: totals.qty, added: totals.added, removed: totals.removed, same: totals.same };
    $('#bomLegend .bom-chip').each(function () {
        const value = map[$(this).data('count')] || 0;
        $(this).find('b').text(value);
        $(this).toggleClass('is-zero', value === 0);
    });
}

function normalizeQty(value) {
    const num = parseFloat(value);
    return isNaN(num) ? String(value === undefined || value === null ? '' : value) : num;
}

// Finds and removes a matching component from the array
function findAndRemoveMatchingComponent(component, array) {
    const index = array.findIndex(item =>
        item.type === component.type &&
        item.componentId === component.componentId &&
        item.componentName === component.componentName
    );

    if (index !== -1) return array.splice(index, 1)[0];
    return null;
}

/* ---------------------------------------------------------- Sticky offsets */

function syncStickyOffsets() {
    const $headRows = $('#bomTable thead tr');
    if (!$headRows.length) return;
    const row1 = Math.round($headRows.eq(0).outerHeight() || 36);
    const row2 = Math.round($headRows.eq(1).outerHeight() || 36);
    const root = document.getElementById('bomUpload');
    if (!root) return;
    root.style.setProperty('--bom-row1-h', row1 + 'px');
    root.style.setProperty('--bom-head-h', (row1 + row2) + 'px');
}

$(window).on('resize', syncStickyOffsets);

/* ---------------------------------------------------------- Filter */

$(document).on("click", ".bom-filter__btn", function () {
    const showAll = $(this).data("filter") === "all";
    $(".bom-filter__btn").removeClass("is-active");
    $(this).addClass("is-active");
    $("#bomTBody").toggleClass("bom-collapsed", !showAll);
});

/* ---------------------------------------------------------- Submit */

$("#sendBom").click(function () {
    const thtData = $(this).attr("data-tht");
    const smdData = $(this).attr("data-smd");
    const thtInfo = JSON.parse(thtData);
    const smdInfo = smdData ? JSON.parse(smdData) : null;

    // Check if uploaded BOM is not already the default
    const thtNeedsDefault = thtInfo.bomId && thtInfo.bomId !== thtInfo.defaultBomId;
    const smdNeedsDefault = smdInfo && smdInfo.deviceId && smdInfo.bomId && smdInfo.bomId !== smdInfo.defaultBomId;

    if (thtNeedsDefault || smdNeedsDefault) {
        // Populate THT row
        if (thtNeedsDefault) {
            $("#thtDeviceName").text(thtInfo.deviceName);
            $("#thtCurrentDefault").text(thtInfo.defaultBomVersion || "brak");
            $("#thtNewVersion").text(thtInfo.deviceVersion);
            $("#defaultThtRow").show().removeClass("is-disabled");
            $("#setDefaultThtCheck").prop("checked", true);
        } else {
            $("#defaultThtRow").hide();
        }

        // Populate SMD row (show laminate + version)
        if (smdNeedsDefault) {
            $("#smdDeviceName").text(smdInfo.deviceName);
            const currentDefault = smdInfo.defaultBomId 
                ? (smdInfo.defaultBomLaminate || "?") + " / " + (smdInfo.defaultBomVersion || "?")
                : "brak";
            const newVersion = (smdInfo.laminateName || "?") + " / " + (smdInfo.deviceVersion || "?");
            $("#smdCurrentDefault").text(currentDefault);
            $("#smdNewVersion").text(newVersion);
            $("#defaultSmdRow").show().removeClass("is-disabled");
            $("#setDefaultSmdCheck").prop("checked", true);
        } else {
            $("#defaultSmdRow").hide();
        }

        // Toggle greyed state on checkbox change
        $("#setDefaultThtCheck, #setDefaultSmdCheck").off("change").on("change", function() {
            $(this).closest(".custom-control").toggleClass("is-disabled", !$(this).is(":checked"));
        });

        $("#setDefaultSmdModal").modal("show");

        // Confirm button - read checkboxes and submit
        $("#setDefaultConfirm").off("click").on("click", function () {
            const setDefaultTht = thtNeedsDefault && $("#setDefaultThtCheck").is(":checked");
            const setDefaultSmd = smdNeedsDefault && $("#setDefaultSmdCheck").is(":checked");
            $("#setDefaultSmdModal").modal("hide");
            submitBom(thtData, smdData, setDefaultSmd, setDefaultTht);
        });
    } else {
        // Neither needs default, submit directly
        submitBom(thtData, smdData, false, false);
    }
});

function submitBom(thtData, smdData, setDefaultSmd, setDefaultTht) {
    const $button = $("#sendBom");
    $button.prop("disabled", true);

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/admin/bom/upload/upload-bom.php",
        data: { thtData: thtData, smdData: smdData, setDefaultSmd: setDefaultSmd, setDefaultTht: setDefaultTht },
        success: function (result) {
            const resultMessage = result[0];
            const wasSuccessful = result[1];
            showToast(wasSuccessful ? "alert-success" : "alert-danger", resultMessage);
            if (wasSuccessful) $("#uploadBomInput").change();
        },
        complete: function () {
            $button.prop("disabled", false);
        }
    });
}

function showToast(alertType, alertMessage) {
    const $alert = $(getAlertString(alertType, alertMessage));
    $("#ajaxResult").append($alert);
    setTimeout(function () { $alert.alert('close'); }, 6000);
}

function getAlertString(alertType, alertMessage) {
    return `<div class="alert ` + alertType + ` alert-dismissible fade show" role="alert">
                ` + alertMessage + `
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
}
