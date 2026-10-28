<form system-form>
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
            <div class="form-row">
                <div class="form-group col-md-12">
                    <label>
                        {__("admin_gateway_formapktitle")} <i class="la la-info-circle la-lg" title="{__("admin_gateway_formapkdesc")}"></i>
                    </label>
                    <input type="file" name="apk_file" class="form-control pb-5">
                </div>

                {* Two Firebase files, working in opposite directions. This one is
                   the service account: it lets THIS SITE send notifications. *}
                <div class="form-group col-md-12">
                    <label>
                        {__("and_admin_build_line75")} <i class="la la-info-circle la-lg" title="{__("and_admin_build_line75_1")}"></i>
                        {if $data.assets.firebase}<span class="badge badge-success">{__("form_uploaded")}</span>{else}<span class="badge badge-danger">{__("form_notuploaded")}</span>{/if}
                    </label>
                    <input type="file" name="firebase" class="form-control pb-5">
                </div>

                {* And this one is the client config: it lets THE APP register for
                   a token. It used to be compiled into a per-customer APK build,
                   which is why one prebuilt app could not serve every site. *}
                <div class="form-group col-md-12">
                    <label>
                        {__("and_admin_build_line67")} <i class="la la-info-circle la-lg" title="{__("and_admin_build_line67_1")}"></i>
                        {if $data.assets.google}<span class="badge badge-success">{__("form_uploaded")}</span>{else}<span class="badge badge-danger">{__("form_notuploaded")}</span>{/if}
                    </label>
                    <input type="file" name="google" class="form-control pb-5">
                    <small class="form-text text-muted">{__("admin_gateway_googlepackage")}</small>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
                <i class="la la-check-circle la-lg"></i> {__("btn_submit")}
            </button>
        </div>
    </div>
</form>
