<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-code la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>
                        {__("and_edit_gate_line17")} <i class="la la-info-circle" title="{__("and_dash_gate_line17_1")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_dash_gate_line19")}" value="{$data.gateway.name}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_short_line24")} <i class="la la-info-circle" title="{__("and_dash_gate_line24_1")}"></i>
                    </label>
                    <small class="text-danger">
                        {__("and_short_line27")} <a href="https://github.com/titansys/zender-gateways" target="_blank">{__("form_btnall_visitlink")}</a> 
                    </small>
                    <input type="file" name="controller" class="form-control pb-5">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_dash_gate_line34")} <i class="la la-info-circle" title="{__("and_dash_gate_line34_1")}"></i>
                    </label>
                    <select name="callback" class="form-control">
                        <option value="1" {if $data.gateway.callback < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.gateway.callback > 1}selected{/if}>{__("form_disable")}</option>
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_edit_gate_line41")} <i class="la la-info-circle" title="{__("and_dash_gate_line44_1")}"></i>
                    </label>
                    <small class="text-danger">
                        {__("and_dash_gate_line47")}
                    </small>
                    <input type="text" name="callback_id" class="form-control" value="{$data.gateway.callback_id}" disabled>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("table_admingateways_pricingtitle")} <i class="la la-info-circle" title="{__("and_dash_gate_line54_1")}"></i>
                    </label>
                    <small class="text-danger">
                        {__("and_dash_gate_line57")} <a href="{site_url("uploads/system/gateway.json")}" target="_blank">{__("btn_download")}</a> 
                    </small>

                    <div system-codeflask>{$data.gateway.pricing}</div>
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