<div class="main-content" system-wrapper>
    {include "../../../modules/header.block.tpl"}

    <div class="header">
        <div class="container">
            <div class="header-body">
                <div class="row align-items-end">
                    <div class="col">
                        <h6 class="header-pretitle">
                            {__("dashpages_sms_headertitle")}
                        </h6>
                        <h1 class="header-title">
                            <i class="la la-handshake la-lg"></i> {__("pages_smstransactions_header")}
                        </h1>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-body">
                <div class="alert alert-primary text-center">
                    {___(__("pages_smstransactions_comtagline"), [{__s("system_partner_commission")}])}
                </div>
                <div class="dt-responsive table-responsive">
                    <table class="table" system-table="sms.transactions"></table>
                </div>
            </div>
        </div>

        {include "../../../modules/footer.block.tpl"}
    </div>
</div>