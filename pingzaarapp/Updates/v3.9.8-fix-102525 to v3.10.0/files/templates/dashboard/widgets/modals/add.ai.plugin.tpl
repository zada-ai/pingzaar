<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-plug la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <div class="alert alert-primary mb-0">
                        <strong>{__("form_addaiplugin_banner_title")}</strong><br>
                        {__("form_addaiplugin_banner_desc")}
                    </div>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("form_addaiplugin_pluginname_desc")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("form_addaiplugin_pluginname_placeholder")}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addaiplugin_endpoint_title")} <i class="la la-info-circle" title="{__("form_addaiplugin_endpoint_desc")}"></i>
                    </label>
                    <input type="text" name="endpoint" class="form-control" placeholder="eg. https://mydomain.com/ai_endpoint.php">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addaiplugin_schema_title")} <i class="la la-info-circle" title="{__("form_addaiplugin_schema_desc")}"></i>
                    </label>
                    <small class="text-danger">
                        {__("form_addaiplugin_sample_schema")} <a href="{site_url("uploads/system/ai.json")}" target="_blank">{__("btn_download")}</a> 
                    </small>

                    <div system-codeflask>{$data.schema}</div>
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