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
                            <i class="la la-key la-lg"></i> {__("dashboard_tools_tabkeystitle")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <a href="{site_url("dashboard/docs")}" class="btn btn-primary lift" title="{__("and_tool_act_6")}" system-nav>
                            <i class="la la-book la-lg"></i> {__("and_tool_act_8")}
                        </a>
                        <button class="btn btn-primary lift" title="{__("and_tool_key_11")}" system-toggle="add.apikey">
                            <i class="la la-key la-lg"></i> {__("dashboard_btn_addkey")}
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
                    <table class="table" system-table="tools.keys"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>