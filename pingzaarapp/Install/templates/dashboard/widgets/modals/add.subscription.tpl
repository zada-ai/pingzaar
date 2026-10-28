<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-crown la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_addsubscriptionuser")} <i class="la la-info-circle" title="{__("and_subs_line17")}"></i>
                    </label>
                    <select name="user" class="form-control" data-live-search="true">
                        {foreach $data.users as $user}
                        <option value="{$user@key}" data-tokens="{$user.token}" data-subtext="{$user.email}">{$user.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addsubscriptionpackage")} <i class="la la-info-circle" title="{__("and_subs_line28")}"></i>
                    </label>
                    <select name="package" class="form-control" data-live-search="true">
                        {foreach $data.packages as $package}
                        {if $package.id > 1}
                        <option value="{$package@key}" data-tokens="{strtolower($package.name)}">{$package.name}</option>
                        {/if}
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_subscriptionmonth")} <i class="la la-info-circle" title="{__("and_subs_line41")}"></i>
                    </label>
                    <input type="number" name="duration" class="form-control" placeholder="{__("and_subs_line43")}" min="1" value="1">
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