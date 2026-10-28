<!DOCTYPE html>
<html lang="en" {if language_rtl}dir="rtl"{/if}>

<head>
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="description" content="{__s("system_site_desc")}">
    <link rel="icon" href="{get_image("favicon")}">

    <title>{$title} &middot; {__s("system_site_name")}</title>

    <link rel="stylesheet" href="{_assets("css/libs/line-awesome.min.css")}">
    <link rel="stylesheet" href="{_assets("css/libs/drawflow.min.css")}">
    <link rel="stylesheet" href="{assets("css/flow.css")}">

    <script>
        {if $data.flow}
            window.flow_id = "{$data.flow.id}";
            window.dataToImport = {$data.flow.flow_data};
        {else}
            {literal}
                window.flow_id = null;
                window.dataToImport = {};
            {/literal}
        {/if}
    </script>
</head>

<body>
    <div id="uploadModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="las la-cloud-upload-alt"></i> {__("flow_modal_upload_title")}</h3>
                <span class="close" id="closeModal">&times;</span>
            </div>
            <div class="modal-body">
                <div class="upload-area">
                    <input type="file" id="fileInput" style="display: none;">
                    <button id="uploadButton" class="btn-upload">
                        <i class="las la-cloud-upload-alt"></i> {__("flow_modal_upload_button_select")}
                    </button>
                    <div id="fileInfo" class="file-info" style="display: none;"></div>
                </div>
                <div id="uploadProgress" class="upload-progress" style="display: none;">
                    <div class="progress-bar">
                        <div id="progressBar" class="progress"></div>
                    </div>
                    <div id="progressText" class="progress-text">{__("flow_modal_upload_progress_text")}</div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="startUpload" class="btn-primary" disabled>{__("flow_modal_upload_button_upload")}</button>
            </div>
        </div>
    </div>

    <div class="wrapper">
        <div class="col">
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="sms">
                <i class="las la-sms"></i><span> {__("flow_draw_sms_source")}</span>
            </div>
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="whatsapp">
                <i class="lab la-whatsapp"></i><span> {__("flow_draw_whatsapp_source")}</span>
            </div>
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="condition">
                <i class="las la-filter"></i><span> {__("flow_draw_condition")}</span>
            </div>
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="ai_reply">
                <i class="las la-robot"></i><span> {__("flow_draw_ai_reply")}</span>
            </div>
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="send_text">
                <i class="las la-comment-alt"></i><span> {__("flow_draw_send_text")}</span>
            </div>
            <div class="drag-drawflow whatsapp-only" draggable="true" ondragstart="drag(event)" data-node="send_image">
                <i class="las la-image"></i><span> {__("flow_draw_send_image")}</span>
            </div>
            <div class="drag-drawflow whatsapp-only" draggable="true" ondragstart="drag(event)" data-node="send_video">
                <i class="las la-video"></i><span> {__("flow_draw_send_video")}</span>
            </div>
            <div class="drag-drawflow whatsapp-only" draggable="true" ondragstart="drag(event)" data-node="send_audio">
                <i class="las la-volume-up"></i><span> {__("flow_draw_send_audio")}</span>
            </div>
            <div class="drag-drawflow whatsapp-only" draggable="true" ondragstart="drag(event)"
                data-node="send_document">
                <i class="las la-file"></i><span> {__("flow_draw_send_document")}</span>
            </div>
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="save_contact">
                <i class="las la-address-book"></i><span> {__("flow_draw_save_contact")}</span>
            </div>
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="pause">
                <i class="las la-pause"></i><span> {__("flow_draw_pause")}</span>
            </div>
            <div class="drag-drawflow" draggable="true" ondragstart="drag(event)" data-node="resume">
                <i class="las la-play"></i><span> {__("flow_draw_resume")}</span>
            </div>
        </div>
        <div class="col-right">
            <div id="drawflow" ondrop="drop(event)" ondragover="allowDrop(event)">
                <div class="btn-export" onclick="exportFlow()">{__("flow_draw_button_save")}</div>
                <div class="btn-clear" onclick="clearFlow()">{__("flow_draw_button_clear")}</div>

                <div class="bar-zoom">
                    <i class="las la-search-minus" onclick="editor.zoom_out()" title="{__('flow_draw_zoom_out')}"></i>
                    <i class="las la-search" onclick="editor.zoom_reset()" title="{__('flow_draw_zoom_reset')}"></i>
                    <i class="las la-search-plus" onclick="editor.zoom_in()" title="{__('flow_draw_zoom_in')}"></i>
                </div>
            </div>
        </div>
    </div>

    <script src="{_assets("js/libs/fetch.min.js")}"></script>
    <script defer>
        window.site_url = "{__s("site_url")}";
        window.template = "{__s("template")}";
        window.tawk_id = "{__s("system_tawk_id")}";
        window.alertsound = {if __s("logged_alertsound", 1) < 2}true{else}false{/if};
        window.color_primary = "{__s("system_theme_background")}";
        window.alert_position = "{if __s("language_rtl")}topRight{else}topLeft{/if}";
        window.overlap_alert_position = "{if __s("language_rtl")}bottomRight{else}bottomLeft{/if}";
        window.consent_position = "{if __s("language_rtl")}bottom-right{else}bottom-left{/if}";
        window.theme_color = "light";

        var lang_response_went_wrong = "{__('response_went_wrong')}",
            lang_alert_attention = "{__('alert_attention')}",
            lang_cookieconsent_message = "{__('cookieconsent_message')}",
            lang_cookieconsent_link = "{__('cookieconsent_link')}",
            lang_cookieconsent_dismiss = "{__('cookieconsent_dismiss')}",
            lang_js_processing_dataloader = "{__('js_processing_dataloader')}",
            lang_js_loader_pleasewait = "{__('js_loader_pleasewait')}",
            lang_response_session_false = "{__('response_session_false')}",
            lang_flowjs_fetching_data = "{__('flowjs_fetching_data')}",
            lang_flowjs_failedload_data = "{__('flowjs_failedload_data')}",
            lang_flowjs_flowbuilder_guidetitle = "{__('flowjs_flowbuilder_guidetitle')}",
            lang_flowjs_flowbuilder_guide1 = "{__('flowjs_flowbuilder_guide1')}",
            lang_flowjs_flowbuilder_guide2 = "{__('flowjs_flowbuilder_guide2')}",
            lang_flowjs_flowbuilder_guide3 = "{__('flowjs_flowbuilder_guide3')}",
            lang_flowjs_flowbuilder_note = "{__('flowjs_flowbuilder_note')}",
            lang_flowjs_node_smstitle = "{__('flowjs_node_smstitle')}",
            lang_flowjs_node_smsdevicesel = "{__('flowjs_node_smsdevicesel')}",
            lang_flowjs_node_textexample = "{__('flowjs_node_textexample')}",
            lang_flowjs_node_texttitle = "{__('flowjs_node_texttitle')}",
            lang_flowjs_node_textinputtitle = "{__('flowjs_node_textinputtitle')}",
            lang_flowjs_node_textinputplaholder = "{__('flowjs_node_textinputplaholder')}",
            lang_flowjs_node_textpriority = "{__('flowjs_node_textpriority')}",
            lang_flowjs_node_yes = "{__('flowjs_node_yes')}",
            lang_flowjs_node_no = "{__('flowjs_node_no')}",
            lang_flowjs_node_watitle = "{__('flowjs_node_watitle')}",
            lang_flowjs_node_waselect = "{__('flowjs_node_waselect')}",
            lang_flowjs_node_savecontact = "{__('flowjs_node_savecontact')}",
            lang_flowjs_node_contactgroup = "{__('flowjs_node_contactgroup')}",
            lang_flowjs_node_nocondition = "{__('flowjs_node_nocondition')}",
            lang_flowjs_node_nosmsmedia = "{__('flowjs_node_nosmsmedia')}",
            lang_flowjs_node_wamediaonly = "{__('flowjs_node_wamediaonly')}",
            lang_flowjs_node_onlyonesource = "{__('flowjs_node_onlyonesource')}",
            lang_flowjs_node_condition = "{__('flowjs_node_condition')}",
            lang_flowjs_node_conditiontype = "{__('flowjs_node_conditiontype')}",
            lang_flowjs_node_condition1 = "{__('flowjs_node_condition1')}",
            lang_flowjs_node_condition2 = "{__('flowjs_node_condition2')}",
            lang_flowjs_node_condition3 = "{__('flowjs_node_condition3')}",
            lang_flowjs_node_condition4 = "{__('flowjs_node_condition4')}",
            lang_flowjs_node_condition5 = "{__('flowjs_node_condition5')}",
            lang_flowjs_node_condition6 = "{__('flowjs_node_condition6')}",
            lang_flowjs_node_conditionval = "{__('flowjs_node_conditionval')}",
            lang_flowjs_node_conditionvalph = "{__('flowjs_node_conditionvalph')}",
            lang_flowjs_node_imagetitle = "{__('flowjs_node_imagetitle')}",
            lang_flowjs_node_imageurl = "{__('flowjs_node_imageurl')}",
            lang_flowjs_node_imageurlph = "{__('flowjs_node_imageurlph')}",
            lang_flowjs_node_uploadbtn = "{__('flowjs_node_uploadbtn')}",
            lang_flowjs_node_caption = "{__('flowjs_node_caption')}",
            lang_flowjs_node_captionph = "{__('flowjs_node_captionph')}",
            lang_flowjs_node_video = "{__('flowjs_node_video')}",
            lang_flowjs_node_videourl = "{__('flowjs_node_videourl')}",
            lang_flowjs_node_videoph = "{__('flowjs_node_videoph')}",
            lang_flowjs_node_audio = "{__('flowjs_node_audio')}",
            lang_flowjs_node_audiourl = "{__('flowjs_node_audiourl')}",
            lang_flowjs_node_audioph = "{__('flowjs_node_audioph')}",
            lang_flowjs_node_documennt = "{__('flowjs_node_documennt')}",
            lang_flowjs_node_documenturl = "{__('flowjs_node_documenturl')}",
            lang_flowjs_node_documentph = "{__('flowjs_node_documentph')}",
            lang_flowjs_upload_uploading = "{__('flowjs_upload_uploading')}",
            lang_flowjs_upload_failed = "{__('flowjs_upload_failed')}",
            lang_flowjs_export_addsourceflow = "{__('flowjs_export_addsourceflow')}",
            lang_flowjs_export_sourcemustconnode = "{__('flowjs_export_sourcemustconnode')}",
            lang_flowjs_export_nodesmustconnctflow = "{__('flowjs_export_nodesmustconnctflow')}",
            lang_flowjs_export_mustselsmssource = "{__('flowjs_export_mustselsmssource')}",
            lang_flowjs_export_mustselwasource = "{__('flowjs_export_mustselwasource')}",
            lang_flowjs_export_savingflow = "{__('flowjs_export_savingflow')}",
            lang_flowjs_export_failedsave = "{__('flowjs_export_failedsave')}",
            lang_flowjs_node_aireply = "{__('flowjs_node_aireply')}",
            lang_flowjs_node_aikey = "{__('flowjs_node_aikey')}",
            lang_flowjs_node_aiplugin = "{__('flowjs_node_aiplugin')}",
            lang_flowjs_node_none = "{__('flowjs_node_none')}",
            lang_flowjs_node_pause = "{__('flowjs_node_pause')}",
            lang_flowjs_node_resume = "{__('flowjs_node_resume')}",
            lang_flowjs_node_pause_duration = "{__('flowjs_node_pause_duration')}",
            lang_flowjs_node_pause_duration_placeholder = "{__('flowjs_node_pause_duration_placeholder')}",
            lang_flowjs_node_pause_duration_help = "{__('flowjs_node_pause_duration_help')}",
            lang_flowjs_node_pause_context = "{__('flowjs_node_pause_context')}",
            lang_flowjs_node_resume_context = "{__('flowjs_node_resume_context')}",
            lang_flowjs_node_context_flow = "{__('flowjs_node_context_flow')}",
            lang_flowjs_node_context_autoreply = "{__('flowjs_node_context_autoreply')}",
            lang_flowjs_node_context_all = "{__('flowjs_node_context_all')}",
            lang_flowjs_node_pause_description = "{__('flowjs_node_pause_description')}",
            lang_flowjs_node_resume_description = "{__('flowjs_node_resume_description')}";

        fetchInject([
            "{_assets("js/template.flow.js")}"
        ], fetchInject([
            "{assets("js/flow.js")}"
        ], fetchInject([
            "{_assets("js/system.js")}"
        ], fetchInject([
            "{_assets("js/libs/jquery.loading.min.js")}",
            "{_assets("js/libs/izitoast.min.js")}",
            "{_assets("js/libs/cookieconsent.min.js")}",
            "{_assets("js/libs/drawflow.min.js")}"
        ], fetchInject([
            "{_assets("js/libs/jquery.min.js")}",
            "{_assets("css/libs/izitoast.min.css")}",
            "{_assets("css/libs/cookieconsent.min.css")}"
        ])))));
    </script>
</body>

</html>