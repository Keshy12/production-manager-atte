<div class="container">
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-4 offset-md-4">
                <div class="card text-center card bg-default mb-3">
                    <div class="card-header">
                        LOGOWANIE
                    </div>
                    <form action="public_html/components/login/login.php" method="POST">
                    <div class="card-body">
                        <input type="text" id="userName" name="userName" class="form-control input-sm chat-input" placeholder="Nazwa Użytkownika" required/>
                        </br>
                        <input type="password" id="userPassword" name="userPassword" class="form-control input-sm chat-input" placeholder="Hasło" required/>
                    </div>
                    <div class="card-footer text-muted">
                        <input type="submit" value="ZALOGUJ" class="btn btn-secondary" />
                    </div>
                    </form>
                </div>
                <?php if(isset($_SESSION['info'])) : ?>
                        <div class="text-danger text-center">
                            <br><?= $_SESSION['info']?>
                        </div> 
                        <?php unset($_SESSION['info']) ?>
                <?php endif; ?> 
            </div>
        </div>
    </div>
</div>