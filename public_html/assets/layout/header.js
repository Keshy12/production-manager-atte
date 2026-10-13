
const COMPONENTS_PATH = "/atte_ms_new/public_html/components"
const ROOT_DIR = "/atte_ms_new"

$(document).ready(function(){
    $('.selectpicker[multiple]').selectpicker({
        countSelectedText: "Wybrano {0}",
        selectAllText: "Zaznacz wszystkie",
        deselectAllText: "Odznacz wszystkie"
    });
    $('.selectpicker').selectpicker();
    $('.dropdown-submenu a.sub-dropdown').on("click", function(e){
      $('.dropdown-submenu .dropdown-menu').hide();
      $(this).next('.dropdown-menu').toggle();
      e.stopPropagation();
      e.preventDefault();
    });
    $('.dropdown-button').on('hide.bs.dropdown', function () {
      $('.dropdown-submenu .dropdown-menu').hide();
    })
    //Auto close popup on mouseup anywhere on the page.
    $("html").on("mouseup", function (e) {
        var l = $(e.target);
        if (l[0] instanceof SVGElement) return;
        if (l[0] instanceof Image) return;
        if (l[0].className.indexOf("popover") == -1) {
            $(".popover").each(function () {
                $(this).popover("hide");
            });
        }
    });
});

// Global setAlert — Bootstrap-styled alert host writer. Pages that show
// feedback (success/info/warning/danger) include a `<div id="alertContainer">`
// host; this helper writes a dismissible BS4 alert into it. When called
// with an empty message, the host is cleared instead.
window.setAlert = function (message, kind) {
    const $box = $('#alertContainer');
    if ($box.length === 0) { console.warn('setAlert: #alertContainer not found'); return; }
    if (!message) { $box.empty(); return; }
    $box.html(
        '<div class="alert alert-' + (kind || 'info') + ' alert-dismissible fade show" role="alert">' +
        message +
        '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>' +
        '</div>'
    );
};

$("#logout").click(function(e){
    e.preventDefault();
    $('#logoutModal').modal('show');
});
  
// disable mousewheel on a input number field when in focus
document.addEventListener("wheel", function(event){
    if(document.activeElement.type === "number"){
      document.activeElement.blur();
    }
});