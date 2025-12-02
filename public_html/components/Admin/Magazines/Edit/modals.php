<!-- Edit Magazine Modal -->
<div class="modal fade" id="editMagazineModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="editMagazineForm">
                <input type="hidden" id="edit_magazine_id">
                <input type="hidden" id="edit_type_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil"></i> Edytuj Magazyn
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_sub_magazine_name">Nazwa Magazynu:</label>
                        <input type="text" class="form-control" id="edit_sub_magazine_name" required>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-12">
                            <h6>Przypisani Użytkownicy</h6>
                            <div id="current_users_list" class="mb-3">
                            </div>

                            <div class="form-group">
                                <label for="assign_user_select">Przypisz użytkownika:</label>
                                <div class="input-group">
                                    <select class="form-control selectpicker" id="assign_user_select" data-live-search="true">
                                        <option value="">Wybierz użytkownika...</option>
                                        <?php foreach ($all_users as $user): ?>
                                            <option value="<?= $user->userId ?>"
                                                    data-current-magazine="<?= $user->subMagazineId ?>"
                                                <?php if ($user->subMagazineId): ?>
                                                    data-content="<?= htmlspecialchars($user->name . ' ' . $user->surname) ?> (<?= htmlspecialchars($user->email) ?>) <span class='badge badge-secondary ml-2'><?= $user->subMagazineId ?></span>"
                                                <?php else: ?>
                                                    data-content="<?= htmlspecialchars($user->name . ' ' . $user->surname) ?> (<?= htmlspecialchars($user->email) ?>)"
                                                <?php endif; ?>>
                                                <?= htmlspecialchars($user->name . ' ' . $user->surname) ?>
                                                (<?= htmlspecialchars($user->email) ?>)
                                                <?php if ($user->subMagazineId): ?>
                                                    - Aktualnie w magazynie ID: <?= $user->subMagazineId ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="input-group-append">
                                        <button type="button" id="clearUserSelect" class="close mx-1" aria-label="Wyczyść wybór">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-success" id="assign_user_btn">
                                <i class="bi bi-person-plus"></i> Przypisz Użytkownika
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Anuluj
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Zapisz Zmiany
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="toggleMagazineUserModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="disableMagazineUserForm">
                <input type="hidden" id="disable_magazine_id">
                <input type="hidden" id="disable_magazine_action">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle"></i> Wyłącz Magazyn z Przypisanymi Zasobami
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Users Section -->
                    <div class="mb-4 user-section">
                        <h6><i class="bi bi-people"></i> Przypisani Użytkownicy</h6>
                        <div id="assigned_users_display" class="mb-3 p-2 border rounded bg-light">
                            <!-- Users will be loaded here -->
                        </div>

                        <div class="form-group">
                            <label><strong>Co zrobić z przypisanymi użytkownikami?</strong></label>

                            <div id="user_action_controls">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="radio" name="user_assignment_action"
                                           id="keep_assigned" value="keep" checked>
                                    <label class="form-check-label" for="keep_assigned">
                                        <strong>Pozostaw</strong> przypisanych
                                        <small class="text-muted d-block">Użytkownicy pozostaną przypisani do nieaktywnego magazynu</small>
                                    </label>
                                </div>

                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="radio" name="user_assignment_action"
                                           id="unassign_from_disabled" value="unassign">
                                    <label class="form-check-label" for="unassign_from_disabled">
                                        <strong>Odłącz</strong> użytkowników
                                        <small class="text-muted d-block">Użytkownicy zostaną odłączeni i będą mogli być przypisani do innych magazynów</small>
                                    </label>
                                </div>

                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="radio" name="user_assignment_action"
                                           id="disable_users" value="disable">
                                    <label class="form-check-label" for="disable_users">
                                        <strong>Wyłącz</strong> użytkowników
                                        <small class="text-muted d-block">Użytkownicy zostaną wyłączeni wraz z magazynem</small>
                                    </label>
                                </div>
                            </div>

                            <!-- Warning for disable users option -->
                            <div id="disable_users_warning" class="alert alert-warning mt-2" style="display: none;">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Uwaga!</strong> Wyłączenie użytkowników spowoduje, że nie będą mogli się logować do systemu.
                                Ta operacja może być cofnięta tylko przez administratora.
                            </div>
                        </div>
                    </div>

                    <hr class="user-section">

                    <!-- Inventory Section -->
                    <div class="mb-4 inventory-section">
                        <h6><i class="bi bi-box-seam"></i> Inwentarz w Magazynie</h6>
                        <div id="magazine_inventory_display" class="mb-3 p-2 border rounded bg-light">
                            <div class="text-center">
                                <i class="bi bi-hourglass-split"></i> Ładowanie inwentarza...
                            </div>
                        </div>

                        <div class="form-group">
                            <label><strong>Co zrobić z inwentarzem?</strong></label>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="inventory_action"
                                       id="inventory_nothing" value="nothing" checked>
                                <label class="form-check-label" for="inventory_nothing">
                                    <strong>Nie rób</strong> nic
                                    <small class="text-muted d-block">Inwentarz pozostanie w nieaktywnym magazynie</small>
                                </label>
                            </div>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="inventory_action"
                                       id="inventory_transfer" value="transfer">
                                <label class="form-check-label" for="inventory_transfer">
                                    <strong>Przenieś</strong> do innego magazynu
                                    <small class="text-muted d-block">Wszystkie produkty zostaną przeniesione do wybranego magazynu</small>
                                </label>
                            </div>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="inventory_action"
                                       id="inventory_clear" value="clear">
                                <span class="form-check-label">
                                    <label for="inventory_clear" class="mb-0"><strong>Wyczyść</strong></label> inwentarz
                                    <small class="text-muted d-block">Wszystkie produkty zostaną usunięte z magazynu</small>
                                </span>
                            </div>

                            <!-- Warning for clear inventory option -->
                            <div id="clear_inventory_warning" class="alert alert-danger mt-2" style="display: none;">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Uwaga!</strong> Wyczyszczenie inwentarza jest operacją nieodwracalną.
                                Wszystkie produkty w magazynie zostaną trwale usunięte z systemu.
                            </div>

                            <!-- Target Magazine Selection (shown only when transfer is selected) -->
                            <div id="target_magazine_selection" class="mt-3" style="display: none;">
                                <label for="target_magazine_select"><strong>Wybierz magazyn docelowy:</strong></label>
                                <select class="form-control" id="target_magazine_select">
                                    <option value="">Ładowanie magazynów...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Anuluj
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-check-circle"></i> Wyłącz Magazyn
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Magazine Inventory Grouping Styles */
.magazine-inventory-table .group-row {
    cursor: pointer;
    font-weight: bold;
    background-color: #f8f9fa !important;
}

.magazine-inventory-table .group-row:hover {
    background-color: #e9ecef !important;
}

.magazine-inventory-table .group-row .toggle-icon {
    transition: transform 0.2s ease;
    display: inline-block;
    font-size: 14px;
    margin-right: 8px;
}

.magazine-inventory-table .group-row[aria-expanded="true"] .toggle-icon {
    transform: rotate(90deg);
}

.magazine-inventory-table .indent-cell {
    padding-left: 30px !important;
}

.magazine-inventory-table .badge-count {
    font-size: 0.85em;
    vertical-align: middle;
}
</style>