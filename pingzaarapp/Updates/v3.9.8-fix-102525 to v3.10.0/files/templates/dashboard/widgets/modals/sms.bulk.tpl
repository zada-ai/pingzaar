<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-mail-bulk la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-md-5">
                    <div class="form-group">
                        <label>
                            {__("forms_campagin_formname")} <i class="la la-info-circle" title="{__("forms_campagin_formhelp")}"></i>
                        </label>
                        <input type="text" name="campaign" class="form-control" placeholder="{__("forms_campaign_formplaceholder")}">
                    </div>
                </div>

                <div class="form-group col-md-7">
                    <div class="form-group">
                        <label>
                            {__("form_bulksms_numbers")} <i class="la la-info-circle" title="{__("and_sms_bulk_18")}"></i>
                        </label>
                        <textarea name="numbers" class="form-control" rows="3" placeholder="{$data.number}
{$data.number}
{$data.number}
{$data.number}
{$data.number}
"></textarea>
                    </div>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_groups")} <i class="la la-info-circle" title="{__("and_sms_bulk_31")}"></i>
                    </label>
                    <select name="groups[]" class="form-control" data-live-search="true" system-select-groups multiple>
                        <option value="0" data-tokens="None {__("form_select_multinone")}" selected>{__("form_select_multinone")}</option>
                        {foreach $data.groups as $group}
                        <option value="{$group@key}" data-tokens="{$group.token}">{$group.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("and_sms_bulk_43")} <i class="la la-info-circle" title="{__("and_whatex27_1")}"></i>
                    </label>
                    <select name="shortener" class="form-control">
                        <option value="0" selected>{__("and_sms_bulk_46")}</option>
                        {foreach $data.shorteners as $shortener}
                        <option value="{$shortener@key}">{$shortener.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("and_sms_bulk_55")} <i class="la la-info-circle" title="{__("and_sms_exe39_1")}"></i>
                    </label>
                    <select name="mode" class="form-control" system-select-mode>
                        <option value="1" selected>{__("and_sms_bulk_58")}</option>
                        <option value="2">{__("and_sms_bulk_59")}</option>
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <div system-device-mode>
                        <label>
                            {__("form_sim")} <i class="la la-info-circle" title="{__("and_sms_bulk_64")}"></i>
                        </label>
                        <select name="sim" class="form-control mb-3">
                            <option value="1" selected>{__("and_sms_quick49")}</option>
                            <option value="2">{__("and_sms_quick50")}</option>
                        </select>
                        
                        <label>
                            {__("form_priority")} <i class="la la-info-circle" title="{__("and_sms_quick56")}"></i>
                        </label>
                        <select name="priority" class="form-control mb-3">
                            <option value="0" selected>{__("form_no")}</option>
                            <option value="1">{__("form_yes")}</option>
                        </select>
                    </div>

                    <label>
                        {__("form_template")} <i class="la la-info-circle" title="{__("and_sms_bulk_81")}"></i>
                    </label>
                    <select class="form-control" data-live-search="true" system-select-template>
                        <option value="none" data-tokens="no none 0" selected>{__("form_none")}</option>
                        {foreach $data.templates as $template}
                        <option value="{$template@key}" data-tokens="{$template.token}" data-format="{$template.format}">{$template.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-8">
                    <div system-device-mode>
                        <label>
                            {__("form_device")} <i class="la la-info-circle" title="{__("and_sms_bulk_94")}"></i>
                        </label>
                        <select name="devices[]" class="form-control mb-3" data-live-search="true" system-device-list multiple>
                            {foreach $data.devices as $device}
                            <option value="{$device@key}" data-tokens="{$device.token}" data-content="{$device.name}" {if $device@index < 1}selected{/if}>{$device.name}</option>
                            {/foreach}
                        </select>
                    </div>

                    <div system-credits-mode>
                        <label>
                            {__("and_sms_bulk_105")} <i class="la la-info-circle" title="{__("and_sms_bulk_105_1")}"></i>
                        </label>
                        <select name="gateways[]" class="form-control mb-3" data-live-search="true" system-device-list multiple>
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

                    <label>
                        {__("form_message")} <span class="badge text-white bg-primary" system-counter-view></span>
                    </label>
                    
                    <button class="btn btn-primary btn-sm" title="{__("and_whatquick_50")}" system-action="translate">
                        <i class="la la-language"></i> {__("sms_btnevent_formcontent_btntranslate")}
                    </button>

                    <textarea name="message" class="form-control mb-3" rows="7" placeholder="{__("form_message_placeholder")}" system-counter></textarea>

                    <label>
                        {__("and_sms_bulk_131")} <i class="la la-info-circle" title="{__("and_sms_bulk_131_1")}"></i>
                    </label>
                    <p>
                        <small>{__("and_sms_bulk_135")}</small> <code>{$data.spintax_sample.main}</code>
                    </p>
                    <p>
                        <small>{___(__("form_literal_spintaxdesc2"), ["<strong>{$data.spintax_sample.good}</strong>", "<strong>{$data.spintax_sample.bad}</strong>"])}</small>
                    </p>

                    <label>
                        {__("form_shortcodes")} <i class="la la-info-circle" title="{__("and_sms_bulk_143")}"></i>
                    </label>
                    {literal}
                    <p>
                        <code><strong>{{contact.name}}</strong>, <strong>{{contact.number}}</strong>, <strong>{{group.name}}</strong>, <strong>{{date.now}}</strong>, <strong>{{date.time}}</strong>, <strong>{{unsubscribe.command}}</strong>, <strong>{{unsubscribe.link}}</strong></code>
                    </p>
                    {/literal}
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