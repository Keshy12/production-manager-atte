<script type="text/template" data-template="deviceProduced">
    <div class='${deviceType}-${deviceId} mt-3'>
        <b class='name'>${deviceName}</b>
        <button type='button' data-type='${deviceType}' data-id='${deviceId}' class='close removeDevice' aria-label='Close'>
            <span aria-hidden='true'>×</span>
        </button>
        <br>
        <small class='description'>${deviceDescription}</small>
        <br>
    </div>
</script>