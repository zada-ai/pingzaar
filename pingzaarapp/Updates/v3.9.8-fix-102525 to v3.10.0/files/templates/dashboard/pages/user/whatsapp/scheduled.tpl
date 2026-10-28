<div class="main-content" system-wrapper>
    {include "../../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("and_dash_pg_whats_line10")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-clock la-lg"></i> {__("messages_scheduled_title")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary lift" title="{__("modals_addnewwasched_tooltip")}" system-toggle="add.whatsapp.scheduled">
                            <i class="la la-calendar la-lg"></i> {__("widget_wascheduled_title")}
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
                    <table class="table table-striped" system-table="whatsapp.scheduled"></table>
                </div>
            </div>
        </div>
        {include "../../../modules/footer.block.tpl"}
    </div>
</div>