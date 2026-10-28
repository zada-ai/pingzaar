<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-satellite-dish la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("and_ussd_line17")} <i class="la la-info-circle" title="{__("and_ussd_line17_1")}"></i>
                    </label>
                    <input type="text" name="code" class="form-control" placeholder="{__("and_ussd_line19")}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("form_sim")} <i class="la la-info-circle" title="{__("and_ussd_line24")}"></i>
                    </label>
                    <select name="sim" class="form-control">
                        <option value="1" selected>{__("and_sms_quick49")}</option>
                        <option value="2">{__("and_sms_quick50")}</option>
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("form_device")} <i class="la la-info-circle" title="{__("and_ussd_line34")}"></i>
                    </label>
                    <select name="device" class="form-control" data-live-search="true">
                        {foreach $data.devices as $device}
                        <option value="{$device@key}" data-tokens="{$device.token}" data-content="{$device.name}" {if $device@index < 1}selected{/if}>{$device.name}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
            <i class="la la-satellite-dish la-lg"></i> {__("and_ussd_line47")}
            </button>
        </div>
    </div>
</form>