    {include "./modules/footer.block.tpl"}

    <div system-preloader>
        <div class="loadingio loadingio-spinner-ripple-c4xwekkbyc9">
            <div class="ldio-k6xrhuhg6o">
                <div></div>
                <div></div>
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
        
        var lang_response_went_wrong = "{__("response_went_wrong")}",
            lang_alert_attention = "{__("alert_attention")}",
            lang_cookieconsent_message = "{__("cookieconsent_message")}",
            lang_cookieconsent_link = "{__("cookieconsent_link")}",
            lang_cookieconsent_dismiss = "{__("cookieconsent_dismiss")}",
            lang_js_processing_dataloader = "{__("js_processing_dataloader")}",
            lang_js_loader_pleasewait = "{__("js_loader_pleasewait")}",
            lang_response_session_false = "{__("response_session_false")}";

        fetchInject([
            "{_assets("js/template.default.js")}", {__s("plugin_scripts")}
        ], fetchInject([
            "{_assets("js/custom.run.js", true)}", {__s("plugin_styles")}
        ], fetchInject([
            "{_assets("js/system.js")}",
        ], fetchInject([
            "{_assets("js/libs/mfb.min.js")}",
            "{assets("js/template.js")}"
        ], fetchInject([
            "{_assets("js/libs/pjax.min.js")}",
            "{_assets("js/libs/waves.min.js")}",
            "{_assets("js/libs/nprogress.min.js")}",
            "{_assets("js/libs/jquery.loading.min.js")}",
            "{_assets("js/libs/izitoast.min.js")}",
            "{_assets("js/libs/cookieconsent.min.js")}",
            "{_assets("js/libs/alertsound.min.js")}",
            "{_assets("js/libs/pusher.min.js")}"
        ], fetchInject([
            "{assets("js/libs/bootstrap.min.js")}",
            "{assets("js/plugins.js")}"
        ], fetchInject([
            "{_assets("js/libs/jquery.min.js")}",
            "{_assets("css/custom.css", true)}",
            "{_assets("css/libs/waves.min.css")}",
            "{_assets("css/libs/izitoast.min.css")}",
            "{_assets("css/libs/cookieconsent.min.css")}",
            "{if __s("language_rtl")}{_assets("css/libs/mfb.rtl.min.css")}{else}{_assets("css/libs/mfb.min.css")}{/if}"
        ])))))));
    </script>
</body>

</html>