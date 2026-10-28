<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-code la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("and_edit_short_line17")} <i class="la la-info-circle" title="{__("and_short_line17_1")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_short_line19")}" value="{$data.shortener.name}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("and_short_line24")} <i class="la la-info-circle" title="{__("and_short_line24_1")}"></i>
                    </label>
                    <small class="text-danger">
                        {__("and_short_line27")} <a href="https://github.com/titansys/zender-shorteners" target="_blank">{__("form_btnall_visitlink")}</a> 
                    </small>
                    <input type="file" name="controller" class="form-control pb-5">
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