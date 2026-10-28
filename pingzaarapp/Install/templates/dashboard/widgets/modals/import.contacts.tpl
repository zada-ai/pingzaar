<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-file-excel la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_excelfile")} <i class="la la-info-circle" title="{__("and_import_line17")}"></i>
                    </label>
                    <small class="text-danger">
                        {__("and_import_line20")} <a href="{site_url("uploads/system/contacts_sample.xlsx")}" target="_blank">{__("btn_download")}</a>
                    </small>
                    <input type="file" name="excel" class="form-control pb-5">
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
                <i class="la la-check-circle la-lg"></i> {__("btn_submit")}
            </button>
        </div>
    </div>
</form>