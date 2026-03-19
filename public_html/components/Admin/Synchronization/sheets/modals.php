<!-- Import Confirmation Modal -->
<div class="modal fade" id="importConfirmationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle"></i> Potwierdź import zamówień
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Warning Alert -->
                <div class="alert alert-warning">
                    <h6 class="mb-2"><i class="bi bi-info-circle"></i> <strong>Uwaga!</strong></h6>
                    <p class="mb-2">Zamierzasz zaimportować <strong>WSZYSTKIE</strong> zamówienia z aktualnego widoku Google Sheets, nie tylko te widoczne na bieżącej stronie.</p>
                    <p class="mb-0">Ta operacja:</p>
                    <ul class="mb-0">
                        <li>Zaimportuje <strong id="modalTotalCount">0</strong> zamówień</li>
                        <li>Utworzy grupy transferów według PO ID</li>
                        <li>Zaktualizuje stan magazynu</li>
                        <li><strong>Nie może być cofnięta</strong> (ale może być anulowana przez anulowanie grupy transferów)</li>
                    </ul>
                </div>

                <!-- Import Summary -->
                <div class="card border-info mb-3">
                    <div class="card-header bg-info text-white py-2">
                        <i class="bi bi-clipboard-data"></i> Podsumowanie importu
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4 class="text-primary" id="modalTotalOrders">0</h4>
                                    <small class="text-muted">Łącznie zamówień</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4 class="text-success" id="modalUniqueParts">0</h4>
                                    <small class="text-muted">Unikalnych części</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4 class="text-info" id="modalUniquePOs">0</h4>
                                    <small class="text-muted">Grup PO</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Missing Parts Warning (if applicable) -->
                <div id="modalMissingPartsWarning" class="alert alert-danger" style="display: none;">
                    <h6><i class="bi bi-exclamation-triangle"></i> Brakujące części</h6>
                    <p class="mb-1">Następujące części nie istnieją w bazie danych:</p>
                    <p class="mb-0"><strong id="modalMissingPartsList"></strong></p>
                </div>

                <!-- Filtered View Warning -->
                <div id="modalFilterWarning" class="alert alert-info" style="display: none;">
                    <i class="bi bi-funnel"></i>
                    <strong>Uwaga:</strong> Aktywne są filtry. Import zostanie zablokowany jeśli nie wszystkie zamówienia spełniają kryteria.
                </div>

                <!-- Confirmation Checkbox -->
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="confirmImportCheckbox">
                    <label class="custom-control-label" for="confirmImportCheckbox">
                        <strong>Rozumiem i chcę kontynuować import</strong>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="bi bi-x"></i> Anuluj
                </button>
                <button type="button" id="confirmImportBtn" class="btn btn-primary" disabled>
                    <i class="bi bi-check-circle"></i> Potwierdź i importuj
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Import Success Summary Modal -->
<div class="modal fade" id="importSuccessModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle-fill"></i> Import zakończony pomyślnie
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Success Summary -->
                <div class="alert alert-success">
                    <h6 class="mb-2"><i class="bi bi-check-circle"></i> <strong>Import ukończony!</strong></h6>
                    <p class="mb-0">
                        Zaimportowano <strong id="successTotalOrders">0</strong> zamówień
                        (<strong id="successUniqueParts">0</strong> unikalnych części)
                        w <strong id="successTransferGroups">0</strong> grupach transferów.
                    </p>
                </div>

                <!-- Transfer Groups Summary with Collapsible PO Details -->
                <div class="card border-primary mb-3">
                    <div class="card-header bg-primary text-white py-2" style="cursor: pointer;"
                         data-toggle="collapse" data-target="#transferGroupsSection"
                         aria-expanded="false" aria-controls="transferGroupsSection">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-chevron-right" id="icon-transferGroupsSection"></i>
                                <i class="bi bi-collection"></i> Utworzone grupy transferów
                            </div>
                        </div>
                    </div>
                    <div id="transferGroupsSection" class="collapse">
                        <div class="card-body p-2">
                            <div id="transferGroupsAccordion">
                                <!-- Will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Components Summary (Aggregated) -->
                <div class="card border-info">
                    <div class="card-header bg-info text-white py-2" style="cursor: pointer;"
                         data-toggle="collapse" data-target="#componentsSummarySection"
                         aria-expanded="false" aria-controls="componentsSummarySection">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-chevron-right" id="icon-componentsSummarySection"></i>
                                <i class="bi bi-box-seam"></i> Podsumowanie zaimportowanych komponentów
                            </div>
                        </div>
                    </div>
                    <div id="componentsSummarySection" class="collapse">
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0" id="componentsSummaryTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 60%;">Nazwa części</th>
                                        <th style="width: 25%;" class="text-right">Łączna ilość</th>
                                        <th style="width: 15%;">JM</th>
                                    </tr>
                                </thead>
                                <tbody id="componentsSummaryBody">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" data-dismiss="modal">
                    <i class="bi bi-check"></i> Zamknij
                </button>
            </div>
        </div>
    </div>
</div>
