<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-key la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_web_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="eg. {__("form_webhookname_placeholder")}" value="{$data.webhook.name}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_edweb_line24")} <i class="la la-info-circle" title="{__("and_web_line24_1")}"></i>
                    </label>
                    <select name="events[]" class="form-control" multiple>
                        <option value="sms" {if in_array("sms", split($data.webhook.events, ","))}selected{/if}>SMS</option>
                        <option value="whatsapp" {if in_array("whatsapp", split($data.webhook.events, ","))}selected{/if}>WhatsApp</option>
                        <option value="ussd" {if in_array("ussd", split($data.webhook.events, ","))}selected{/if}>USSD</option>
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_webhookurl")} <i class="la la-info-circle" title="{__("and_web_line36")}"></i>
                    </label>
                    <input type="text" name="url" class="form-control" placeholder="{__("and_edweb_line38")}" value="{$data.webhook.url}">
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