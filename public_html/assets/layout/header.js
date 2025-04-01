
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