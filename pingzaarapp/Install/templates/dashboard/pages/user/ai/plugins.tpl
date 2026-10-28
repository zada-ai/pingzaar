<div class="main-content" system-wrapper>
    {include "../../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("template_sidebar_ai")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-plug la-lg"></i> {__("template_sidebar_ai_plugins")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary lift" title="{__("dasboard_aipage_addpluginbtn")}" system-toggle="add.ai.plugin">
                            <i class="la la-plug la-lg"></i> {__("dasboard_aipage_addpluginbtn")}
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
                    <table class="table" system-table="ai.plugins"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>