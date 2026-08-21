<div class="modal fade" id="confirmDeleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Potwierdź</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Czy na pewno chcesz usunąć ten element?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nie</button>
                <button type="button" id="confirmDelete" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cloneBomModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Klonuj BOM</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Wybierz BOM źródłowy tego samego typu. Po zatwierdzeniu
                    zawartość bieżącego BOM-u zostanie zastąpiona skopiowanymi
                    pozycjami.
                </p>

                <div class="form-group">
                    <label for="cloneSourceDevice">Komponent źródłowy</label>
                    <select id="cloneSourceDevice" data-live-search="true"
                            data-width="100%" class="selectpicker">
                    </select>
                </div>

                <div class="form-group" id="cloneSourceLaminateGroup" style="display:none;">
                    <label for="cloneSourceLaminate">Laminat</label>
                    <select id="cloneSourceLaminate" data-live-search="true"
                            data-width="100%" class="selectpicker" disabled>
                    </select>
                </div>

                <div class="form-group" id="cloneSourceVersionGroup" style="display:none;">
                    <label for="cloneSourceVersion">Wersja</label>
                    <select id="cloneSourceVersion" data-width="100%"
                            class="selectpicker" disabled>
                    </select>
                </div>

                <input type="hidden" id="cloneSourceBomId" value="">

                <div id="cloneSourcePreview" style="display:none;" class="mt-3">
                    <div class="text-muted small mb-2">
                        <b>Ten BOM zawiera <span id="cloneSourceCount">0</span> pozycji</b>
                    </div>
                    <table class="table table-bordered table-sm text-center mb-0 small" style="max-width: 100%;">
                        <thead>
                            <tr>
                                <th style="width:70%" scope="col">Komponent</th>
                                <th style="width:30%" scope="col">Ilość</th>
                            </tr>
                        </thead>
                        <tbody id="cloneSourceTBody"></tbody>
                    </table>
                </div>

                <div id="cloneTargetWarning" style="display:none;" class="mt-3">
                    <!-- Warning shown when the target BOM already has rows -->
                </div>

                <div id="cloneModalAlert" class="mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="cloneBomConfirmBtn" class="btn btn-warning" disabled>
                    Klonuj (nadpisz)
                </button>
            </div>
        </div>
    </div>
</div>