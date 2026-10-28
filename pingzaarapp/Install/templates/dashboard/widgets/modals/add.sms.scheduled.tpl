<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-calendar la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_sms_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_sms_line19")}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_schedule_schedule")} <i class="la la-info-circle" title="{__("and_sms_line24")}"></i>
                    </label>
                    <input type="text" name="schedule" class="form-control" placeholder="{__("and_sms_line26")}" system-datepicker-schedule>
                </div>

                <div class="form-group col-md-4">
                    <div system-device-mode>
                        <label>
                            {__("form_device")} <i class="la la-info-circle" title="{__("and_sms_line110")}"></i>
                        </label>
                        <select name="device" class="form-control" data-live-search="true">
                            {foreach $data.devices as $device}
                            <option value="{$device@key}" data-tokens="{$device.token}" data-content="{$device.name}" {if $device@index < 1}selected{/if}>{$device.name}</option>
                            {/foreach}
                        </select>
                    </div>

                    <div system-credits-mode>
                        <label>
                            {__("and_sms_line121")} <i class="la la-info-circle" title="{__("and_sms_exe70_1")}"></i>
                        </label>
                        <select name="gateway" class="form-control" data-live-search="true">
                            {foreach $data.gateways as $gateway}
                            <option value="{$gateway.id}" data-tokens="{$gateway.name}">{$gateway.name}</option>
                            {/foreach}
                            {if !empty($data.devicesGlobal)}
                            {if !empty($data.gateways)}
                            <option data-divider="true"></option>
                            {/if}
                            {foreach $data.devicesGlobal as $device}
                            <option value="{$device@key}" data-tokens="{$device.token}" data-content="<i class='flag-icon flag-icon flag-icon-{strtolower($device.country)}'></i> {$device.name} <span class='badge badge-primary'>{__("form_smsall_globalstatus")}</span> ({$device.rate} PHP)" {if empty($data.gateways) && $device@index < 1}selected{/if}>{$device.name} ({__("form_smsall_globalstatus")})</option>
                            {/foreach}
                            {/if}
                        </select>
                    </div>
                </div>

                <div class="form-group col-md-4">
                    <div class="form-group">
                        <label>
                            {__("form_schedule_numbers")} <i class="la la-info-circle" title="{__("and_sms_line42")}"></i>
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
                        {__("form_groups")} <i class="la la-info-circle" title="{__("and_sms_line55")}"></i>
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
                        {__("and_sms_line67")} <i class="la la-info-circle" title="{__("and_whatex27_1")}"></i>
                    </label>
                    <select name="shortener" class="form-control">
                        <option value="0" selected>{__("and_sms_line70")}</option>
                        {foreach $data.shorteners as $shortener}
                        <option value="{$shortener@key}">{$shortener.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("forms_repeatdays_title")} <i class="la la-info-circle" title="{__("forms_repeatdays_tagline")}"></i>
                    </label>
                    <input type="number" name="repeat" class="form-control" placeholder="ex. 7" value="0">

                    <label class="mt-3">
                        {__("and_sms_line79")} <i class="la la-info-circle" title="{__("and_sms_exe39_1")}"></i>
                    </label>
                    <select name="mode" class="form-control" system-select-mode>
                        <option value="1" selected>{__("and_sms_line82")}</option>
                        <option value="2">{__("and_sms_line83")}</option>
                    </select>

                    <div class="mt-3" system-device-mode>
                        <label>
                            {__("form_sim")} <i class="la la-info-circle" title="{__("and_sms_line88")}"></i>
                        </label>
                        <select name="sim" class="form-control">
                            <option value="1" selected>{__("and_sms_quick49")}</option>
                            <option value="2">{__("and_sms_quick50")}</option>
                        </select>
                    </div>

                    <label class="mt-3">
                        {__("form_template")} <i class="la la-info-circle" title="{__("and_sms_line97")}"></i>
                    </label>
                    <select class="form-control" data-live-search="true" system-select-template>
                        <option value="none" data-tokens="no none 0" selected>{__("form_none")}</option>
                        {foreach $data.templates as $template}
                        <option value="{$template@key}" data-tokens="{$template.token}" data-format="{$template.format}">{$template.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-8">
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
                        <small>{__("and_sms_bulk_135")}</small> <code>Tom is a {literal}<strong>{good|bad}</strong>{/literal} cat</code>
                    </p>
                    <p>
                        <small>{___(__("form_literal_spintaxdesc2"), ["<strong>good</strong>", "<strong>bad</strong>"])}</small>
                    </p>

                    <label>{__("form_shortcodes")}</label>
                    {literal}
                    <p>
                        <code><strong>{{contact.name}}</strong>, <strong>{{contact.number}}</strong>, <strong>{{group.name}}</strong>, <strong>{{date.now}}</strong>, <strong>{{date.time}}</strong></code>
                    </p>
                    {/literal}
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
                <i class="la la-clock la-lg"></i> {__("form_smsschedule_submit")}
            </button>
        </div>
    </div>
</form>