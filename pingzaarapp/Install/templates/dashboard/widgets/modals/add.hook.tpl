<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-wave-square la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group col-12">
                    <label>
                        {__("form_name")} <i class="la la-info-circle" title="{__("and_dash_auto_line17")}"></i>
                    </label>
                    <input type="text" name="name" class="form-control" placeholder="{__("and_dash_hook_line19")}">
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("and_dash_hook_line24")} <i class="la la-info-circle" title="{__("and_dash_hook_line24_1")}"></i>
                    </label>
                    <select name="source" class="form-control">
                        <option value="1">{__("and_dash_hook_line27")}</option>
                        <option value="2">{__("and_dash_hook_line28")}</option>
                    </select>
                </div>

                <div class="form-group col-md-6">
                    <label>
                        {__("form_hook_event")} <i class="la la-info-circle" title="{__("and_dash_hook_line34")}"></i>
                    </label>
                    <select name="event" class="form-control">
                        <option value="1">{__("table_toolsactions_eventonsend")}</option>
                        <option value="2">{__("table_toolsactions_eventonreceive")}</option>
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_hook_link")} <i class="la la-info-circle" title="{__("and_dash_hook_line44")}"></i>
                    </label>
                    <textarea name="link" rows="5" class="form-control">{literal}http://someremoteurl.com/test.php?phone={{phone}}&message={{message}}&time={{date.now}}{/literal}</textarea>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_shortcodes")} <i class="la la-info-circle" title="{__("and_dash_hook_line51")}"></i>
                    </label>
                    {literal}
                    <p>
                        <code>
                            <strong>{{phone}}</strong>, <strong>{{message}}</strong>, <strong>{{date.now}}</strong>, <strong>{{date.time}}</strong>
                        </code>
                    </p>
                    {/literal}
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