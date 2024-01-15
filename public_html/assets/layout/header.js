
function getFlowpinDate() {
    $.ajax({
        type: "POST",
        url: "/atte_ms/get-flowpin-date.php",
        data: {},
        success: function(data) {
          let result = JSON.parse(data);
          $("#flowpinDate").html(result.flowpin);
          $("#GSWarehouseDate").html(result.gs);
        }
    });
  }

  function getNotifications() {
    $.ajax({
        type: "POST",
        url: "/atte_ms/get-unresolved-notifications.php",
        data: {},
        success: function(data) {
          let result = JSON.parse(data);
          $("#notificationCounter").html(result.count);
          $("#notificationDropdown").html(result.dropdown.join(""));
          // $("#showNotifications").html(data);
        }
    });
  }

  $(document).ready(function(){
    $('.selectpicker').selectpicker();
    $('.dropdown-submenu a.test').on("click", function(e){
      $('.dropdown-submenu .dropdown-menu').hide();
      $(this).next('.dropdown-menu').toggle();
      e.stopPropagation();
      e.preventDefault();
    });
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
    getNotifications();
    getFlowpinDate();
  });

  $("#toggleFlowpinUpdate").click(function(){
    $(this).html() == "Ukryj" ? $(this).html('Pokaż') : $(this).html('Ukryj');
    $("#flowpinUpdate").toggle();
  });

  $("#updateDataFromFlowpin").click(function(){
    $("#spinnerflowpin").show();
    $.ajax({
        type: "POST",
        url: "/atte_ms/flowpin-sku-update.php",
        data: {},
        success: function(data) {
          if(data.length) alert(data);
          $("#spinnerflowpin").hide();
          getFlowpinDate();
        }
    });
  })

  $("#logout").click(function(e){
    e.preventDefault();
    $('#logoutModal').modal('show');
  });