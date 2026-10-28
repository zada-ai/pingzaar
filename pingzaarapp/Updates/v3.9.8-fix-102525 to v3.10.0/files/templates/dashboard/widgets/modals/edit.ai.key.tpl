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
                        {__("form_aikey_provider_title")} <i class="la la-info-circle" title="{__("form_aikey_provider_desc")}"></i>
                    </label>
                    <select name="provider" class="form-control" system-ai-provider>
                        <option value="openai" {if $data.key.provider eq "openai"}selected{/if}>OpenAI ({__("form_addaikey_providerrecommended")})</option>
                        <option value="geminiai" {if $data.key.provider eq "geminiai"}selected{/if}>GeminiAI</option>
                        <option value="claudeai" {if $data.key.provider eq "claudeai"}selected{/if}>ClaudeAI</option>
                        <option value="grokai" {if $data.key.provider eq "grokai"}selected{/if}>GrokAI</option>
                        <option value="deepseekai" {if $data.key.provider eq "deepseekai"}selected{/if}>DeepSeekAI</option>
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addaikey_initailprompt_title")} <i class="la la-info-circle" title="{__("form_addaikey_initailprompt_desc")}"></i>
                    </label>
                    <textarea name="prompt" rows="5" class="form-control" placeholder="{__("form_addaikey_initailprompt_placeholder")}">{$data.key.prompt}</textarea>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addaikey_postprompt_title")} <i class="la la-info-circle" title="{__("form_addaikey_postprompt_desc")}"></i>
                    </label>
                    <textarea name="post_prompt" rows="5" class="form-control" placeholder="{__("form_addaikey_postprompt_placeholder")}">{$data.key.post_prompt}</textarea>
                </div>

                <div class="form-group col-12" system-vision-ai>
                    <label>
                        {__("form_addaikey_vision_title")} <i class="la la-info-circle" title="{__("form_addaikey_vision_desc")}"></i>
                    </label>
                    <select name="vision" class="form-control">
                        <option value="1" {if $data.key.vision < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.key.vision > 1}selected{/if}>{__("form_disable")}</option>
                    </select>
                </div>

                <div class="form-group col-12" system-transcription-openai>
                    <label>
                        {__("form_addaiapikey_transcriptiontitle")} <i class="la la-info-circle" title="{__("form_addaiapikey_transcriptiondesc")}"></i>
                    </label>
                    <select name="transcription" class="form-control">
                        <option value="1" {if $data.key.transcription < 2}selected{/if}>{__("form_enable")}</option>
                        <option value="2" {if $data.key.transcription > 1}selected{/if}>{__("form_disable")}</option>
                    </select>
                </div>

                <div class="form-group col-12" system-models-openai>
                    <label>
                        {__("form_addaikey_model_title")} <i class="la la-info-circle" title="{__("form_addaikey_model_desc")}"></i>
                    </label>
                    <select name="model_openai" class="form-control">
                        <option value="gpt-5" data-content="gpt-5 <span class='badge badge-success'>{__("form_addaikey_modelsmartest")}</span>" {if $data.key.model eq "gpt-5"}selected{/if}>gpt-5 ({__("form_addaikey_modelsmartest")})</option>
                        <option value="gpt-5-pro" {if $data.key.model eq "gpt-5-pro"}selected{/if}>gpt-5-pro</option>
                        <option value="gpt-5-mini" {if $data.key.model eq "gpt-5-mini"}selected{/if}>gpt-5-mini</option>
                        <option value="gpt-5-nano" data-content="gpt-5-nano <span class='badge badge-warning'>{__("form_addaikey_modelcheapest")}</span>" {if $data.key.model eq "gpt-5-nano"}selected{/if}>gpt-5-nano ({__("form_addaikey_modelcheapest")})</option>
                        <option value="gpt-4.1" {if $data.key.model eq "gpt-4.1"}selected{/if}>gpt-4.1</option>
                        <option value="gpt-4.1-mini" {if $data.key.model eq "gpt-4.1-mini"}selected{/if}>gpt-4.1-mini</option>
                        <option value="gpt-4.1-nano" {if $data.key.model eq "gpt-4.1-nano"}selected{/if}>gpt-4.1-nano</option>
                    </select>
                </div>

                <div class="form-group col-12" system-models-geminiai>
                    <label>
                        {__("form_addaikey_model_title")} <i class="la la-info-circle" title="{__("form_addaikey_model_desc")}"></i>
                    </label>
                    <select name="model_geminiai" class="form-control">
                        <option value="gemini-2.5-pro" data-content="gemini-2.5-pro <span class='badge badge-success'>{__("form_addaikey_modelsmartest")}</span>" {if $data.key.model eq "gemini-2.5-pro"}selected{/if}>gemini-2.5-pro ({__("form_addaikey_modelsmartest")})</option>
                        <option value="gemini-2.5-flash" {if $data.key.model eq "gemini-2.5-flash"}selected{/if}>gemini-2.5-flash</option>
                        <option value="gemini-2.5-flash-lite" data-content="gemini-2.5-flash-lite <span class='badge badge-warning'>{__("form_addaikey_modelcheapest")}</span>" {if $data.key.model eq "gemini-2.5-flash-lite"}selected{/if}>gemini-2.5-flash-lite ({__("form_addaikey_modelcheapest")})</option>
                    </select>
                </div>

                <div class="form-group col-12" system-models-claudeai>
                    <label>
                        {__("form_addaikey_model_title")} <i class="la la-info-circle" title="{__("form_addaikey_model_desc")}"></i>
                    </label>
                    <select name="model_claudeai" class="form-control">
                        <option value="claude-opus-4-1" data-content="claude-opus-4-1" {if $data.key.model eq "claude-opus-4-1"}selected{/if}>claude-opus-4-1</option>
                        <option value="claude-opus-4-0" data-content="claude-opus-4-0" {if $data.key.model eq "claude-opus-4-0"}selected{/if}>claude-opus-4-0</option>
                        <option value="claude-sonnet-4-5" data-content="claude-sonnet-4-5" {if $data.key.model eq "claude-sonnet-4-5"}selected{/if}>claude-sonnet-4-5</option>
                        <option value="claude-sonnet-4-0" data-content="claude-sonnet-4-0" {if $data.key.model eq "claude-sonnet-4-0"}selected{/if}>claude-sonnet-4-0</option>
                    </select>
                </div>

                <div class="form-group col-12" system-models-grokai>
                    <label>
                        {__("form_addaikey_model_title")} <i class="la la-info-circle" title="{__("form_addaikey_model_desc")}"></i>
                    </label>
                    <select name="model_grokai" class="form-control">
                        <option value="grok-3" data-content="grok-3 <span class='badge badge-success'>{__("form_addaikey_modelsmartest")}</span> <span class='badge badge-danger'>{__("form_addaikey_visonunsupported")}</span>" {if $data.key.model eq "grok-3"}selected{/if}>grok-3 ({__("form_addaikey_modelsmartest")}) ({__("form_addaikey_visonunsupported")})</option>
                        <option value="grok-3-mini" data-content="grok-3-mini <span class='badge badge-warning'>{__("form_addaikey_modelcheapest")}</span> <span class='badge badge-danger'>{__("form_addaikey_visonunsupported")}</span>" {if $data.key.model eq "grok-3-mini"}selected{/if}>grok-3-mini ({__("form_addaikey_modelcheapest")}) ({__("form_addaikey_visonunsupported")})</option>
                    </select>
                </div>

                <div class="form-group col-12" system-models-deepseekai>
                    <label>
                        {__("form_addaikey_model_title")} <i class="la la-info-circle" title="{__("form_addaikey_model_desc")}"></i>
                    </label>
                    <select name="model_deepseekai" class="form-control">
                        <option value="deepseek-chat" data-content="deepseek-chat <span class='badge badge-danger'>{__("form_addaikey_visonunsupported")}</span>" {if $data.key.model eq "deepseek-chat"}selected{/if}>deepseek-chat ({__("form_addaikey_visonunsupported")})</option>
                    </select>
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addaikey_maxtokens")} <i class="la la-info-circle" title="{__("form_addaikey_maxtokens_desc")}"></i>
                    </label>
                    <input type="number" name="max_tokens" class="form-control" placeholder="{__("form_addaikey_historythreshold_placeholder")}" value="{$data.key.max_tokens}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addaikey_historythreshold_title")} <i class="la la-info-circle" title="{__("form_addaikey_historythreshold_desc")}"></i>
                    </label>
                    <input type="number" name="history" class="form-control" placeholder="{__("form_addaikey_historythreshold_placeholder")}" value="{$data.key.history}">
                </div>

                <div class="form-group col-12">
                    <label>
                        {__("form_addaikey_apikey_title")} <i class="la la-info-circle" title="{__("form_addaikey_apikey_desc")}"></i>
                    </label>
                    <input type="text" name="apikey" class="form-control" placeholder="eg. sk-proj-DOSWJC4GeNM5VcLcJILhyObypIrBvRdTChHq-2dJoo0A6fiaE2y4EF" value="{$data.key.apikey}">
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