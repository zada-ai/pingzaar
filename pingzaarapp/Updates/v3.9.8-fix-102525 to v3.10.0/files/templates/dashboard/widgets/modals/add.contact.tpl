<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-address-book la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_dash_con_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_edit_con_line19")}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_number")} <i class="la la-info-circle" title="{__("and_dash_con_line24")}"></i>
                    </label>
                    <input type="text" name="phone" class="form-control" placeholder="eg. {$data.number}">
                </div>
                
                <div class="form-group col-12">
                    <label>
                        {__("form_group")} <i class="la la-info-circle" title="{__("and_dash_con_line31")}"></i>
                    </label>
                    <select name="groups[]" class="form-control" data-live-search="true" multiple>
                        {foreach $data.groups as $group}
                        <option value="{$group@key}" data-tokens="{$group.token}">{$group.name}</option>
                        {/foreach}
                    </select>
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