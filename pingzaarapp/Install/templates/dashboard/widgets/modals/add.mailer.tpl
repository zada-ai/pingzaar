<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-chrome la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("table_adminmarketing_titletitle")} <i class="la la-info-circle" title="{__("and_dash_mail_line17_1")}"></i>
                    </label>
                    <input type="text" name="title" class="form-control" placeholder="{__("and_push_line19")}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_dash_mail_line24")} <i class="la la-info-circle" title="{__("and_dash_mail_line24_1")}"></i>
                    </label>
                    <select name="users[]" class="form-control" data-live-search="true" system-select-users multiple>
                        <option value="0" data-tokens="None {__("form_select_multinone")}" selected>{__("form_select_multinone")}</option>
                        {foreach $data.users as $user}
                        <option value="{$user.id}" data-tokens="{$user.name} {$user.email}">{$user.name} ({$user.email})</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("dashboard_admin_menuroles")} <i class="la la-info-circle" title="{__("and_dash_mail_line36_1")}"></i>
                    </label>
                    <select name="roles[]" class="form-control" data-live-search="true" system-select-roles multiple>
                        <option value="0" data-tokens="None {__("form_select_multinone")}" selected>{__("form_select_multinone")}</option>
                        {foreach $data.roles as $role}
                        <option value="{$role.id}" data-tokens="{$role.name}">{$role.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("and_dash_mail_line48")} <i class="la la-info-circle" title="{__("and_dash_mail_line48_1")}"></i>
                    </label>
                    <div system-codeflask><p>{__("and_dash_mail_line50")}</p></div>
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