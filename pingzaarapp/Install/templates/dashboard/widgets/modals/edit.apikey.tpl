<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-key la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_apikey_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_apikey_line19")}" value="{$data.key.name}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_permissions")} <i class="la la-info-circle" title="{__("and_apikey_line24")}"></i>
                    </label>
                    <select name="permissions[]" class="form-control" data-live-search="true" multiple>
                        {foreach $data.permissions as $permission}
                        <option value="{$permission}" data-tokens="{$permission}" {if in_array($permission, split($data.key.permissions, ","))}selected{/if}>{$permission}</option>
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