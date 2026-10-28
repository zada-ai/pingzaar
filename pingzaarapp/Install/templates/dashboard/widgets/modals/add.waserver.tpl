<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-whatsapp la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-md-5">
                    <label>
                        {__("form_waserver_addeditserver_name")} <i class="la la-info-circle la-lg" title="{__("form_waserver_addeditserver_nametagline")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="eg. Free Server">
                </div>

                <div class="form-group col-md-3">
                    <label>
                        {__("form_waserver_addeditserver_accounts")} <i class="la la-info-circle la-lg" title="{__("form_waserver_addeditserver_accountstagline")}"></i>
                    </label>
                    <input type="number" name="accounts" class="form-control" placeholder="50">
                </div>
                
                <div class="form-group col-md-4">
                    <label>
                        {__("form_waserver_addeditserver_packages")} <i class="la la-info-circle la-lg" title="{__("form_waserver_addeditserver_packagestagline")}"></i>
                    </label>
                    <select name="packages[]" class="form-control" data-live-search="true" multiple>
                        {foreach $data.packages as $package}
                        <option value="{$package.id}" {if $package@index < 1}selected{/if}>{$package.name}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-md-8">
                    <label>
                        {__("form_waserver_addeditserver_serverurl")} <i class="la la-info-circle la-lg" title="{__("forms_systemsettings_waserverhelp")}"></i>
                    </label>
                    <input type="text" name="url" class="form-control" placeholder="eg. http://127.0.0.1">
                </div>

                <div class="form-group col-md-4">
                    <label>
                        {__("form_waserver_addeditserver_serverport")} <i class="la la-info-circle la-lg" title="{__("forms_systemsettings_waporthelp")}"></i>
                    </label>
                    <input type="text" name="port" class="form-control" placeholder="eg. 7001">
                </div>

                <div class="form-group col-md-12">
                    <label>
                        {__("form_waserver_secretkey")} <i class="la la-info-circle la-lg" title="{__("form_waserver_secretkeydesc_386")}"></i>
                    </label>
                    <input type="text" name="secret" class="form-control" placeholder="eg. Xg40V0ynJscNzZywSU8MwpPjaCi2BC">
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