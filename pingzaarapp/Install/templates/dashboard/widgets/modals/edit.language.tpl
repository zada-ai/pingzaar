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
                <div class="form-group col-4">
                    <label>{__("form_name")}</label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_dash_lang_line19")}" value="{$data.language.name}">
                </div>

                <div class="form-group col-4">
                    <label>{__("form_countrycode")}</label>
                    <select name="iso" class="form-control" data-live-search="true">
                        {foreach $data.countries as $country}
                        <option value="{$country@key}" data-tokens="{strtolower($country)}" {if $country@key eq $data.language.iso}selected{/if}>{$country@key}</option>
                        {/foreach}
                    </select>
                </div>

                <div class="form-group col-4">
                    <label>{__("and_dash_lang_line35")}</label>
                    <select name="rtl" class="form-control">
                        <option value="1" {if $data.language.rtl < 2}selected{/if}>{__("and_edit_lang_line32")}</option>
                        <option value="2" {if $data.language.rtl > 1}selected{/if}>{__("and_edit_lang_line33")}</option>
                    </select>
                </div>
                
                <div class="form-group col-12">
                    <label>{__("form_translations")}</label>
                    <textarea name="translations" class="form-control" cols="100" rows="10" placeholder="{__("form_translations_placeholder")}">{$data.strings}</textarea>
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