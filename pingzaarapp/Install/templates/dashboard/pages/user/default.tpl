<div class="main-content" system-wrapper>
    {include "../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("dashboard_title_default")}
                        </h6>

                        <h1 class="header-title">
                            <i class="la la-chart-bar la-lg me-1"></i> {__("dashboard_overall_overview")}
                        </h1>
                    </div>
    
                    <div class="col-auto">
                        <a class="btn btn-primary lift mb-2 mb-lg-0 {if !in_array("sms", split($subscription.services, ","))}d-none{/if}" href="{site_url("dashboard/misc/rates.gateways")}" system-nav>
                            <i class="la la-comments-dollar la-lg me-1"></i> {__("dashboard_overview_gatewaysbtn")}
                        </a>

                        <a class="btn btn-primary lift mb-2 mb-lg-0 {if !in_array("sms", split($subscription.services, ","))}d-none{/if}" href="{site_url("dashboard/misc/rates.partners")}" system-nav>
                            <i class="la la-handshake la-lg me-1"></i> {__("and_dash_pg_rates_line39")}
                        </a>
                    </div>
                </div> 
            </div> 
        </div>
    </div> 

    <div class="container">
        <div class="row">
            <div class="col-12 col-lg-6 col-xl">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="text-uppercase text-muted mb-2">
                                    {__("dashboard_overview_user_totalsent")}
                                </h6>

                                <span class="h2 mb-0">
                                    {$data.total.sent}
                                </span>
                            </div>
                            <div class="col-auto">
                                <span class="h2 la la-telegram la-lg text-muted mb-0"></span>
                            </div>
                        </div> 
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6 col-xl">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="text-uppercase text-muted mb-2">
                                    {__("dashboard_overview_user_totalreceived")}
                                </h6>

                                <span class="h2 mb-0">
                                    {$data.total.received}
                                </span>
                            </div>

                            <div class="col-auto">
                                <span class="h2 la la-comment la-lg text-muted mb-0"></span>
                            </div>
                        </div> 
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6 col-xl">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="text-uppercase text-muted mb-2">
                                    {__("and_dash_not_line35")}
                                </h6>

                                <span class="h2 mb-0">
                                    {$data.ratio.success}%
                                </span>
                            </div>

                            <div class="col-auto">
                                <span class="h2 fe fe-trending-up text-success mb-0"></span>
                            </div>
                        </div> 
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6 col-xl">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="text-uppercase text-muted mb-2">
                                    {__("and_dash_pg_def_line190")}
                                </h6>

                                <span class="h2 mb-0">
                                    {$data.ratio.failed}%
                                </span>
                            </div>
                            <div class="col-auto">
                                <span class="h2 fe fe-trending-down text-danger mb-0"></span>
                            </div>
                        </div> 
                    </div>
                </div>

            </div>
        </div> 
        <div class="row">
            <div class="col-12 col-xl-9">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("and_dashboard_pages_default_line58")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("and_dashboard_pages_default_line60")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0"
                            system-iframe="{site_url}/widget/chart/dashboard.messages"></iframe>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("table_tootlswebhooks_eventstitle")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("and_dashboard_pages_default_line60")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0"
                            system-iframe="{site_url}/widget/chart/dashboard.events"></iframe>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-header-title">
                            {__("administration_default_utilities")}
                        </h4>

                        <span class="text-muted me-3">
                            {__("and_dashboard_pages_default_line60")}
                        </span>
                    </div>

                    <div class="card-body">
                        <iframe class="w-100 border-0"
                            system-iframe="{site_url}/widget/chart/dashboard.utilities"></iframe>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-3">
                {if $partner}
                <div class="card">
                    <h3 class="card-header">
                        {__("and_dash_pg_rates_line39")}
                    </h3>

                    <div class="card-body">
                        <h4 class="card-text">
                            {number_format($data.balance.earnings, 2)} {strtoupper(system_currency)}
                            <span class="badge bg-primary-soft">{__("and_dashboard_pages_default_line117")}</span>
                        </h4>
                    </div>

                    <div class="card-footer">
                        <button class="btn btn-primary lift btn-sm" system-toggle="add.payout">
                            <i class="la la-money-check-alt me-1"></i> {__("and_dashboard_pages_default_line131")}
                        </button>
                    </div>
                </div>
                {/if}

                <div class="card">
                    <h3 class="card-header">
                        {__("and_dashboard_pages_default_line140")}
                    </h3>

                    <div class="card-body">
                        <h4 class="card-text">
                            {number_format($data.balance.credits, 2)} {strtoupper(system_currency)}
                            <span class="badge bg-primary-soft">{__("and_dashboard_pages_default_line148")}</span>
                        </h4>
                    </div>

                    <div class="card-footer">
                        <button class="btn btn-primary lift btn-sm" system-toggle="add.credits">
                            <i class="la la-coins me-1"></i> {__("and_dashboard_pages_default_line162")}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {include "../../modules/footer.block.tpl"}
    </div>

</div>