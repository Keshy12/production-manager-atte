<script type="text/template" data-template="verifyCard">
    <div class="card my-4 mx-4">
        <div style="width: 18rem; background-color: ${backgroundColor}" class="card-header alert-secondary text-center">${inputType}</div>
        <div class="card alert-secondary h-100" style="width: 18rem;">
            <div class="card-body">
                <h6 class="user card-subtitle mb-2 text-center text-muted">${user}</h6>
                <h5 class="deviceName card-title text-center">${deviceName}</h5>
                <p class="deviceDescription card-text text-muted">${deviceDescription}</p>
                <hr>
                <p class="card-title text-muted">Komentarz:</p>
                <textarea class="comment form-control" cols="30" rows="4">${comment}</textarea>
                <p class="card-title text-muted mt-2">Ilość: 
                    <label style="font-size: 0.75rem"><input style="width: 0.75rem; height: 0.75rem" type="checkbox" class="correct ml-1">
                    Korekta?</label>
                </p>
                <input type="number" class="form-control quantity" value="${quantity}" id="" readonly="">
                <div class="text-center">
                    <button value="${id}" 
                    data-deviceId="${deviceId}" 
                    data-commissionId="${commissionId}" 
                    data-deviceType="${deviceType}" 
                    class="btn btn-primary mt-3 verificationSubmit">Potwierdź</button>
                </div>
            </div>
        </div>
        <div class="timestamp card-footer alert-secondary text-center">${timestamp}</div>
    </div>
</script>

<div style="max-width: 25rem" class="justify-content-center d-flex flex-wrap mx-auto mt-4">
    <select class="selectpicker form-control" id="verifyType">
        <option value="">Wszystko</option>
        <option value="sku">Weryfikuj SKU</option>
        <option value="tht">Weryfikuj THT</option>
        <option value="smd">Weryfikuj SMD</option> 
        <option value="parts">Weryfikuj Parts</option>
    </select>
</div>

<div id="container" class="mx-auto w-100">

</div>

<script src="http://<?=BASEURL?>/public_html/components/verification/verification-view.js"></script>
