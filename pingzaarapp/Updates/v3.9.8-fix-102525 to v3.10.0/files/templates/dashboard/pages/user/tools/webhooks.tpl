<div class="main-content" system-wrapper>
    {include "../../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("dashboard_tools_title")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-code-branch la-lg"></i> {__("dashboard_tools_tabhookstitle")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <a href="{site_url("dashboard/docs/webhooks")}" class="btn btn-primary lift mb-2 mb-lg-0" title="{__("and_tool_act_6")}" system-nav>
                            <i class="la la-book la-lg"></i> {__("and_tool_act_8")}
                        </a>
                        <button class="btn btn-primary lift mb-2 mb-lg-0" system-toggle="add.webhook">
                            <i class="la la-code-branch la-lg"></i> {__("dashboard_btn_addhook")}
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
                    <table class="table" system-table="tools.webhooks"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>