<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-shield la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_role_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_role_line19")}">
                </div>
                
                <div class="form-group col-12">
                    <label>
                        {__("form_permissions")} <i class="la la-info-circle" title="{__("and_role_line24")}"></i>
                    </label>
                    {* Built from permission_list() rather than hardcoded, so a new
                       permission cannot be checked in a controller yet be missing
                       here — which is how manage_waservers and manage_widgets ended
                       up ungrantable. *}
                    <select name="permissions[]" class="form-control" data-live-search="true" multiple>
                        {foreach $data.permissions as $permission}
                        <option value="{$permission}" {if in_array($permission, ["manage_users", "manage_roles"])}selected{/if}>{$permission}</option>
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