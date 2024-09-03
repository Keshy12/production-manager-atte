const ref__valuepackage_template = $('script[data-template="ref__valuepackage_template"]').text().split(/\$\{(.+?)\}/g);
const ref__package_exclude_template = $('script[data-template="ref__package_exclude_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

$("#searchDictionaryForm").submit(function(e) {
    e.preventDefault();
    $form = $(this);

    let searchValue = $("#searchDictionaryInput").val();
    $form.attr('data-search', searchValue);
    generateDictionaryView();
});

$('body').on('click', '.createNewRow', function() { 
    let $row = $(this).parent().parent();
    
    let dictionaryType = $("#dictionarySelect").val();
    let dictionaryItemInput = $row.find(".dictionaryItemInput").val();
    const newRow = [
        dictionaryItemInput
    ]

    if(dictionaryType == 'ref__valuepackage') {
        let componentTypeSelect = $row.find("select.componentTypeSelect").val();
        let componentDeviceSelect = $row.find("select.componentDeviceSelect").val();
        newRow.push(componentTypeSelect, componentDeviceSelect);
    }

    const data = {dictionaryType: dictionaryType, newRowValues: newRow};
    addDictionaryRow(data);
    generateDictionaryView();
});

function addDictionaryRow(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/dictionary/add-row.php",
        async: false,
        data: data,
        success: function (data) {
            let wasSuccessful = JSON.parse(data);
            let resultMessage = wasSuccessful ? 
                        "Edytowanie danych powiodło się." : 
                        "Coś poszło nie tak, dane nie zostały edytowane";
            let resultAlertType = wasSuccessful ? 
                        "alert-success" : 
                        "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

$("#createNewDictionaryRow").click(function() {
    $("#currentpage").text(1);
    generateDictionaryView();
    let dictionaryType = $("#dictionarySelect").val();
    let $TBody = $("#dictionaryTBody");
    let $newRow = $TBody.find('tr').first().clone();
    $newRow.find('.dictionaryValue').html('');
    $newRow.find('.componentInfo').html('');
    $TBody.prepend($newRow);
    
    generateDictionaryTextInput($newRow);

    generateComponentSelect($newRow, '' , '');

    generateSaveCancelButtons($newRow, '');
    $(".editPackageExclude, .editValuePackage, .removeDictionaryItem").prop('disabled', true);
});

$("#confirmDelete").click(function() {
    let dictionaryType = $("#dictionarySelect").val();
    let rowId = $(this).attr('data-id');
    const data = {dictionaryType: dictionaryType, rowId: rowId};
    removeDictionaryRow(data);
    $("#confirmDeleteModal").modal('hide');
    generateDictionaryView();
});

$('body').on('click', '.removeDictionaryItem', function() {
    let rowId = $(this).attr('data-id');
    $("#confirmDelete").attr('data-id', rowId);
    $("#confirmDeleteModal").modal('show');
});

function removeDictionaryRow(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/dictionary/remove-row.php",
        async: false,
        data: data,
        success: function (data) {
            let wasSuccessful = JSON.parse(data);
            let resultMessage = wasSuccessful ? 
                        "Usunięcie danych powiodło się." : 
                        "Coś poszło nie tak, dane nie zostały usunięte";
            let resultAlertType = wasSuccessful ? 
                        "alert-success" : 
                        "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

$('body').on('change', 'select.componentTypeSelect', function(){
    let componentType = this.value;
    let $componentDeviceSelect = $(this).parent().parent().find('select.componentDeviceSelect');
    $componentDeviceSelect.val('');
    $componentDeviceSelect.empty();
    $('#list__'+componentType+'_hidden option').clone().appendTo($componentDeviceSelect);
    $componentDeviceSelect.selectpicker('refresh');
});


$('body').on('click', '.editPackageExclude', function() {
    $(".editPackageExclude, .removeDictionaryItem").prop('disabled', true);
    let $row = $(this).parent().parent();
    let rowId = $(this).attr('data-id');

    generateDictionaryTextInput($row);
    generateSaveCancelButtons($row, rowId);
});

$('body').on('click', '.applyChanges', function(){
    let $row = $(this).parent().parent();
    let rowId = $(this).attr('data-id');

    let dictionaryType = $("#dictionarySelect").val();
    
    let dictionaryItemInput = $row.find(".dictionaryItemInput").val();

    const newRow = [
        dictionaryItemInput
    ]

    if(dictionaryType == 'ref__valuepackage') {
        let componentTypeSelect = $row.find("select.componentTypeSelect").val();
        let componentDeviceSelect = $row.find("select.componentDeviceSelect").val();
        newRow.push(componentTypeSelect, componentDeviceSelect);
    }
    const data = {dictionaryType: dictionaryType, rowId: rowId, newRowValues: newRow};
    editDictionaryRow(data);
    generateDictionaryView();
});

function editDictionaryRow(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/dictionary/edit-row.php",
        async: false,
        data: data,
        success: function (data) {
            let wasSuccessful = JSON.parse(data);
            let resultMessage = wasSuccessful ? 
                        "Edytowanie danych powiodło się." : 
                        "Coś poszło nie tak, dane nie zostały edytowane";
            let resultAlertType = wasSuccessful ? 
                        "alert-success" : 
                        "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

$('body').on('click', '.declineChanges', generateDictionaryView);

$('body').on('click', '.editValuePackage', function() {
    $(".editValuePackage, .removeDictionaryItem").prop('disabled', true);
    let componentType = $(this).attr('data-component-type');
    let componentId = $(this).attr('data-component-id');
    let rowId = $(this).attr('data-id');

    let $row = $(this).parent().parent();

    generateDictionaryTextInput($row);

    generateComponentSelect($row, componentType, componentId);

    generateSaveCancelButtons($row, rowId);
});

function generateSaveCancelButtons($row, rowId)
{
    let $editButtons = $row.find(".editButtons");
    $editButtons.empty();

    const acceptClass = rowId == '' ? 'createNewRow' : 'applyChanges';

    let $applyChangesButton = $(`<button class="btn mr-1 btn-outline-success `+acceptClass+`">
            <i class="bi bi-check-lg"></i>
        </button>`);
    let $declineChangesButton = $(`<button class="btn btn-outline-danger declineChanges">
        <i class="bi bi-x"></i>
    </button>`);

    $applyChangesButton.attr("data-id", rowId);
    $editButtons.append($applyChangesButton).append($declineChangesButton);    
}

function generateComponentSelect($row, componentType, componentId)
{
    let $componentInfo = $row.find('.componentInfo');
    $componentInfo.empty();

    let $componentInfoTypeSelect = $(`<select data-width="20%" class="selectpicker componentTypeSelect">
        <option value="tht">THT</option>
        <option value="parts">Parts</option>
    </select>`).val(componentType);

    let $componentInfoDeviceSelect = $(`<select data-title="Wybierz urządzenie..." data-live-search="true" data-width="80%" class="selectpicker componentDeviceSelect">
        </select>`);

    $('#list__'+componentType+'_hidden option').clone().appendTo($componentInfoDeviceSelect);
    $componentInfo.append($componentInfoTypeSelect).append($componentInfoDeviceSelect);
    $('.selectpicker').selectpicker('refresh');
    $componentInfoDeviceSelect.selectpicker('val', componentId);
}

function generateDictionaryTextInput($row)
{
    let $valuePackage = $row.find('.dictionaryValue');
    let valuePackage = $valuePackage.text();
    $valuePackage.empty();

    let $valuePackageInput = $('<input>', {
        type: 'text',
        class: 'dictionaryItemInput form-control text-center',
        value: valuePackage
    });

    $valuePackage.html($valuePackageInput);
}

$("#nextpage").click(function(){
    let page = parseInt($("#currentpage").text());
    page++;
    $("#currentpage").text(page);
    generateDictionaryView();
})

$("#previouspage").click(function(){
    let page = parseInt($("#currentpage").text());
    page--;
    $("#currentpage").text(page);
    generateDictionaryView();
});

$("#dictionarySelect").change(function() {
    $("#currentpage").text(1);
    $("#tableContainer").css('visibility', 'visible');
    generateDictionaryView();
});

function enablePaginationButtons(page, nextPageAvailable)
{
    $("#previouspage").prop('disabled', (page==1));
    $("#nextpage").prop('disabled', !nextPageAvailable);
}

function generateDictionaryView()
{
    let searchValue = $("#searchDictionaryForm").attr('data-search');
    $("#previouspage, #nextpage").prop('disabled', true);
    let dictionaryType = $("#dictionarySelect").val();
    let page = parseInt($("#currentpage").text());
    const dictionaryData = getDictionaryInfo(dictionaryType, page, searchValue);
    let tableData = dictionaryData[0];
    let nextPageAvailable = dictionaryData[1];
    generateTableRows(tableData, dictionaryType);
    enablePaginationButtons(page, nextPageAvailable);
}

function getDictionaryInfo(dictionaryType, page, searchValue)
{
    let result = null;
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/dictionary/get-dictionary-values.php",
        async: false,
        data: { dictionaryType: dictionaryType, page: page, searchValue: searchValue},
        success: function (data) {
            result = JSON.parse(data);
        }
    });
    return result;
}

function generateTableRows(tableData, dictionaryType)
{
    let $TBody = $("#dictionaryTBody");
    $TBody.empty();
    let template = ref__valuepackage_template;
    $("#componentCol").show();
    $("#valuePackageCol").text("Value Package");
    if(dictionaryType == 'ref__package_exclude') {
        $("#componentCol").hide();
        $("#valuePackageCol").text("Package");
        template = ref__package_exclude_template;
    }
    for(const [key, item] of Object.entries(tableData))
    {
        let renderedItem = template.map(render(item)).join('');
        $TBody.append(renderedItem);
    }
}