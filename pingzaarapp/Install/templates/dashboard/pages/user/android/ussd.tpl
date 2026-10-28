<div class="main-content" system-wrapper>
    {include "../../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col mb-2 mb-lg-0">
                        <h6 class="header-pretitle">
                            {__("and_dashboard_pages_android_line10")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-satellite-dish la-lg"></i> {__("and_droid_ussd_3")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary lift mb-2 mb-lg-0" title="{__("table_btn_refresh")}" system-action="refresh">
                            <i class="la la-refresh la-lg"></i>
                        </button>
                        <button class="btn btn-danger lift mb-2 mb-lg-0" title="{__("and_con_grp_6")}" system-trash="android.ussd">
                            <i class="la la-stream la-lg"></i>
                        </button>
                        <button class="btn btn-primary lift mb-2 mb-lg-0" title="{__("and_droid_ussd_14")}" system-toggle="add.ussd">
                            <i class="la la-satellite-dish la-lg"></i> {__("and_ussd_line47")}
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
                    <table class="table" system-table="android.ussd"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>