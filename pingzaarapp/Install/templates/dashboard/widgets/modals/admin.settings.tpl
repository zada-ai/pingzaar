<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-cog la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group mb-0 col-12">
                    <h2 class="text-uppercase">{__("form_settingsite")}</h2>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingssitename")} <i class="la la-info-circle la-lg" title="{__("and_settings_line21")}"></i>
                    </label>
                    <input type="text" name="site_name" class="form-control" placeholder="{__("and_settings_line23")}" value="{$data.system.site_name}">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingssitedesc")} <i class="la la-info-circle la-lg" title="{__("and_settings_line28")}"></i>
                    </label>
                    <input type="text" name="site_desc" class="form-control" placeholder="{__("and_settings_line30")}"  value="{$data.system.site_desc}">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingspcode")} <i class="la la-info-circle la-lg" title="{__("and_settings_line35")}"></i>
                    </label>
                    <input type="text" name="purchase_code" class="form-control" placeholder="{__("and_settings_line37")}" value="{$data.system.purchase_code}">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingsanalytics")} <i class="la la-info-circle la-lg" title="{__("and_settings_line42")}"></i>
                    </label>
                    <input type="text" name="analytics_key" class="form-control" placeholder="{__("and_settings_line44")}" value="{__s("system_analytics_key")}">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingsprotocol")} <i class="la la-info-circle la-lg" title="{__("and_settings_line49")}"></i>
                    </label>
                    <select name="protocol" class="form-control">
                        <option value="1" {if $data.system.protocol < 2}selected{/if}>HTTP</option>
                        <option value="2" {if $data.system.protocol > 1}selected{/if}>HTTPS</option>  
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingsdeflang")} <i class="la la-info-circle la-lg" title="{__("and_settings_line59")}"></i>
                    </label>
                    <select name="default_lang" class="form-control" data-live-search="true">
                        {foreach $data.languages as $language}
                        <option value="{$language@key}" data-tokens="{$language.token}" {if $data.system.default_lang eq $language@key}selected{/if}>{$language.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("and_settings_line80")} <i class="la la-info-circle la-lg" title="{__("and_settings_line80_1")}"></i>
                    </label>
                    <select name="reset_mode" class="form-control">
                        <option value="1" {if $data.system.reset_mode < 2}selected{/if}>{__("and_settings_line83")}</option>
                        <option value="2" {if $data.system.reset_mode > 1}selected{/if}>{__("form_monthly")}</option>
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("and_settings_line90")} <i class="la la-info-circle la-lg" title="{__("and_settings_line90_1")}"></i>
                    </label>
                    <select name="freemodel" class="form-control">
                        <option value="1" {if $data.system.freemodel < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.system.freemodel > 1}selected{/if}>{__("form_disable")}</option>  
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("administration_landing_api")} <i class="la la-info-circle la-lg" title="{__("and_settings_line127_1")}"></i>
                    </label>
                    <select name="admin_api[]" class="form-control" data-live-search="true" system-select-adminapi multiple>
                        <option value="0" {if in_array("0", split(__s("system_admin_api"), ","))}selected{/if}>{__("form_disable")}</option>
                        <option value="get_users" {if in_array("get_users", split(__s("system_admin_api"), ","))}selected{/if}>get_users</option>
                        <option value="get_roles" {if in_array("get_roles", split(__s("system_admin_api"), ","))}selected{/if}>get_roles</option>
                        <option value="get_packages" {if in_array("get_packages", split(__s("system_admin_api"), ","))}selected{/if}>get_packages</option>
                        <option value="get_vouchers" {if in_array("get_vouchers", split(__s("system_admin_api"), ","))}selected{/if}>get_vouchers</option>
                        <option value="get_subscriptions" {if in_array("get_subscriptions", split(__s("system_admin_api"), ","))}selected{/if}>get_subscriptions</option>
                        <option value="get_transactions" {if in_array("get_transactions", split(__s("system_admin_api"), ","))}selected{/if}>get_transactions</option>
                        <option value="get_languages" {if in_array("get_languages", split(__s("system_admin_api"), ","))}selected{/if}>get_languages</option>
                        <option value="get_apikeys" {if in_array("get_apikeys", split(__s("system_admin_api"), ","))}selected{/if}>get_apikeys</option>
                        <option value="create_user" {if in_array("create_user", split(__s("system_admin_api"), ","))}selected{/if}>create_user</option>
                        <option value="create_apikey" {if in_array("create_apikey", split(__s("system_admin_api"), ","))}selected{/if}>create_apikey</option>
                        <option value="create_role" {if in_array("create_role", split(__s("system_admin_api"), ","))}selected{/if}>create_role</option>
                        <option value="create_package" {if in_array("create_package", split(__s("system_admin_api"), ","))}selected{/if}>create_package</option>
                        <option value="create_voucher" {if in_array("create_voucher", split(__s("system_admin_api"), ","))}selected{/if}>create_voucher</option>
                        <option value="create_subscription" {if in_array("create_subscription", split(__s("system_admin_api"), ","))}selected{/if}>create_subscription</option>
                        <option value="edit_user" {if in_array("edit_user", split(__s("system_admin_api"), ","))}selected{/if}>edit_user</option>
                        <option value="edit_role" {if in_array("edit_role", split(__s("system_admin_api"), ","))}selected{/if}>edit_role</option>
                        <option value="edit_package" {if in_array("edit_package", split(__s("system_admin_api"), ","))}selected{/if}>edit_package</option>
                        <option value="delete_user" {if in_array("delete_user", split(__s("system_admin_api"), ","))}selected{/if}>delete_user</option>
                        <option value="delete_role" {if in_array("delete_role", split(__s("system_admin_api"), ","))}selected{/if}>delete_role</option>
                        <option value="delete_package" {if in_array("delete_package", split(__s("system_admin_api"), ","))}selected{/if}>delete_package</option>
                        <option value="delete_voucher" {if in_array("delete_voucher", split(__s("system_admin_api"), ","))}selected{/if}>delete_voucher</option>
                        <option value="delete_subscription" {if in_array("delete_subscription", split(__s("system_admin_api"), ","))}selected{/if}>delete_subscription</option>
                        <option value="delete_transaction" {if in_array("delete_transaction", split(__s("system_admin_api"), ","))}selected{/if}>delete_transaction</option>
                        <option value="delete_apikey" {if in_array("delete_apikey", split(__s("system_admin_api"), ","))}selected{/if}>delete_apikey</option>
                        <option value="redeem_voucher" {if in_array("redeem_voucher", split(__s("system_admin_api"), ","))}selected{/if}>redeem_voucher</option>
                        <option value="clear_cache" {if in_array("clear_cache", split(__s("system_admin_api"), ","))}selected{/if}>clear_cache</option>
                    </select>
                </div>

                <div class="form-group col-md-2">
                    <label>
                        {__("modal_adminsettings_garbageretentitle")} <i class="la la-info-circle la-lg" title="{__("modal_adminsettings_garbageretentitle_desc")}"></i>
                    </label>
                    <input type="number" name="garbage_retention" class="form-control" min="1" placeholder="eg. 10" value="{__s("system_garbage_retention")}">
                </div>

                <div class="form-group col-md-2">
                    <label>
                        {__("and_settings_line110")} <i class="la la-info-circle la-lg" title="{__("and_settings_line110_1")}"></i>
                    </label>
                    <select name="livechat" class="form-control">
                        <option value="1" {if $data.system.livechat < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.system.livechat > 1}selected{/if}>{__("form_disable")}</option>
                    </select>
                </div>

                <div class="form-group col-md-5">
                    <label>
                        {__("form_settings_tawkchatlink")} <i class="la la-info-circle la-lg" title="{__("and_settings_line120")}"></i>
                    </label>
                    <input type="text" name="tawk_id" class="form-control" placeholder="eg. https://tawk.to/chat/5ead66fc10362a7578be7856/1f7tsdspq" value="{$data.system.tawk_id}">
                </div>

                <div class="form-group col-md-6">
                    <h4 class="text-uppercase">
                        {__("system_settings_adminapitoken_title")} <i class="la la-info-circle la-lg" title="{__("system_settings_adminapitoken_desc")}"></i>
                        <button type="button" class="btn btn-danger btn-sm" system-action="regenerate_admintoken">{__("and_settings_line160")}</button>
                    </h4>
                    <code>
                        <p admin-api-token>{__s("system_admin_token")}</p>
                    </code>
                </div>

                <div class="form-group col-md-6">
                    <h4 class="text-uppercase">
                        {__("form_adminsettings_token")} <i class="la la-info-circle la-lg" title="{__("system_settings_systokenregen_desc")}"></i>
                        <button type="button" class="btn btn-danger btn-sm" system-action="regenerate_systoken">{__("and_settings_line160")}</button>
                    </h4>
                    <code>
                        <p sys-token>{__s("system_token")}</p>
                    </code>
                </div>

                <div class="form-group mb-0 col-12">
                    <h2 class="text-uppercase">{__("form_settingsmailing")}</h2>
                </div>

                <div class="form-group col-md-2">
                    <label>
                        {__("form_settingsmailfunc")} <i class="la la-info-circle la-lg" title="{__("and_settings_line173")}"></i>
                    </label>
                    <select name="mail_function" class="form-control">
                        <option value="1" {if $data.system.mail_function < 2}selected{/if}>{__("form_native")}</option>
                        <option value="2" {if $data.system.mail_function > 1}selected{/if}>{__("form_remotesmtp")}</option> 
                    </select>
                </div>

                <div class="form-group col-md-2">
                    <label>
                        {__("form_settingssmtpsecure")} <i class="la la-info-circle la-lg" title="{__("and_settings_line183")}"></i>
                    </label>
                    <select name="smtp_secure" class="form-control">
                        <option value="1" {if $data.system.smtp_secure < 2}selected{/if}>TLS</option>
                        <option value="2" {if $data.system.smtp_secure > 1}selected{/if}>SSL</option> 
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingssitemail")} <i class="la la-info-circle la-lg" title="{__("and_settings_line193")}"></i>
                    </label>
                    <input type="text" name="site_mail" class="form-control" placeholder="{__("and_settings_line195")}" value="{$data.system.site_mail}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingssmtphost")} <i class="la la-info-circle la-lg" title="{__("and_settings_line200")}"></i>
                    </label>
                    <small class="text-muted">
                        {__("form_settingssmtp_small")}
                    </small>
                    <input type="text" name="smtp_host" class="form-control" placeholder="{__("and_settings_line205")}" value="{$data.system.smtp_host}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingssmtpport")} <i class="la la-info-circle la-lg" title="{__("and_settings_line210")}"></i>
                    </label>
                    <small class="text-muted">
                        {__("form_settingssmtp_small")}
                    </small>
                    <input type="text" name="smtp_port" class="form-control" placeholder="{__("and_settings_line215")}" value="{$data.system.smtp_port}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingssmtpusername")} <i class="la la-info-circle la-lg" title="{__("and_settings_line220")}"></i>
                    </label>
                    <small class="text-muted">
                        {__("form_settingssmtp_small")}
                    </small>
                    <input type="text" name="smtp_username" class="form-control" placeholder="{__("and_settings_line225")}" value="{$data.system.smtp_username}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingssmtppassword")} <i class="la la-info-circle la-lg" title="{__("and_settings_line230")}"></i>
                    </label>
                    <small class="text-muted">
                        {__("form_settingssmtp_small")}
                    </small>
                    <input type="password" name="smtp_password" class="form-control" placeholder="{__("and_settings_line235")}" value="{$data.system.smtp_password}">
                </div>

                <div class="form-group mb-0 col-12">
                    <h2 class="text-uppercase">{__("form_settingspayments")}</h2>
                </div>

                <div class="form-group col-md-6 mb-0">
                    <label>
                        {__("widgets_viewbank_titlenew")} <i class="la la-info-circle la-lg" title="{__("modal_adminsettings_offlinepaymenttooltipnew")}"></i>
                    </label>
                    <select name="offline_payment" class="form-control mb-4">
                        <option value="1" {if $data.system.offline_payment < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.system.offline_payment > 1}selected{/if}>{__("form_disable")}</option> 
                    </select>

                    <label>
                        {__("modal_adminsettings_systemcurrencynew")} <i class="la la-info-circle la-lg" title="{__("modal_adminsettings_systemcurrencytooltipnew")}"></i>
                    </label>
                    <select name="currency" class="form-control" data-live-search="true">
                        {foreach $data.currencies as $currency}
                        <option value="{$currency}" data-tokens="{$currency}" {if $data.system.currency eq $currency}selected{/if}>{$currency}</option>
                        {/foreach}
                    </select>

                    <label>
                        {__("modal_adminsettings_currencyapikey")}
                    </label>
                    <small class="text-muted">
                        {__("modal_adminsettings_currencyapikeyinfo")}
                    </small>
                    <input type="text" name="currency_api_key" class="form-control" value="{$data.system.currency_api_key}">
                </div>

                <div class="form-group col-md-6 mb-0">
                    <label>
                        {__("modal_adminsettings_banktransfer1new")} <i class="la la-info-circle la-lg" title="{__("modal_adminsettings_banktransfer2new")}"></i>
                    </label>
                    <textarea name="bank_template" class="form-control mb-3" rows="10">{__s("system_bank_template")}</textarea>

                    <label>
                        {__("form_shortcodes")} <i class="la la-info-circle" title="{__("modal_adminsettings_banktransfer3new")}"></i>
                    </label>
                    {literal}
                    <p>
                        <code><strong>{{user.name}}</strong>, <strong>{{user.email}}</strong>, <strong>{{user.country}}</strong>, <strong>{{order.price}}</strong></code>
                    </p>
                    {/literal}
                </div>

                <div class="form-group mb-0 col-12">
                    <h2 class="text-uppercase">{__("modal_adminsettings_realtimetitle")}</h2>
                </div>

                <div class="form-group col-md-6 mb-0">
                    <small class="text-muted d-block mb-3">
                        {__("modal_adminsettings_realtimeinfo")}
                    </small>

                    <label>
                        {__("modal_adminsettings_realtimedriver")}
                    </label>
                    <select name="realtime_driver" class="form-control mb-4" system-realtime-driver>
                        <option value="pusher" {if $data.system.realtime_driver neq "compatible"}selected{/if}>{__("modal_adminsettings_realtimedriverpusher")}</option>
                        <option value="compatible" {if $data.system.realtime_driver eq "compatible"}selected{/if}>{__("modal_adminsettings_realtimedrivercompatible")}</option>
                    </select>

                    <div system-realtime-pusher{if $data.system.realtime_driver eq "compatible"} style="display: none;"{/if}>
                        <label>
                            {__("modal_adminsettings_realtimecluster")}
                        </label>
                        <small class="text-muted d-block">
                            <a href="https://dashboard.pusher.com" target="_blank" rel="noopener noreferrer">{__("modal_adminsettings_realtimecredentials")}</a>
                        </small>
                        <select name="realtime_cluster" class="form-control">
                            {foreach ["mt1" => "US East (N. Virginia)", "us2" => "US East (Ohio)", "us3" => "US West (Oregon)", "eu" => "Europe (Ireland)", "ap1" => "Asia Pacific (Singapore)", "ap2" => "Asia Pacific (Mumbai)", "ap3" => "Asia Pacific (Tokyo)", "ap4" => "Asia Pacific (Sydney)"] as $code => $region}
                            <option value="{$code}" {if $data.system.realtime_cluster eq $code}selected{/if}>{$region} &mdash; {$code}</option>
                            {/foreach}
                        </select>
                    </div>

                    <div system-realtime-compatible{if $data.system.realtime_driver neq "compatible"} style="display: none;"{/if}>
                        <label>
                            {__("modal_adminsettings_realtimehost")}
                        </label>
                        <small class="text-muted d-block">
                            {__("modal_adminsettings_realtimehostinfo")}
                        </small>
                        <input type="text" name="realtime_host" class="form-control" value="{$data.system.realtime_host}">
                    </div>

                    <button type="button" class="btn btn-secondary btn-sm mt-3" system-realtime-test>{__("modal_adminsettings_realtimetest")}</button>
                </div>

                <div class="form-group col-md-6 mb-0">
                    <label>
                        {__("modal_adminsettings_realtimeappid")}
                    </label>
                    <input type="text" name="realtime_app_id" class="form-control" value="{$data.system.realtime_app_id}">

                    <label>
                        {__("modal_adminsettings_realtimekey")}
                        {if $data.system.realtime_key}<span class="badge badge-success">{__("modal_adminsettings_realtimeconfigured")}</span>{/if}
                    </label>
                    <input type="password" name="realtime_key" class="form-control" value="" autocomplete="new-password" placeholder="{if $data.system.realtime_key}{__("modal_adminsettings_realtimekeepblank")}{/if}">

                    <label>
                        {__("modal_adminsettings_realtimesecret")}
                        {if $data.system.realtime_secret}<span class="badge badge-success">{__("modal_adminsettings_realtimeconfigured")}</span>{/if}
                    </label>
                    <input type="password" name="realtime_secret" class="form-control" value="" autocomplete="new-password" placeholder="{if $data.system.realtime_secret}{__("modal_adminsettings_realtimekeepblank")}{/if}">
                </div>

                <div class="form-group mb-0 col-12">
                    <h2 class="text-uppercase">{__("form_settingssocialtitle")}</h2>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("form_settingssocial")} <i class="la la-info-circle la-lg" title="{__("and_settings_line336")}"></i>
                    </label>
                    <select name="social_auth" class="form-control">
                        <option value="1" {if __s("system_social_auth") < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if __s("system_social_auth") > 1}selected{/if}>{__("form_disable")}</option> 
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("form_settingssocialplatforms")} <i class="la la-info-circle la-lg" title="{__("and_settings_line346")}"></i>
                    </label>
                    <select name="social_platforms[]" class="form-control" multiple>
                        <option value="facebook" {if $data.platforms.facebook}selected{/if}>{__("and_settings_line349")}</option>
                        <option value="google" {if $data.platforms.google}selected{/if}>{__("and_settings_line350")}</option> 
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_settings_line357")} <i class="la la-info-circle la-lg" title="{__("and_settings_line357_1")}"></i>
                    </label>
                    <input type="text" name="facebook_id" class="form-control" placeholder="{__("and_settings_line359")}" value="{$data.system.facebook_id}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_settings_line364")} <i class="la la-info-circle la-lg" title="{__("and_settings_line364_1")}"></i>
                    </label>
                    <input type="text" name="facebook_secret" class="form-control" placeholder="{__("and_settings_line366")}" value="{$data.system.facebook_secret}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_settings_line371")} <i class="la la-info-circle la-lg" title="{__("and_settings_line371_1")}"></i>
                    </label>
                    <input type="text" name="google_id" class="form-control" placeholder="{__("and_settings_line373")}" value="{$data.system.google_id}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_settings_line378")} <i class="la la-info-circle la-lg" title="{__("and_settings_line378_1")}"></i>
                    </label>
                    <input type="text" name="google_secret" class="form-control" placeholder="{__("and_settings_line380")}" value="{$data.system.google_secret}">
                </div>

                <div class="form-group mb-0 col-12">
                    <h2 class="text-uppercase">{__("form_settingssecurity")}</h2>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_adminsettings_authredi")} <i class="la la-info-circle la-lg" title="{__("form_adminsettings_authredidesc")}"></i>
                    </label>
                    <select name="auth_redirect" class="form-control">
                        <option value="1" {if $data.system.auth_redirect < 2}selected{/if}>{__("form_adminsettings_authredihomepage")}</option>
                        <option value="2" {if $data.system.auth_redirect > 1}selected{/if}>{__("form_adminsettings_authredidashboard")}</option>  
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_systemsettingshomepage")} <i class="la la-info-circle la-lg" title="{__("and_settings_line100")}"></i>
                    </label>
                    <select name="homepage" class="form-control">
                        <option value="1" {if $data.system.homepage < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.system.homepage > 1}selected{/if}>{__("form_disable")}</option>  
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingsreg")} <i class="la la-info-circle la-lg" title="{__("and_settings_line70")}"></i>
                    </label>
                    <select name="registrations" class="form-control">
                        <option value="1" {if $data.system.registrations < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.system.registrations > 1}selected{/if}>{__("form_disable")}</option>  
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_adminsettings_defaultcoun")} <i class="la la-info-circle la-lg" title="{__("form_adminsettings_defaultcoundesc")}"></i>
                    </label>
                    <select name="default_country" class="form-control" data-live-search="true">
                        {foreach $data.countries as $country}
                        <option value="{$country@key}" data-tokens="{strtolower($country)}" {if $country@key eq __s("system_default_country")}selected{/if}>{$country} ({$country@key})</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_adminsettings_defaulttimezo")} <i class="la la-info-circle la-lg" title="{__("form_adminsettings_defaulttimezodesc")}"></i>
                    </label>
                    <select name="default_timezone" class="form-control" data-live-search="true">
                        {foreach $data.timezones as $timezone}
                        <option value="{strtolower($timezone)}" {if strtolower($timezone) eq __s("system_default_timezone")}selected{/if}>{strtoupper($timezone)}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_adminsettings_mailingtrig")} <i class="la la-info-circle la-lg" title="{__("form_adminsettings_mailingtrigdesc")}"></i>
                    </label>
                    <select name="mailing_triggers[]" class="form-control" data-live-search="true" system-select-mailing multiple>
                        <option value="0" {if in_array("0", split(__s("system_mailing_triggers"), ","))}selected{/if}>{__("form_disable")}</option>
                        <option value="register_confirm" {if in_array("register_confirm", split(__s("system_mailing_triggers"), ","))}selected{/if}>register_confirm</option>
                        <option value="admin_new_user" {if in_array("admin_new_user", split(__s("system_mailing_triggers"), ","))}selected{/if}>admin_new_user</option>  
                        <option value="admin_new_device" {if in_array("admin_new_device", split(__s("system_mailing_triggers"), ","))}selected{/if}>admin_new_device</option>  
                        <option value="admin_new_whatsapp" {if in_array("admin_new_whatsapp", split(__s("system_mailing_triggers"), ","))}selected{/if}>admin_new_whatsapp</option>  
                        <option value="admin_package_buy" {if in_array("admin_package_buy", split(__s("system_mailing_triggers"), ","))}selected{/if}>admin_package_buy</option>  
                        <option value="admin_credits_buy" {if in_array("admin_credits_buy", split(__s("system_mailing_triggers"), ","))}selected{/if}>admin_credits_buy</option>  
                        <option value="admin_voucher_redeem" {if in_array("admin_voucher_redeem", split(__s("system_mailing_triggers"), ","))}selected{/if}>admin_voucher_redeem</option>  
                        <option value="admin_payout_request" {if in_array("admin_payout_request", split(__s("system_mailing_triggers"), ","))}selected{/if}>admin_payout_request</option>  
                    </select>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_adminsettings_adminemail")} <i class="la la-info-circle la-lg" title="{__("form_adminsettings_adminemaildesc")}"></i>
                    </label>
                    <input type="text" name="mailing_address" class="form-control" placeholder="admin@email.com" value="{$data.system.mailing_address}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_adminsettings_recaptchatitle")} <i class="la la-info-circle la-lg" title="{__("form_adminsettings_recaptchadesc")}"></i>
                    </label>
                    <select name="recaptcha" class="form-control">
                        <option value="1" {if $data.system.recaptcha < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.system.recaptcha > 1}selected{/if}>{__("form_disable")}</option>  
                    </select>
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingsrecaptchakey")} <i class="la la-info-circle la-lg" title="{__("and_settings_line413")}"></i>
                    </label>
                    <input type="text" name="recaptcha_key" class="form-control" placeholder="{__("and_settings_line415")}" value="{$data.system.recaptcha_key}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_settingsrecaptchasecret")} <i class="la la-info-circle la-lg" title="{__("and_settings_line420")}"></i>
                    </label>
                    <input type="text" name="recaptcha_secret" class="form-control" placeholder="{__("and_settings_line422")}" value="{$data.system.recaptcha_secret}">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("system_settings_aimod_title")} <i class="la la-info-circle la-lg" title="{__("system_settings_aimod_desc")}"></i>
                    </label>
                    <select name="ai_moderation" class="form-control">
                        <option value="1" {if $data.system.ai_moderation < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.system.ai_moderation > 1}selected{/if}>{__("form_disable")}</option>  
                    </select>
                </div>

                <div class="form-group col-md-8">
                    <label>
                        {__("system_settings_aimod_apikey_title")} <i class="la la-info-circle la-lg" title="{__("system_settings_aimod_apikey_desc")}"></i>
                    </label>
                    <input type="text" name="openai_apikey" class="form-control" placeholder="eg. sk-proj-DOSWJC4GeNM5VcLcJILhyObypIrBvRdTChHq-2dJoo0A6fiaE2y4EF-Q0du_fRmsg2LUzz0vJ2T3BlbkFJ1l_KzyTIHwvWaQD8rUxTNydG4M4L" value="{$data.system.openai_apikey}">
                </div>

                <div class="form-group mb-0 col-12">
                    <h2 class="text-uppercase">{__("forms_systemsettings_messagestitle")}</h2>
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingsmessagemin")} <i class="la la-info-circle la-lg" title="{__("and_settings_line293")}"></i>
                    </label>
                    <input type="number" name="message_min" class="form-control" placeholder="{__("and_pack_line145")}" value="{$data.system.message_min}">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_settingsmessagemax")} <i class="la la-info-circle la-lg" title="{__("and_settings_line300")}"></i>
                    </label>
                    <input type="number" name="message_max" class="form-control" placeholder="{__("and_pack_line60")}" value="{$data.system.message_max}">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("forms_systemsettings_partnercommission")} <i class="la la-info-circle la-lg" title="{__("forms_systemsettings_partnercommissionhelp")}"></i>
                    </label>
                    <input type="number" name="partner_commission" class="form-control" placeholder="eg. 3" value="{$data.system.partner_commission}">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("forms_systemsettings_partnerminimum")} <i class="la la-info-circle la-lg" title="{__("forms_systemsettings_partnerminimumhelp")}"></i>
                    </label>
                    <input type="number" name="partner_minimum" class="form-control" placeholder="eg. 100" value="{$data.system.partner_minimum}">
                </div>

                <div class="form-group col-md-12">
                    <label>
                        {__("form_settingsmessagemark")} <i class="la la-info-circle la-lg" title="{__("and_settings_line307")}"></i>
                    </label>
                    <input type="text" name="message_mark" class="form-control" placeholder="{__("and_settings_line309")}" value="{$data.system.message_mark}">
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
                <i class="la la-check-circle la-lg"></i> {__("btn_save")}
            </button>
        </div>
    </div>
</form>