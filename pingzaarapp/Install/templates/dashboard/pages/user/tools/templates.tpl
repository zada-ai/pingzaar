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
                            <i class="la la-wrench la-lg"></i> {__("dashboard_messages_tabtemplatestitle")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary lift mb-2 mb-lg-0" system-toggle="add.template">
                            <i class="la la-wrench la-lg"></i> {__("dashboard_btn_addtemplate")}
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
                    <table class="table" system-table="tools.templates"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>