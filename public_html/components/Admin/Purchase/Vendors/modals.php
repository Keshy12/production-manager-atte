<!-- Edit Vendor Modal -->
<div class="modal fade" id="editVendorModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="editVendorForm">
                <input type="hidden" id="edit_vendor_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj dostawcę</h5>
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label for="edit_vendor_name">Nazwa:</label>
                                <input type="text" class="form-control" id="edit_vendor_name" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="edit_vendor_lead_time">Lead time (dni):</label>
                                <input type="number" min="0" class="form-control" id="edit_vendor_lead_time">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_vendor_address">Adres:</label>
                        <input type="text" class="form-control" id="edit_vendor_address">
                    </div>
                    <div class="form-group">
                        <label for="edit_vendor_additional_data">Dodatkowe dane:</label>
                        <input type="text" class="form-control" id="edit_vendor_additional_data">
                    </div>
                    <div class="form-group">
                        <label for="edit_vendor_comment">Komentarz:</label>
                        <textarea class="form-control" id="edit_vendor_comment" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Anuluj
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Zapisz zmiany
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Vendor Confirm Modal -->
<div class="modal fade" id="toggleVendorModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="toggle_vendor_id">
            <input type="hidden" id="toggle_vendor_is_active">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="toggle_vendor_body">
                Czy na pewno chcesz zmienić status tego dostawcy?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmToggleVendor" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>

<!-- Detail Vendor Modal -->
<div class="modal fade" id="detailVendorModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Szczegóły dostawcy: <span id="detail_vendor_name"></span></h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="detailTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-suppliers" data-toggle="tab" href="#pane-suppliers" role="tab">
                            Osoby kontaktowe <span class="badge badge-secondary" id="detail_supplier_count">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-vendor-parts" data-toggle="tab" href="#pane-vendor-parts" role="tab">
                            Artykuły <span class="badge badge-secondary" id="detail_vendor_part_count">0</span>
                        </a>
                    </li>
                </ul>

                <div class="tab-content mt-3">
                    <div class="tab-pane fade show active" id="pane-suppliers" role="tabpanel">
                        <button type="button" class="btn btn-sm btn-primary mb-2" id="addSupplierBtn">
                            <i class="bi bi-plus-circle"></i> Dodaj osobę kontaktową
                        </button>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <th>Nazwa</th>
                                    <th>Stanowisko</th>
                                    <th>Telefon</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody id="suppliersTBody"></tbody>
                            </table>
                        </div>
                        <div id="suppliers_empty" class="alert alert-info" style="display:none;">
                            Brak osób kontaktowych dla tego dostawcy.
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-vendor-parts" role="tabpanel">
                        <button type="button" class="btn btn-sm btn-primary mb-2" id="addVendorPartBtn">
                            <i class="bi bi-plus-circle"></i> Dodaj artykuł
                        </button>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <th>Producent</th>
                                    <th>PartNo</th>
                                    <th>JM</th>
                                    <th>Full&nbsp;Pack</th>
                                    <th>Status</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody id="vendorPartsTBody"></tbody>
                            </table>
                        </div>
                        <div id="vendor_parts_empty" class="alert alert-info" style="display:none;">
                            Brak artykułów dla tego dostawcy.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Supplier Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="supplierForm">
                <input type="hidden" id="supplier_id">
                <input type="hidden" id="supplier_vendor_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="supplierModalTitle">Dodaj osobę kontaktową</h5>
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="supplier_name">Imię i nazwisko:</label>
                        <input type="text" class="form-control" id="supplier_name" required>
                    </div>
                    <div class="form-group">
                        <label for="supplier_job_title">Stanowisko:</label>
                        <input type="text" class="form-control" id="supplier_job_title">
                    </div>
                    <div class="form-group">
                        <label for="supplier_phone">Telefon:</label>
                        <input type="text" class="form-control" id="supplier_phone">
                    </div>
                    <div class="form-group">
                        <label for="supplier_email">Email:</label>
                        <input type="email" class="form-control" id="supplier_email">
                    </div>
                    <div class="form-group">
                        <label for="supplier_comment">Komentarz:</label>
                        <textarea class="form-control" id="supplier_comment" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Zapisz</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Supplier Confirm Modal -->
<div class="modal fade" id="toggleSupplierModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="toggle_supplier_id">
            <input type="hidden" id="toggle_supplier_is_active">
            <div class="modal-header">
                <h5 class="modal-title">Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="toggle_supplier_body">Zmienić status tej osoby kontaktowej?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmToggleSupplier" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Vendor Part Modal -->
<div class="modal fade" id="addVendorPartModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="addVendorPartForm">
                <input type="hidden" id="vp_vendor_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Dodaj artykuł u dostawcy</h5>
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Producent:</label>
                                <select id="vp_producer_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                    <option value="">Wybierz producenta...</option>
                                </select>
                                <input type="hidden" id="vp_producer_id" name="producer_id">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>JM:</label>
                                <select id="vp_unit_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                    <option value="">Wybierz jednostkę...</option>
                                </select>
                                <input type="hidden" id="vp_unit_id" name="vendor_jm_id">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Part (nasz katalog):</label>
                        <select id="vp_part_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                            <option value="">Wybierz part...</option>
                        </select>
                        <input type="hidden" id="vp_part_id" name="parts_id">
                    </div>
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group">
                                <label for="vp_vendor_part_no">Numer części u dostawcy:</label>
                                <input type="text" class="form-control" id="vp_vendor_part_no" required>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <label for="vp_full_pack_quantity">Pełne opakowanie:</label>
                                <input type="number" min="0.0001" step="0.0001" class="form-control" id="vp_full_pack_quantity" value="1">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="vp_comment">Komentarz:</label>
                        <textarea class="form-control" id="vp_comment" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Zapisz</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Vendor Part Confirm Modal -->
<div class="modal fade" id="toggleVendorPartModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="toggle_vp_id">
            <input type="hidden" id="toggle_vp_is_active">
            <div class="modal-header">
                <h5 class="modal-title">Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="toggle_vp_body">Zmienić status tego artykułu?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmToggleVendorPart" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>
