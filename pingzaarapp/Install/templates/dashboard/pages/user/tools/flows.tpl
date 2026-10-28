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
                            <i class="la la-project-diagram la-lg"></i> {__("flow_page_title")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <a href="{site_url("flow")}" target="_blank" class="btn btn-primary lift" title="{__("flow_page_createbtn")}">
                            <i class="la la-project-diagram la-lg"></i> {__("flow_page_createbtn")}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-body">
                <div class="dt-responsive table-responsive">
                    <table class="table" system-table="tools.flows"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>