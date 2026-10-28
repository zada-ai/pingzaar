<div class="main-content" system-wrapper>
    {include "../../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("dashpages_sms_headertitle")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-clock la-lg"></i> {__("messages_scheduled_title")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary lift" title="{__("modals_addnewsmssched_tooltip")}" system-toggle="add.sms.scheduled">
                            <i class="la la-calendar me-1"></i> {__("messages_scheduled_schedule")}
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
                    <table class="table table-striped" system-table="sms.scheduled"></table>
                </div>
            </div>
        </div>
        {include "../../../modules/footer.block.tpl"}
    </div>
</div>