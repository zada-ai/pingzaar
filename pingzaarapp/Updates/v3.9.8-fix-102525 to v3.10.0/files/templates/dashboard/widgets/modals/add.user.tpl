<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-user-plus la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_user_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_user_line19")}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_emailaddress")} <i class="la la-info-circle" title="{__("and_user_line24")}"></i>
                    </label>
                    <input type="text" name="email" class="form-control" placeholder="{__("and_user_line26")}">
                </div>
                
                <div class="form-group col-12">
                    <label>
                        {__("form_password")} <i class="la la-info-circle" title="{__("and_user_line31")}"></i>
                    </label>
                    <input type="text" name="password" class="form-control" placeholder="eg. {__("form_password")}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("user_settings_defthemecolortitle")} <i class="la la-info-circle" title="{__("user_settings_defthemecolortooltip")}"></i>
                    </label>
                    <select name="theme_color" class="form-control">
                        <option value="light" selected>{__("theme_settings_defthemecolorlight")}</option>
                        <option value="dark">{__("theme_settings_defthemecolordark")}</option>
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("form_usertimezone")} <i class="la la-info-circle" title="{__("and_user_line38")}"></i>
                    </label>
                    <select name="timezone" class="form-control" data-live-search="true">
                        {foreach $data.timezones as $timezone}
                        <option value="{strtolower($timezone)}">{strtoupper($timezone)}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("forms_edituser_clockformat")} <i class="la la-info-circle" title="{__("forms_edituser_clockformathelp")}"></i>
                    </label>
                    <select name="clock_format" class="form-control" data-live-search="true">
                        <option value="1" selected>{__("forms_edituser_clockformatselect1")}</option>
                        <option value="2">{__("forms_edituser_clockformatselect2")}</option>
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("forms_edituser_dateformat")} <i class="la la-info-circle" title="{__("forms_edituser_dateformathelp")}"></i>
                    </label>
                    <select name="date_format" class="form-control" data-live-search="true">
                        <option value="1" selected>MM-DD-YYYY</option>
                        <option value="2">DD-MM-YYYY</option>
                        <option value="3">YYYY-MM-DD</option>
                        <option value="4">YYYY-DD-MM</option>
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("forms_edituser_dateseparator")} <i class="la la-info-circle" title="{__("forms_edituser_dateseparatorhelp")}"></i>
                    </label>
                    <select name="date_separator" class="form-control" data-live-search="true">
                        <option value="1" selected>MM-DD-YYYY</option>
                        <option value="2">MM/DD/YYYY</option>
                        <option value="3">MM.DD.YYYY</option>
                        <option value="4">MM DD YYYY</option>
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("and_user_line49")} <i class="la la-info-circle" title="{__("and_user_line49_1")}"></i>
                    </label>
                    <select name="country" class="form-control" data-live-search="true">
                        {foreach $data.countries as $country}
                        <option value="{$country@key}" data-tokens="{strtolower($country)}">{$country} ({$country@key})</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("and_uset60")} <i class="la la-info-circle" title="{__("and_uset60_1")}"></i>
                    </label>
                    <select name="alertsound" class="form-control">
                        <option value="1" selected>{__("form_enable")}</option>
                        <option value="2">{__("form_disable")}</option>
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("form_adduser_role")} <i class="la la-info-circle" title="{__("and_user_line70")}"></i>
                    </label>
                    <select name="role" class="form-control">
                        {foreach $data.roles as $role}
                        <option value="{$role@key}">{$role.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("form_language")} <i class="la la-info-circle" title="{__("and_user_line81")}"></i>
                    </label>
                    <select name="language" class="form-control" data-live-search="true">
                        {foreach $data.languages as $language}
                        <option value="{$language@key}" data-tokens="{$language.token}">{$language.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("and_user_line92")} <i class="la la-info-circle" title="{__("and_user_line92_1")}"></i>
                    </label>
                    <select name="partner" class="form-control">
                        <option value="1">{__("form_enable")}</option>
                        <option value="2" selected>{__("form_disable")}</option>
                    </select>
                </div>

                <div class="form-group col-6">
                    <label>
                        {__("form_addusertpl_credits")} <i class="la la-info-circle" title="{__("form_addusertpl_creditsdesc")}"></i>
                    </label>
                    <input type="text" name="credits" class="form-control" placeholder="eg. 10">
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