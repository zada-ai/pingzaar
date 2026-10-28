<div class="main-content" system-wrapper>
    {include "../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col">
                        <h6 class="header-pretitle">
                            {__("dashboard_title_default")}
                        </h6>

                        <h1 class="header-title">
                            <i class="la la-cubes la-lg"></i> {__("modal_packages_title")}
                        </h1>
                    </div>
                </div> 
            </div> 
        </div>
    </div> 

    <div class="container">
        <div class="row d-flex align-items-stretch">
            {foreach $data.packages as $package}
            <div class="col-12 col-lg-4 d-flex">
                <div class="card flex-fill">
                    <div class="card-body d-flex flex-column">
                        <div class="content">
                            <h6 class="text-uppercase text-center text-muted my-4">
                                {$package.name}
                            </h6>

                            <div class="row no-gutters align-items-center justify-content-center">
                                {if $package.id < 2}
                                <div class="col-auto">
                                    <div class="h1 mb-5 mt-5">{__("form_freefor")}</div>
                                </div>
                                {else}
                                <div class="col-auto">
                                    <div class="display-2 mb-0">
                                        {$package.price} <small>{strtoupper(system_currency)}</small>
                                    </div>
                                </div>
                                {/if}
                            </div> 

                            {if $package.id > 1}
                            <div class="h6 text-uppercase text-center text-muted mb-5">
                                {__("form_monthly")}
                            </div>
                            {/if}

                            <div class="mb-3">
                                <ul class="list-group list-group-flush">
                                    {if in_array("sms", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("dashboard_packagesmodal_smssend")}</small> <small>{if $package.send_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.send_limit)} {if __s("system_reset_mode") < 2}{__("default_pricecolumns_dailylabel")}{else}{__("default_pricecolumns_monthlylabel")}{/if}{/if}</small>
                                    </li>
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_usub34")}</small> <small>{if $package.receive_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.receive_limit)} {if __s("system_reset_mode") < 2}{__("default_pricecolumns_dailylabel")}{else}{__("default_pricecolumns_monthlylabel")}{/if}{/if}</small>
                                    </li>
                                    {/if}
                                    {if in_array("whatsapp", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_usub40")}</small> <small>{if $package.wa_send_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.wa_send_limit)} {if __s("system_reset_mode") < 2}{__("default_pricecolumns_dailylabel")}{else}{__("default_pricecolumns_monthlylabel")}{/if}{/if}</small>
                                    </li>
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_usub46")}</small> <small>{if $package.wa_receive_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.wa_receive_limit)} {if __s("system_reset_mode") < 2}{__("default_pricecolumns_dailylabel")}{else}{__("default_pricecolumns_monthlylabel")}{/if}{/if}</small>
                                    </li>
                                    {/if}
                                    {if in_array("android_ussd", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_package_line51")}</small> <small>{if $package.ussd_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.ussd_limit)} {if __s("system_reset_mode") < 2}{__("default_pricecolumns_dailylabel")}{else}{__("default_pricecolumns_monthlylabel")}{/if}{/if}</small>
                                    </li>
                                    {/if}
                                    {if in_array("sms", split($package.services, ",")) || in_array("whatsapp", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_usub69")}</small> <small>{if $package.scheduled_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.scheduled_limit)}{/if}</small>
                                    </li>
                                    {/if}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_package_line66")}</small> <small>{if $package.contact_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.contact_limit)}{/if}</small>
                                    </li>
                                    {if in_array("api", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_package_line71")}</small> <small>{if $package.key_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.key_limit)}{/if}</small>
                                    </li>
                                    {/if}
                                    {if in_array("webhooks", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_package_line76")}</small> <small>{if $package.webhook_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.webhook_limit)}{/if}</small>
                                    </li>
                                    {/if}
                                    {if in_array("actions", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_package_line81")}</small> <small>{if $package.action_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.action_limit)}{/if}</small>
                                    </li>
                                    {/if}
                                    {if in_array("sms", split($package.services, ",")) || in_array("android_ussd", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_usub104")}</small> <small>{if $package.device_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.device_limit)}{/if}</small>
                                    </li>
                                    {/if}
                                    {if in_array("whatsapp", split($package.services, ","))}
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                        <small>{__("and_package_line91")}</small> <small>{if $package.wa_account_limit < 1}{__("default_pricecolumns_unlimitedlabel")}{else}{number_format($package.wa_account_limit)}{/if}</small>
                                    </li>
                                    {/if}
                                </ul>
                            </div>
                        </div>

                        <div class="btn-container mt-auto">
                            <button class="btn btn-block btn-primary" {if $package.id > 1}system-toggle="add.duration/{$package.id}"{/if} {if $package.id < 2}disabled{/if}>
                                {if $package.id < 2}
                                    <i class="la la-bolt"></i> {__("btn_free")}
                                {else}
                                    <i class="la la-credit-card"></i> {__("btn_purchase")}
                                {/if}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            {/foreach}
        </div>


        {include "../../modules/footer.block.tpl"}
    </div>
</div>