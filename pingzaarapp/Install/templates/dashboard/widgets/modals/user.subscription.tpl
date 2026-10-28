<div class="modal-content">
    <div class="modal-header">
        <h3 class="modal-title">
            <i class="la la-crown la-lg"></i> {$title}
        </h3>

        <button type="button" class="close" data-dismiss="modal">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    
    <div class="modal-body">
        <div class="row">
            <div class="col-md-4">
                <ul class="text-left">
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("form_package")}
                        </h4>
                        <h5 class="text-muted">{$data.subscription.name}</h5>
                        {if $data.subscription.id > 1}
                        <h5 class="text-warning">{__("and_usub23")} {date(logged_date_format, strtotime($data.subscription.expire_date))}</h5>
                        {/if}
                    </li>
                    {if in_array("sms", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub28")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.quota.sms_send} / {if $data.subscription.send_limit > 0}{number_format($data.subscription.send_limit)} {if __s("system_reset_mode") < 2}{__("form_daily")}{else}monthly{/if}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub34")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.quota.sms_receive} / {if $data.subscription.receive_limit > 0}{number_format($data.subscription.receive_limit)} {if __s("system_reset_mode") < 2}{__("form_daily")}{else}monthly{/if}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                    {if in_array("whatsapp", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub40")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.quota.wa_send} / {if $data.subscription.wa_send_limit > 0}{number_format($data.subscription.wa_send_limit)} {if __s("system_reset_mode") < 2}{__("form_daily")}{else}monthly{/if}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub46")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.quota.wa_receive} / {if $data.subscription.wa_receive_limit > 0}{number_format($data.subscription.wa_receive_limit)} {if __s("system_reset_mode") < 2}{__("form_daily")}{else}monthly{/if}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                </ul>
            </div>

            <div class="col-md-4">
                <ul class="text-left">
                    {if in_array("android_ussd", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub57")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.quota.ussd} / {if $data.subscription.ussd_limit > 0}{number_format($data.subscription.ussd_limit)} {if __s("system_reset_mode") < 2}{__("form_daily")}{else}monthly{/if}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                    {if in_array("sms", split($data.subscription.services, ",")) || in_array("whatsapp", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub69")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.scheduled} / {if $data.subscription.scheduled_limit > 0}{number_format($data.subscription.scheduled_limit)}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("user_subscriptioncontacts")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.contacts} / {if $data.subscription.contact_limit > 0}{number_format($data.subscription.contact_limit)}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {if in_array("api", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("form_keys")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.keys} / {if $data.subscription.key_limit > 0}{number_format($data.subscription.key_limit)}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                </ul>
            </div>

            <div class="col-md-4">
                <ul class="text-left">
                    {if in_array("webhooks", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("form_hooks")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.webhooks} / {if $data.subscription.webhook_limit > 0}{number_format($data.subscription.webhook_limit)}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                    {if in_array("actions", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub98")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.actions} / {if $data.subscription.action_limit > 0}{number_format($data.subscription.action_limit)}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                    {if in_array("sms", split($data.subscription.services, ",")) || in_array("android_ussd", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub104")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.devices} / {if $data.subscription.device_limit > 0}{number_format($data.subscription.device_limit)}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                    {if in_array("whatsapp", split($data.subscription.services, ","))}
                    <li class="mb-3">
                        <h4 class="text-uppercase">
                            {__("and_usub110")}
                        </h4>
                        <h5 class="text-muted">{$data.usage.wa_accounts} / {if $data.subscription.wa_account_limit > 0}{number_format($data.subscription.wa_account_limit)}{else}<i class="la la-infinity infinity"></i>{/if}</h5>
                    </li>
                    {/if}
                </ul>
            </div>
        </div>
    </div>
</div>