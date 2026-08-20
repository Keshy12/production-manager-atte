<!-- Edit VendorPart Modal -->
<div class="modal fade" id="editVpModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="editVpForm">
                <input type="hidden" id="edit_vp_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj artykuł u dostawcy</h5>
                    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dostawca:</label>
                                <select id="edit_vendor_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                    <option value="">Wybierz dostawcę...</option>
                                </select>
                                <input type="hidden" id="edit_vp_vendor_id" name="vendor_id">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Producent:</label>
                                <select id="edit_producer_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                    <option value="">Wybierz producenta...</option>
                                </select>
                                <input type="hidden" id="edit_vp_producer_id" name="producer_id">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Part (nasz katalog):</label>
                                <select id="edit_part_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                    <option value="">Wybierz part...</option>
                                </select>
                                <input type="hidden" id="edit_vp_part_id" name="parts_id">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>JM:</label>
                                <select id="edit_unit_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                    <option value="">Wybierz jednostkę...</option>
                                </select>
                                <input type="hidden" id="edit_vp_unit_id" name="vendor_jm_id">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group">
                                <label for="edit_vp_vendor_part_no">Numer części u dostawcy:</label>
                                <input type="text" class="form-control" id="edit_vp_vendor_part_no" required>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <label for="edit_vp_full_pack_quantity">Pełne opakowanie:</label>
                                <input type="number" min="0.0001" step="0.0001" class="form-control" id="edit_vp_full_pack_quantity" value="1">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_vp_comment">Komentarz:</label>
                        <textarea class="form-control" id="edit_vp_comment" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle VendorPart Confirm Modal -->
<div class="modal fade" id="toggleVpModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <input type="hidden" id="toggle_vp_id">
            <input type="hidden" id="toggle_vp_is_active">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="toggle_vp_body">Zmienić status tego artykułu?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmToggleVp" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>
