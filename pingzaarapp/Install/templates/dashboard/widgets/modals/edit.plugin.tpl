<form system-form>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="la la-list la-lg"></i> {$title}
            </h3>

            <button type="button" class="close" data-dismiss="modal">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="form-row">
                {foreach $data.fields as $field}
                <div class="form-group {if in_array($field.type, ["text", "select"])}col-md-6{else}col-md-12{/if}">
                    <label>
                        {ucfirst($field.label)} <i class="la la-info-circle la-lg" title="{$field.description}"></i>
                    </label>
                    {if $field.type eq "select"}
                    <select name="{$field@key}" class="form-control">
                        {foreach $field.options as $option}
                        <option value="{$option@key}" {if $field.value eq $option@key}selected{/if}>{$option@value}</option>
                        {/foreach}
                    </select>
                    {else}
                    <input type="text" name="{$field@key}" class="form-control {if isset($field.readonly) and $field.readonly eq "true"}text-muted{/if}" placeholder="{ucfirst($field.label)}" value="{$field.value}" {if isset($field.readonly) and $field.readonly eq "true"}disabled{/if}>
                    {/if}
                </div>
                {/foreach}
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary">
                <i class="la la-check-circle la-lg"></i> {__("btn_submit")}
            </button>
        </div>
    </div>
</form>