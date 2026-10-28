<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-ticket la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body pb-0">
            <div class="p-3">
                <div class="form-row">
                    <div class="form-group col-12">
                        <label>
                            {__("form_voucher")} <i class="la la-info-circle" title="{__("and_redeem_line18")}"></i>
                        </label>
                        <input type="text" name="code" class="form-control" placeholder="{__("and_redeem_line20")}">
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
                <i class="la la-check-circle la-lg"></i> {__("btn_redeem")}
            </button>
        </div>
    </div>
</form>