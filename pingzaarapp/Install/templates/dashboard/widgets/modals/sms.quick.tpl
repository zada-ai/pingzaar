<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-telegram la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_number")} <i class="la la-info-circle" title="{__("and_sms_quick17")}"></i>
                    </label>
                    <input type="text" name="phone" class="form-control" placeholder="eg. {$data.phone}" system-autocomplete="contacts">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_sms_quick24")} <i class="la la-info-circle" title="{__("and_sms_exe39_1")}"></i>
                    </label>
                    <select name="mode" class="form-control" system-select-mode>
                        <option value="1" selected>{__("and_sms_quick27")}</option>
                        <option value="2">{__("and_sms_quick28")}</option>
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_sms_quick34")} <i class="la la-info-circle" title="{__("and_whatex27_1")}"></i>
                    </label>
                    <select name="shortener" class="form-control">
                        <option value="0" selected>{__("and_sms_quick37")}</option>
                        {foreach $data.shorteners as $shortener}
                        <option value="{$shortener@key}">{$shortener.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-6" system-device-mode>
                    <label>
                        {__("form_sim")} <i class="la la-info-circle" title="{__("and_sms_quick46")}"></i>
                    </label>
                    <select name="sim" class="form-control">
                        <option value="1" selected>{__("and_sms_quick49")}</option>
                        <option value="2">{__("and_sms_quick50")}</option>
                    </select>
                </div>

                <div class="form-group col-md-6" system-device-mode>
                    <label>
                        {__("form_priority")} <i class="la la-info-circle" title="{__("and_sms_quick56")}"></i>
                    </label>
                    <select name="priority" class="form-control">
                        <option value="1" selected>{__("form_no")}</option>
                        <option value="2">{__("form_yes")}</option>
                    </select>
                </div>

                <div class="form-group col-12" system-device-mode>
                    <label>
                        {__("form_device")} <i class="la la-info-circle" title="{__("and_sms_quick66")}"></i>
                    </label>
                    <select name="device" class="form-control" data-live-search="true" system-device-list>
                        {foreach $data.devices as $device}
                        <option value="{$device@key}" data-tokens="{$device.token}" data-content="{$device.name}" {if $device@index < 1}selected{/if}>{$device.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-12" system-credits-mode>
                    <label>
                        {__("and_sms_quick77")} <i class="la la-info-circle" title="{__("and_sms_exe70_1")}"></i>
                    </label>
                    <select name="gateway" class="form-control mb-3" data-live-search="true" system-device-list>
                        {foreach $data.gateways as $gateway}
                        <option value="{$gateway.id}" data-tokens="{$gateway.name}">{$gateway.name}</option>
                        {/foreach}
                        {if !empty($data.devicesGlobal)}
                        {if !empty($data.gateways)}
                        <option data-divider="true"></option>
                        {/if}
                        {foreach $data.devicesGlobal as $device}
                        <option value="{$device@key}" data-tokens="{$device.token}" data-content="<i class='flag-icon flag-icon flag-icon-{strtolower($device.country)}'></i> {$device.name} (<span class='text-lowercase'>{$device.owner|truncate:15:"..."}</span>) <span class='badge badge-primary'>{__("form_smsall_globalstatus")}</span>" {if empty($data.gateways) && $device@index < 1}selected{/if}>{$device.name} ({__("form_smsall_globalstatus")})</option>
                        {/foreach}
                        {/if}
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_message")} <span class="badge text-white bg-primary" system-counter-view></span>
                    </label>
                    
                    <button class="btn btn-primary btn-sm" title="{__("and_whatquick_50")}" system-action="translate">
                        <i class="la la-language"></i> {__("sms_btnevent_formcontent_btntranslate")}
                    </button>

                    <textarea name="message" class="form-control mb-3" rows="5" placeholder="{__("form_message_placeholder")}" system-counter></textarea>

                    <label>
                        {__("and_sms_quick104")} <i class="la la-info-circle" title="{__("and_sms_quick104_1")}"></i>
                    </label>
                    <p>
                        <small>{__("and_sms_bulk_135")}</small> <code>{$data.spintax_sample.main}</code>
                    </p>
                    <p>
                        <small>{___(__("form_literal_spintaxdesc2"), ["<strong>{$data.spintax_sample.good}</strong>", "<strong>{$data.spintax_sample.bad}</strong>"])}</small>
                    </p>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
                <i class="la la-telegram la-lg"></i> {__("btn_send")}
            </button>
        </div>
    </div>
</form>