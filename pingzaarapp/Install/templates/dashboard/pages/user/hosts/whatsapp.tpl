<div class="main-content" system-wrapper>
    {include "../../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("dashboard_userhosts_hostbreadcrumb")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-whatsapp la-lg"></i> {__("tabs_whatsappaccounts_titleheader")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary lift" title="{__("table_btn_refresh")}" system-action="refresh">
                            <i class="la la-refresh la-lg"></i>
                        </button>
                        <button class="btn btn-primary lift" title="{__("and_what_accnt_6")}" relink-unique="none" wa-link-url="link" wa-link-title="{__("widget_waaddaccount_title")}" system-toggle="add.whatsapp">
                            <i class="la la-whatsapp la-lg"></i> {__("widget_waaddaccount_title")}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-body">
                <div class="dt-responsive table-responsive">
                    <table class="table" system-table="whatsapp.accounts"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>