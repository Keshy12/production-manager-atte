<div class="modal fade" id="addAdminProfileModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Administrator</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Czy na pewno chcesz dodać użytkownika jako administratora?
            </div>
            <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
            <button type="button" id="addAdminProfileSubmit" class="btn btn-primary" >Tak</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addInactiveUserModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nieaktywny użytkownik</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Czy na pewno chcesz utworzyć nieaktywnego użytkownika? Użytkownik nie będzie mógł się zalogować do systemu do momentu jego aktywacji.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="addInactiveUserSubmit" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="changeAdminPasswordModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Potwierdź hasło administratora</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p id="changeAdminPasswordMessage">Zmiana uprawnień administratora wymaga potwierdzenia hasłem.</p>
                <div class="form-group mt-3">
                    <label for="changeAdminPasswordInput">Twoje hasło:</label>
                    <input type="password" id="changeAdminPasswordInput" class="form-control" autocomplete="current-password">
                    <small id="changeAdminPasswordError" class="text-danger" style="display: none;"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="changeAdminPasswordSubmit" class="btn btn-primary">Zmień uprawnienia</button>
            </div>
        </div>
    </div>
</div>
