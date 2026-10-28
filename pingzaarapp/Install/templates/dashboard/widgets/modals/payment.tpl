<div class="modal-content">
    <div class="modal-header">
        <h3 class="modal-title">
            <i class="la la-credit-card la-lg"></i> {$title}
        </h3>

        <button type="button" class="close" data-dismiss="modal">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>

    <div class="modal-body">
        <div class="row" system-payments>
            {if __s("system_offline_payment") < 2}
            <div class="col-md-6">
                <button class="btn btn-white btn-payment-offline btn-block lift" system-toggle="view/bank-{$data.original_price}">
                    <i class="la la-money-check la-lg"></i> {__("modal_paymentbtn_banktransfernew")}
                </button>
            </div>
            {/if}
        </div>
    </div>
</div>