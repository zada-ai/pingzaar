<div class="main-content" system-wrapper>
    {include "../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("and_dashboard_modules_header_line45")}
                        </h6>

                        <h1 class="header-title">
                            <i class="la la-chart-bar la-lg me-1"></i> {__("dashboard_overall_overview")}
                        </h1>
                    </div>

                    {if __s("super_admin")}
                    <div class="col-auto">
                        <button class="btn btn-primary lift mb-2 mb-lg-0" title="{__("admin_btncron_tooltip")}" system-toggle="setup.cron">
                            <i class="la la-broom la-lg"></i> {__("admin_btncron_btn")}
                        </button>

                        <button class="btn btn-primary lift mb-2 mb-lg-0" title="{__("pages_admin_cachehelp")}" system-action="clear">
                            <i class="la la-trash la-lg"></i> {__("administration_landing_btncache")}
                        </button>

                        <button class="btn btn-primary lift mb-2 mb-lg-0" title="{__("pages_admin_themehelp")}" system-toggle="admin.theme">
                            <i class="la la-palette la-lg"></i> {__("dashboard_btn_theme")}
                        </button>

                        <button class="btn btn-primary lift mb-2 mb-lg-0" title="{__("pages_admin_systemsettingshelp")}" system-toggle="admin.settings">
                            <i class="la la-cog la-lg"></i> {__("dashboard_btn_settings")}
                        </button>
                    </div>
                    {/if}
                </div> 
            </div> 
        </div>
    </div> 

    <div class="container">
        <div class="row">
            {if __s("super_admin")}
            <div class="col-md-12 col-xl-4">
                <div class="card">
                    <h4 class="card-header">
                        {__("admin_gateway_title")}
                    </h4>

                    <div class="card-body">
                        <h4 class="text-uppercase">
                            {__("admin_gateway_status")}: {if $data.gateway}<span class="badge badge-success">{__("admin_gateway_uploaded")}</span>{else}<span class="badge badge-danger">{__("admin_gateway_notuploaded")}</span>{/if}
                        </h4>

                        <h4 class="text-uppercase">
                            {__("and_admin_build_line49")}: <span class="badge badge-success text-lowercase">v{__s("system_apk_version")}</span>
                        </h4>
                    </div>

                    <div class="card-footer">
                        <button class="btn btn-primary btn-sm lift mb-2 mb-lg-0" system-toggle="admin.gateway">
                            <i class="la la-android la-lg"></i> {__("dashboard_btn_buildsettings")}
                        </button>
                    </div>
                </div>

                <div class="card">
                    <h4 class="card-header">
                        {__("administration_landing_system")}
                    </h4>

                    <div class="card-body">
                        <h4 class="text-uppercase">
                            {__("admin_update_installed")}: <span class="badge badge-success text-lowercase">v{version}</span>
                        </h4>
                    </div>

                    <div class="card-footer">
                        <button class="btn btn-primary btn-sm lift mb-2 mb-lg-0" system-toggle="system.update">
                            <i class="la la-terminal la-lg"></i> {__("admin_update_btn")}
                        </button>

                        <button class="btn btn-primary btn-sm lift mb-2 mb-lg-0" system-support>
                            <i class="la la-headset la-lg"></i>
                            <span class="d-none d-sm-inline">{__("dashboard_btn_support")}</span>
                        </button>
                    </div>
                </div>
            </div>
            {/if}

            <div class="col-md-12 {if __s("super_admin")}col-xl-8{/if}">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("dashboard_adminoverview_topcountries5")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.countries"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-md-12 col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("dashboard_adminoverview_browsers")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.browsers"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-md-12 col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("dashboard_adminoverview_os")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.os"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("administration_default_messages")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.messages"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-md-12 col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("administration_default_utilities")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.utilities"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-md-12 col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("administration_default_subscriptions")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.subscriptions"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-md-12 col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("administration_default_credits")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.credits"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-md-12 col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("pages_administration_commissionstitle")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("dashboard_admin_tabdefaultmessageslast")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0" system-iframe="{site_url}/widget/chart/admin.commissions"></iframe>
                    </div>
                </div>
            </div>
        </div> 

        {include "../../modules/footer.block.tpl"}
    </div>
</div>