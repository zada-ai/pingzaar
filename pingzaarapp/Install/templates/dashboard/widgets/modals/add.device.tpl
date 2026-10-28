<div class="modal-content">
    <div class="modal-header">
        <h3 class="modal-title">
            <i class="la la-android la-lg"></i> {$title}
        </h3>

        <button type="button" class="close" data-dismiss="modal">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    
    <div class="modal-body">
        <p class="text-justify">{__("form_adddevice_one")}</p>

        <h5 class="text-uppercase">{__("form_adddevice_two")}</h5>
        <p class="pl-3 text-justify">{__("form_adddevice_three")}</p>
        <p class="text-center">
            <a href="{site_url("uploads/builder/gateway.apk?_={time()}", true)}" class="btn btn-primary lift" target="_blank">
                <i class="la la-android la-lg text-success"></i> {__("btn_download")}<br>
            </a>

            <div class="row">
                <div class="col"><hr></div>
                <div class="col-auto">{__("form_adddevicescandl")}</div>
                <div class="col"><hr></div>
            </div>

            <div id="system-qrcode-download">
                <script>system.qrcode("{site_url("uploads/builder/gateway.apk?_={time()}", true)}", 150, 150, "system-qrcode-download");</script>
            </div>
        </p>

        <h5 class="text-uppercase">{__("form_adddevice_four")}</h5>
        <p class="pl-3 text-justify">
            {__("form_adddevice_five")}

            <div id="system-qrcode">
                {* Carries the site URL and a single-use pairing token, not the
                   account hash. The app is no longer built per install, so it
                   needs the URL; and a QR holding the account hash was a durable
                   credential that anyone could photograph and reuse. *}
                <script>system.qrcode("{$data.pairing|escape:'javascript'}", 220, 220);</script>
            </div>
        </p>

        <h5 class="text-uppercase">{__("form_adddevice_six")}</h5>
        <p class="pl-3 text-justify">{__("form_adddevice_seven")}</p>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-dismiss="modal">
            <i class="la la-check-circle la-lg"></i> {__("btn_done")}
        </button>
    </div>
</div>