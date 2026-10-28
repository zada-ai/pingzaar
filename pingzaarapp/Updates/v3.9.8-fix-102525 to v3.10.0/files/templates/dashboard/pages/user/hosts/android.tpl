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
                            <i class="la la-android la-lg"></i> {__("and_usub104")}
                        </h1>
                    </div>
                    <div class="col-auto">
                        <a href="{site_url("dashboard/docs/android")}" class="btn btn-primary lift mb-2 mb-lg-0" title="{__("and_tool_act_6")}" system-nav>
                            <i class="la la-book la-lg"></i> {__("and_tool_act_8")}
                        </a>
                        {if $partner}
                        <a href="{site_url("dashboard/docs/partners")}" class="btn btn-primary lift mb-2 mb-lg-0" title="{__("and_droid_dev_11")}" system-nav>
                            <i class="la la-handshake la-lg"></i> {__("and_droid_dev_13")}
                        </a>
                        {/if}
                        <button class="btn btn-primary lift mb-2 mb-lg-0" title="{__("and_droid_dev_16")}" system-toggle="add.device">
                            <i class="la la-android la-lg"></i> {__("dashboard_btn_adddevice")}
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
                    <table class="table" system-table="android.devices"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>