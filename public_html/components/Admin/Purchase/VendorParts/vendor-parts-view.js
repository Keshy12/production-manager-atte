$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/VendorParts/";

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function showAlert(message, type) {
        if(!type) type = 'success';
        const html = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">'
            + message
            + '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>'
            + '</div>';
        $('#alertContainer').html(html);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    function getAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'GET', data: data, dataType: 'json' });
    }

    function searchAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'GET', data: data, dataType: 'json' });
    }

    // ============================================================
    // Searchable select bootstrap helper
    // ============================================================
    function fillSelect(selectId, hiddenId, items, currentVal) {
        let opts = '<option value="">Wybierz...</option>';
        (items || []).forEach(function(item) {
            const sel = currentVal && String(item.id) === String(currentVal) ? ' selected' : '';
            opts += '<option value="' + esc(item.id) + '"' + sel + '>' + esc(item.label || item.name) + '</option>';
        });
        $('#' + selectId).html(opts);
        if(currentVal) { $('#' + hiddenId).val(currentVal); }
        $('#' + selectId).selectpicker('refresh');
    }

    function bindSearchable(selectId, hiddenId, endpoint) {
        $('#' + selectId).on('changed.bs.select', function() {
            $('#' + hiddenId).val($(this).val());
        });
        $(document).on('keyup', '.bs-searchbox input', function() {
            const $sel = $(this).closest('.bootstrap-select').find('select');
            if($sel.attr('id') !== selectId) return;
            const q = $(this).val();
            searchAjax(endpoint, { q: q })
                .done(function(r) { fillSelect(selectId, hiddenId, r, null); });
        });
    }

    // Initial load of all dropdowns for the add form
    function initAddDropdowns() {
        searchAjax('vp-search-vendors.php',  { q: '' }).done(function(r) { fillSelect('add_vendor_select',  'add_vendor_id',  r, null); });
        searchAjax('vp-search-producers.php', { q: '' }).done(function(r) { fillSelect('add_producer_select', 'add_producer_id', r, null); });
        searchAjax('vp-search-parts.php',     { q: '' }).done(function(r) { fillSelect('add_part_select',     'add_part_id',     r, null); });
        searchAjax('vp-search-units.php',     { q: '' }).done(function(r) { fillSelect('add_unit_select',     'add_unit_id',     r, null); });
    }
    initAddDropdowns();

    bindSearchable('add_vendor_select',  'add_vendor_id',  'vp-search-vendors.php');
    bindSearchable('add_producer_select', 'add_producer_id', 'vp-search-producers.php');
    bindSearchable('add_part_select',     'add_part_id',     'vp-search-parts.php');
    bindSearchable('add_unit_select',     'add_unit_id',     'vp-search-units.php');

    // ============================================================
    // Add form submit
    // ============================================================
    $('#addVpForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            vendor_id:          $('#add_vendor_id').val(),
            producer_id:        $('#add_producer_id').val(),
            parts_id:           $('#add_part_id').val(),
            vendor_part_no:     $('#add_vendor_part_no').val().trim(),
            vendor_jm_id:       $('#add_unit_id').val(),
            full_pack_quantity: $('#add_full_pack_quantity').val() || '1',
            comment:            $('#add_comment').val().trim(),
        };
        if(!data.vendor_id || !data.producer_id || !data.parts_id || !data.vendor_jm_id) {
            showAlert('Dostawca, producent, part i jednostka są wymagane', 'warning'); return;
        }
        if(!data.vendor_part_no) { showAlert('Vendor part no jest wymagany', 'warning'); return; }
        postAjax('vp-add.php', data)
            .done(function(r) {
                if(r.success) {
                    showAlert(r.message || 'Artykuł dodany', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ============================================================
    // Edit modal
    // ============================================================
    bindSearchable('edit_vendor_select',  'edit_vp_vendor_id',  'vp-search-vendors.php');
    bindSearchable('edit_producer_select', 'edit_vp_producer_id', 'vp-search-producers.php');
    bindSearchable('edit_part_select',     'edit_vp_part_id',     'vp-search-parts.php');
    bindSearchable('edit_unit_select',     'edit_vp_unit_id',     'vp-search-units.php');

    $(document).on('click', '.edit-vp-btn', function() {
        const id = parseInt($(this).data('id'), 10);
        getAjax('vp-get.php', { id: id })
            .done(function(r) {
                if(r.success) {
                    const vp = r.vendorPart;
                    $('#edit_vp_id').val(vp.id);
                    $('#edit_vp_vendor_part_no').val(vp.vendorPartNo || '');
                    $('#edit_vp_full_pack_quantity').val(vp.fullPackQuantity);
                    $('#edit_vp_comment').val(vp.comment || '');

                    // Pre-fill dropdowns with current values + reload full lists
                    searchAjax('vp-search-vendors.php',  { q: '' }).done(function(list) {
                        const item = list.find(function(x) { return String(x.id) === String(vp.vendorId); });
                        const merged = item ? [item].concat(list.filter(function(x) { return String(x.id) !== String(vp.vendorId); })) : list;
                        fillSelect('edit_vendor_select', 'edit_vp_vendor_id', merged, vp.vendorId);
                    });
                    searchAjax('vp-search-producers.php', { q: '' }).done(function(list) {
                        const item = list.find(function(x) { return String(x.id) === String(vp.producerId); });
                        const merged = item ? [item].concat(list.filter(function(x) { return String(x.id) !== String(vp.producerId); })) : list;
                        fillSelect('edit_producer_select', 'edit_vp_producer_id', merged, vp.producerId);
                    });
                    searchAjax('vp-search-parts.php', { q: '' }).done(function(list) {
                        const item = list.find(function(x) { return String(x.id) === String(vp.partsId); });
                        const merged = item ? [item].concat(list.filter(function(x) { return String(x.id) !== String(vp.partsId); })) : list;
                        fillSelect('edit_part_select', 'edit_vp_part_id', merged, vp.partsId);
                    });
                    searchAjax('vp-search-units.php', { q: '' }).done(function(list) {
                        const item = list.find(function(x) { return String(x.id) === String(vp.vendorJmId); });
                        const merged = item ? [item].concat(list.filter(function(x) { return String(x.id) !== String(vp.vendorJmId); })) : list;
                        fillSelect('edit_unit_select', 'edit_vp_unit_id', merged, vp.vendorJmId);
                    });

                    $('#editVpModal').modal('show');
                } else { showAlert(r.error || 'Błąd ładowania', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $('#editVpForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            id:                 $('#edit_vp_id').val(),
            vendor_id:          $('#edit_vp_vendor_id').val(),
            producer_id:        $('#edit_vp_producer_id').val(),
            parts_id:           $('#edit_vp_part_id').val(),
            vendor_part_no:     $('#edit_vp_vendor_part_no').val().trim(),
            vendor_jm_id:       $('#edit_vp_unit_id').val(),
            full_pack_quantity: $('#edit_vp_full_pack_quantity').val() || '1',
            comment:            $('#edit_vp_comment').val().trim(),
        };
        if(!data.vendor_id || !data.producer_id || !data.parts_id || !data.vendor_jm_id) {
            showAlert('Dostawca, producent, part i jednostka są wymagane', 'warning'); return;
        }
        if(!data.vendor_part_no) { showAlert('Vendor part no jest wymagany', 'warning'); return; }
        postAjax('vp-update.php', data)
            .done(function(r) {
                if(r.success) {
                    $('#editVpModal').modal('hide');
                    showAlert(r.message || 'Zapisano', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd zapisu', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ============================================================
    // Toggle active
    // ============================================================
    $(document).on('click', '.toggle-vp-btn', function() {
        const id = parseInt($(this).data('id'), 10);
        const isActive = $(this).data('is-active') == 1;
        $('#toggle_vp_id').val(id);
        $('#toggle_vp_is_active').val(isActive ? '1' : '0');
        const verb = isActive ? 'wyłączyć' : 'włączyć';
        $('#toggle_vp_body').html('Czy na pewno chcesz <b>' + verb + '</b> ten artykuł?');
        $('#toggleVpModal').modal('show');
    });

    $('#confirmToggleVp').on('click', function() {
        const id = parseInt($('#toggle_vp_id').val(), 10);
        const isActive = $('#toggle_vp_is_active').val() === '1';
        postAjax('vp-toggle-active.php', { id: id, is_active: isActive ? 1 : 0 })
            .done(function(r) {
                if(r.success) {
                    $('#toggleVpModal').modal('hide');
                    showAlert(r.message || 'Status zmieniony', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });
});
