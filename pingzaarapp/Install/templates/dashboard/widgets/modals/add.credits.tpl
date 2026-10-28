<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-coins la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="p-3 pl-5 pr-5">
                <div class="form-row">
                    <div class="input-group col-12">
                        <input type="number" class="form-control" placeholder="eg. 12" min="10" value="10" system-credits>
                        <div class="input-group-append">
                            <span class="input-group-text">{strtoupper(system_currency)}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn-primary btn-block" system-toggle="payment/credits/10" system-loader="Processing request" system-credits-button>
                <i class="la la-check-circle la-lg"></i> {__("and_dash_cre_line28")}
            </button>
        </div>
    </div>
</form>