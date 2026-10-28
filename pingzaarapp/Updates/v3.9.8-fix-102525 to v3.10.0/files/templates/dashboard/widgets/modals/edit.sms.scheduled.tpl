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
                    <input type="text" name="name" class="form-control" placeholder="{__("and_sms_line19")}" value="{$data.scheduled.name}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_schedule_schedule")} <i class="la la-info-circle" title="{__("and_sms_line24")}"></i>
                    </label>
                    <input type="text" name="schedule" class="form-control" placeholder="{__("and_sms_line26")}" value="{date("m/d/Y h:i A", $data.scheduled.send_date)}" system-datepicker-schedule>
                </div>

                <div class="form-group col-md-4">
                    <div system-device-mode>
                        <label>
                            {__("form_device")} <i class="la la-info-circle" title="{__("and_edit_sms_line88")}"></i>
                        </label>
                        <select name="device" class="form-control" data-live-search="true">
                            {foreach $data.devices as $device}
                            <option value="{$device@key}" data-tokens="{$device.token}" data-content="{$device.name}" {if $device@key eq $data.scheduled.did}selected{/if}>{$device.name}</option>
                            {/foreach}
                        </select>
                    </div>

                    <div system-credits-mode>
                        <label>
                            {__("and_edit_sms_line99")} <i class="la la-info-circle" title="{__("and_sms_exe70_1")}"></i>
                        </label>
                        <select name="gateway" class="form-control" data-live-search="true">
                            {foreach $data.gateways as $gateway}
                            <option value="{$gateway.id}" data-tokens="{$gateway.name}" {if $gateway@key eq $data.scheduled.gateway}selected{/if}>{$gateway.name}</option>
                            {/foreach}
                            {if !empty($data.devicesGlobal)}
                            {if !empty($data.gateways)}
                            <option data-divider="true"></option>
                            {/if}
                            {foreach $data.devicesGlobal as $device}
                            <option value="{$device@key}" data-tokens="{$device.token}" data-content="<i class='flag-icon flag-icon flag-icon-{strtolower($device.country)}'></i> {$device.name} <span class='badge badge-primary'>{__("form_smsall_globalstatus")}</span> ({$device.rate} PHP)" {if $device@key eq $data.scheduled.did}selected{/if}>{$device.name} ({__("form_smsall_globalstatus")})</option>
                            {/foreach}
                            {/if}
                        </select>
                    </div>
                </div>

                <div class="form-group col-md-6">
                    <div class="form-group">
                        <label>
                            {__("form_schedule_numbers")} <i class="la la-info-circle" title="{__("and_sms_line42")}"></i>
                        </label>
                        <textarea name="numbers" class="form-control" rows="3" placeholder="{$data.number}
{$data.number}
{$data.number}
{$data.number}
{$data.number}
">{$data.scheduled.numbers}</textarea>
                    </div>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("form_groups")} <i class="la la-info-circle" title="{__("and_sms_line55")}"></i>
                    </label>
                    <select name="groups[]" class="form-control" data-live-search="true" system-select-groups multiple>
                        <option value="0" data-tokens="None {__("form_select_multinone")}" {if in_array(0, split($data.scheduled.groups, ","))}selected{/if}>{__("form_select_multinone")}</option>
                        {foreach $data.groups as $group}
                        <option value="{$group@key}" data-tokens="{$group.token}" {if in_array($group@key, split($data.scheduled.groups, ","))}selected{/if}>{$group.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("forms_repeatdays_title")} <i class="la la-info-circle" title="{__("forms_repeatdays_tagline")}"></i>
                    </label>
                    <input type="number" name="repeat" class="form-control" placeholder="eg. 7" value="{$data.scheduled.repeat}">

                    <label class="mt-3">
                        {__("and_edit_sms_line67")} <i class="la la-info-circle" title="{__("and_sms_exe39_1")}"></i>
                    </label>
                    <select name="mode" class="form-control" system-select-mode>
                        <option value="1" {if $data.scheduled.mode < 2}selected{/if}>{__("and_edit_sms_line70")}</option>
                        <option value="2" {if $data.scheduled.mode > 1}selected{/if}>{__("and_edit_sms_line71")}</option>
                    </select>

                    <div system-device-mode>
                        <label>
                            {__("form_sim")} <i class="la la-info-circle" title="{__("and_edit_sms_line76")}"></i>
                        </label>
                        <select name="sim" class="form-control">
                            <option value="1" {if $data.scheduled.sim < 2}selected{/if}>{__("and_sms_quick49")}</option>
                            <option value="2" {if $data.scheduled.sim > 1}selected{/if}>{__("and_sms_quick50")}</option>
                        </select>
                    </div>
                </div>

                <div class="form-group col-md-8">
                    <label>
                        {__("form_message")} <span class="badge text-white bg-primary" system-counter-view></span>
                    </label>

                    <button class="btn btn-primary btn-sm" title="{__("and_whatquick_50")}" system-action="translate">
                        <i class="la la-language"></i> {__("sms_btnevent_formcontent_btntranslate")}
                    </button>
                    
                    <textarea name="message" class="form-control mb-3" rows="7" placeholder="{__("form_message_placeholder")}" system-counter>{$data.scheduled.message}</textarea>

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