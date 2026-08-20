$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Vendors/";
    const searchBase = COMPONENTS_PATH + "/Admin/Purchase/VendorParts/";

    // Currently-open vendor context (for the detail modal)
    let currentVendorId = null;
    let currentDetailData = { vendor: null, suppliers: [], vendorParts: [] };

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
        return $.ajax({ url: searchBase + endpoint, type: 'GET', data: data, dataType: 'json' });
    }

    // ============================================================
    // Vendor CRUD
    // ============================================================

    $('#addVendorForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            name:            $('#vendor_name').val().trim(),
            address:         $('#vendor_address').val().trim(),
            additional_data: $('#vendor_additional_data').val().trim(),
            lead_time_days:  $('#vendor_lead_time').val(),
            comment:         $('#vendor_comment').val().trim(),
        };
        if(!data.name) { showAlert('Nazwa dostawcy jest wymagana', 'warning'); return; }
        postAjax('vendor-add.php', data)
            .done(function(r) {
                if(r.success) {
                    showAlert('Dostawca dodany pomyślnie', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $(document).on('click', '.edit-vendor-btn', function() {
        const id = $(this).data('id');
        getAjax('vendor-get.php', { id: id })
            .done(function(r) {
                if(r.success) {
                    $('#edit_vendor_id').val(r.vendor.id);
                    $('#edit_vendor_name').val(r.vendor.name || '');
                    $('#edit_vendor_address').val(r.vendor.address || '');
                    $('#edit_vendor_additional_data').val(r.vendor.additionalData || '');
                    $('#edit_vendor_lead_time').val(r.vendor.leadTimeDays == null ? '' : r.vendor.leadTimeDays);
                    $('#edit_vendor_comment').val(r.vendor.comment || '');
                    $('#editVendorModal').modal('show');
                } else { showAlert(r.error || 'Błąd ładowania', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $('#editVendorForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            id:              $('#edit_vendor_id').val(),
            name:            $('#edit_vendor_name').val().trim(),
            address:         $('#edit_vendor_address').val().trim(),
            additional_data: $('#edit_vendor_additional_data').val().trim(),
            lead_time_days:  $('#edit_vendor_lead_time').val(),
            comment:         $('#edit_vendor_comment').val().trim(),
        };
        if(!data.name) { showAlert('Nazwa jest wymagana', 'warning'); return; }
        postAjax('vendor-update.php', data)
            .done(function(r) {
                if(r.success) {
                    $('#editVendorModal').modal('hide');
                    showAlert('Zmiany zapisane', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd zapisu', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $(document).on('click', '.toggle-vendor-btn', function() {
        const id = $(this).data('id');
        const isActive = $(this).data('is-active') == 1;
        const name = $(this).data('name');
        const hasParts = $(this).data('has-parts') == 1;
        $('#toggle_vendor_id').val(id);
        $('#toggle_vendor_is_active').val(isActive ? '1' : '0');
        const verb = isActive ? 'wyłączyć' : 'włączyć';
        let body = 'Czy na pewno chcesz <b>' + verb + '</b> dostawcę <b>' + esc(name) + '</b>?';
        if(isActive && hasParts) {
            body += '<br><br><span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Ten dostawca ma przypisane artykuły. Wyłączenie nie usunie artykułów.</span>';
        }
        $('#toggle_vendor_body').html(body);
        $('#toggleVendorModal').modal('show');
    });

    $('#confirmToggleVendor').on('click', function() {
        const id = parseInt($('#toggle_vendor_id').val(), 10);
        const isActive = $('#toggle_vendor_is_active').val() === '1';
        postAjax('vendor-toggle-active.php', { id: id, is_active: isActive ? 1 : 0 })
            .done(function(r) {
                if(r.success) {
                    $('#toggleVendorModal').modal('hide');
                    if($('#detailVendorModal').is(':visible') && currentVendorId === id) {
                        $('#detailVendorModal').modal('hide');
                    }
                    showAlert(r.message || 'Status zmieniony', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ============================================================
    // Detail modal (suppliers + vendor parts)
    // ============================================================

    function renderSupplierRow(s) {
        const isActive = s.isActive == 1 || s.isActive === true;
        const statusBadge = isActive
            ? '<span class="badge badge-success">Aktywna</span>'
            : '<span class="badge badge-danger">Wyłączona</span>';
        const toggleBtnClass = isActive ? 'btn-danger' : 'btn-success';
        const toggleIcon = isActive ? 'x-circle' : 'check-circle';
        return '<tr>'
            + '<td>' + esc(s.name) + '</td>'
            + '<td>' + esc(s.jobTitle || '') + '</td>'
            + '<td>' + esc(s.phone || '') + '</td>'
            + '<td>' + esc(s.email || '') + '</td>'
            + '<td>' + statusBadge + '</td>'
            + '<td>'
            +   '<div class="btn-group" role="group">'
            +     '<button class="btn btn-sm btn-warning edit-supplier-btn" data-id="' + s.id + '"><i class="bi bi-pencil"></i></button>'
            +     '<button class="btn btn-sm ' + toggleBtnClass + ' toggle-supplier-btn"'
            +       ' data-id="' + s.id + '" data-is-active="' + (isActive ? '1' : '0') + '" data-name="' + esc(s.name) + '">'
            +       '<i class="bi bi-' + toggleIcon + '"></i>'
            +     '</button>'
            +   '</div>'
            + '</td>'
            + '</tr>';
    }

    function renderVendorPartRow(vp) {
        const isActive = vp.isActive == 1 || vp.isActive === true;
        const statusBadge = isActive
            ? '<span class="badge badge-success">Aktywny</span>'
            : '<span class="badge badge-danger">Nieaktywny</span>';
        const toggleBtnClass = isActive ? 'btn-danger' : 'btn-success';
        const toggleIcon = isActive ? 'x-circle' : 'check-circle';
        return '<tr>'
            + '<td>' + esc(vp.producerName || '—') + '</td>'
            + '<td>' + esc(vp.partName || '—') + '</td>'
            + '<td>' + esc(vp.vendorPartNo || '') + '</td>'
            + '<td>' + esc(vp.unitName || '—') + '</td>'
            + '<td class="text-center">' + esc(vp.fullPackQuantity) + '</td>'
            + '<td>' + statusBadge + '</td>'
            + '<td>'
            +   '<button class="btn btn-sm ' + toggleBtnClass + ' toggle-vp-btn"'
            +       ' data-id="' + vp.id + '" data-is-active="' + (isActive ? '1' : '0') + '">'
            +       '<i class="bi bi-' + toggleIcon + '"></i>'
            +   '</button>'
            + '</td>'
            + '</tr>';
    }

    function renderDetail(data) {
        currentDetailData = data;
        $('#detail_vendor_name').text(data.vendor.name);

        const suppliers = data.suppliers || [];
        $('#detail_supplier_count').text(suppliers.length);
        if(suppliers.length === 0) {
            $('#suppliersTBody').html('');
            $('#suppliers_empty').show();
        } else {
            let html = '';
            suppliers.forEach(function(s) { html += renderSupplierRow(s); });
            $('#suppliersTBody').html(html);
            $('#suppliers_empty').hide();
        }

        const vps = data.vendorParts || [];
        $('#detail_vendor_part_count').text(vps.length);
        if(vps.length === 0) {
            $('#vendorPartsTBody').html('');
            $('#vendor_parts_empty').show();
        } else {
            let html = '';
            vps.forEach(function(vp) { html += renderVendorPartRow(vp); });
            $('#vendorPartsTBody').html(html);
            $('#vendor_parts_empty').hide();
        }
    }

    function reloadDetail() {
        if(!currentVendorId) return;
        getAjax('vendor-detail.php', { id: currentVendorId })
            .done(function(r) {
                if(r.success) { renderDetail(r); }
                else { showAlert(r.error || 'Błąd ładowania szczegółów', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    }

    $(document).on('click', '.detail-vendor-btn', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        currentVendorId = id;
        $('#detail_vendor_name').text(name);
        $('#detailVendorModal').modal('show');
        getAjax('vendor-detail.php', { id: id })
            .done(function(r) {
                if(r.success) { renderDetail(r); }
                else { showAlert(r.error || 'Błąd ładowania', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ============================================================
    // Supplier CRUD (inside detail modal)
    // ============================================================

    $('#addSupplierBtn').on('click', function() {
        if(!currentVendorId) return;
        $('#supplier_id').val('');
        $('#supplier_vendor_id').val(currentVendorId);
        $('#supplier_name').val('');
        $('#supplier_job_title').val('');
        $('#supplier_phone').val('');
        $('#supplier_email').val('');
        $('#supplier_comment').val('');
        $('#supplierModalTitle').text('Dodaj osobę kontaktową');
        $('#supplierModal').modal('show');
    });

    $(document).on('click', '.edit-supplier-btn', function() {
        const id = parseInt($(this).data('id'), 10);
        const s = (currentDetailData.suppliers || []).find(function(x) { return x.id == id; });
        if(!s) { showAlert('Nie znaleziono danych osoby kontaktowej', 'warning'); return; }
        $('#supplier_id').val(s.id);
        $('#supplier_vendor_id').val(s.vendorId);
        $('#supplier_name').val(s.name || '');
        $('#supplier_job_title').val(s.jobTitle || '');
        $('#supplier_phone').val(s.phone || '');
        $('#supplier_email').val(s.email || '');
        $('#supplier_comment').val(s.comment || '');
        $('#supplierModalTitle').text('Edytuj osobę kontaktową');
        $('#supplierModal').modal('show');
    });

    $('#supplierForm').on('submit', function(e) {
        e.preventDefault();
        const id = $('#supplier_id').val();
        const data = {
            vendor_id: $('#supplier_vendor_id').val(),
            name:      $('#supplier_name').val().trim(),
            job_title: $('#supplier_job_title').val().trim(),
            phone:     $('#supplier_phone').val().trim(),
            email:     $('#supplier_email').val().trim(),
            comment:   $('#supplier_comment').val().trim(),
        };
        if(!data.name) { showAlert('Imię i nazwisko jest wymagane', 'warning'); return; }
        const endpoint = id ? 'supplier-update.php' : 'supplier-add.php';
        if(id) { data.id = id; }
        postAjax(endpoint, data)
            .done(function(r) {
                if(r.success) {
                    $('#supplierModal').modal('hide');
                    showAlert(r.message || 'Zapisano', 'success');
                    reloadDetail();
                } else { showAlert(r.error || 'Błąd zapisu', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $(document).on('click', '.toggle-supplier-btn', function() {
        const id = parseInt($(this).data('id'), 10);
        const isActive = $(this).data('is-active') == 1;
        const name = $(this).data('name');
        $('#toggle_supplier_id').val(id);
        $('#toggle_supplier_is_active').val(isActive ? '1' : '0');
        const verb = isActive ? 'wyłączyć' : 'włączyć';
        $('#toggle_supplier_body').html(
            'Czy na pewno chcesz <b>' + verb + '</b> osobę <b>' + esc(name) + '</b>?'
        );
        $('#toggleSupplierModal').modal('show');
    });

    $('#confirmToggleSupplier').on('click', function() {
        const id = parseInt($('#toggle_supplier_id').val(), 10);
        const isActive = $('#toggle_supplier_is_active').val() === '1';
        postAjax('supplier-toggle-active.php', { id: id, is_active: isActive ? 1 : 0 })
            .done(function(r) {
                if(r.success) {
                    $('#toggleSupplierModal').modal('hide');
                    showAlert(r.message || 'Status zmieniony', 'success');
                    reloadDetail();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ============================================================
    // VendorPart inside detail modal (add + toggle)
    // ============================================================

    function loadSearchable(selectId, hiddenId, endpoint, currentVal) {
        return searchAjax(endpoint, { q: '' })
            .done(function(r) {
                let opts = '<option value="">Wybierz...</option>';
                if(r && r.length) {
                    r.forEach(function(item) {
                        const sel = currentVal && item.id == currentVal ? ' selected' : '';
                        opts += '<option value="' + item.id + '"' + sel + '>' + esc(item.label || item.name) + '</option>';
                    });
                }
                $('#' + selectId).html(opts);
                if(currentVal) { $('#' + hiddenId).val(currentVal); }
                $('#' + selectId).selectpicker('refresh');
            });
    }

    $('#addVendorPartBtn').on('click', function() {
        if(!currentVendorId) return;
        $('#vp_vendor_id').val(currentVendorId);
        $('#vp_producer_id').val('');
        $('#vp_unit_id').val('');
        $('#vp_part_id').val('');
        $('#vp_vendor_part_no').val('');
        $('#vp_full_pack_quantity').val('1');
        $('#vp_comment').val('');
        $('#addVendorPartModal').modal('show');
        loadSearchable('vp_producer_select', 'vp_producer_id', 'vp-search-producers.php', null);
        loadSearchable('vp_unit_select', 'vp_unit_id', 'vp-search-units.php', null);
        loadSearchable('vp_part_select', 'vp_part_id', 'vp-search-parts.php', null);
    });

    function bindSelectPickerLiveSearch(selectId, hiddenId, endpoint) {
        $('#' + selectId).on('changed.bs.select', function() {
            $('#' + hiddenId).val($(this).val());
        });
        // Refresh results as user types in the live-search box.
        $(document).on('keyup', '.bs-searchbox input', function() {
            const $sel = $(this).closest('.bootstrap-select').find('select');
            const sid = $sel.attr('id');
            if(sid !== selectId) return;
            const q = $(this).val();
            searchAjax(endpoint, { q: q })
                .done(function(r) {
                    let opts = '<option value="">Wybierz...</option>';
                    if(r && r.length) {
                        r.forEach(function(item) {
                            opts += '<option value="' + item.id + '">' + esc(item.label || item.name) + '</option>';
                        });
                    }
                    $('#' + selectId).html(opts);
                    $('#' + selectId).selectpicker('refresh');
                });
        });
    }

    bindSelectPickerLiveSearch('vp_producer_select', 'vp_producer_id', 'vp-search-producers.php');
    bindSelectPickerLiveSearch('vp_unit_select',    'vp_unit_id',     'vp-search-units.php');
    bindSelectPickerLiveSearch('vp_part_select',    'vp_part_id',     'vp-search-parts.php');

    $('#addVendorPartForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            vendor_id:          $('#vp_vendor_id').val(),
            producer_id:        $('#vp_producer_id').val(),
            parts_id:           $('#vp_part_id').val(),
            vendor_part_no:     $('#vp_vendor_part_no').val().trim(),
            vendor_jm_id:       $('#vp_unit_id').val(),
            full_pack_quantity: $('#vp_full_pack_quantity').val() || '1',
            comment:            $('#vp_comment').val().trim(),
        };
        if(!data.producer_id || !data.parts_id || !data.vendor_jm_id) {
            showAlert('Producent, part i jednostka są wymagane', 'warning'); return;
        }
        if(!data.vendor_part_no) { showAlert('Vendor part no jest wymagany', 'warning'); return; }
        postAjax('vendor-part-add.php', data)
            .done(function(r) {
                if(r.success) {
                    $('#addVendorPartModal').modal('hide');
                    showAlert(r.message || 'Artykuł dodany', 'success');
                    reloadDetail();
                    location.reload(); // also refresh main list counts
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $(document).on('click', '.toggle-vp-btn', function() {
        const id = parseInt($(this).data('id'), 10);
        const isActive = $(this).data('is-active') == 1;
        $('#toggle_vp_id').val(id);
        $('#toggle_vp_is_active').val(isActive ? '1' : '0');
        const verb = isActive ? 'wyłączyć' : 'włączyć';
        $('#toggle_vp_body').html('Czy na pewno chcesz <b>' + verb + '</b> ten artykuł?');
        $('#toggleVendorPartModal').modal('show');
    });

    $('#confirmToggleVendorPart').on('click', function() {
        const id = parseInt($('#toggle_vp_id').val(), 10);
        const isActive = $('#toggle_vp_is_active').val() === '1';
        postAjax('vendor-part-toggle-active.php', { id: id, is_active: isActive ? 1 : 0 })
            .done(function(r) {
                if(r.success) {
                    $('#toggleVendorPartModal').modal('hide');
                    showAlert(r.message || 'Status zmieniony', 'success');
                    reloadDetail();
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });
});
