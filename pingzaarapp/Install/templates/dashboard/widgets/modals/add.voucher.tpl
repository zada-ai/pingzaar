<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-money-bill-wave la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_vouch_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_vouch_line19")}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_voucher_count")} <i class="la la-info-circle" title="{__("and_vouch_line24")}"></i> 
                    </label>
                    <input type="number" name="count" class="form-control" placeholder="{__("and_vouch_line26")}" min="1" value="1">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_subscriptionmonth")} <i class="la la-info-circle" title="{__("and_subs_line41")}"></i>
                    </label>
                    <input type="number" name="duration" class="form-control" placeholder="{__("and_subs_line43")}" min="1" value="1">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_voucher_package")} <i class="la la-info-circle" title="{__("and_subs_line28")}"></i>
                    </label>
                    <select name="package" class="form-control" data-live-search="true">
                        {foreach $data.packages as $package}
                            {if $package.id > 1}
                            <option value="{$package@key}" data-tokens="{strtolower($package.name)}">{$package.name}</option>
                            {/if}
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