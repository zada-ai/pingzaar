<?php

class Plugin_Controller extends MVC_Controller
{
    public function index()
    {
        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

		set_template("dashboard");

		set_logged($this->session->get("logged"));

        $this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        $request = $this->sanitize->array($_REQUEST);

        $json = isset($request["json"]) ? true : false;

        set_language(logged_language, $this->system->isLanguageRtl(logged_language));

        if(!isset($request["name"]))
            $this->pluginError(__("plugin_controller_wentwrong"), $json);

        if(!isset($request["action"]) || empty($request["action"]))
            $request["action"] = "default";

        if(!$this->file->exists("system/plugins/installables/{$request["name"]}/plugin.json"))
            $this->pluginError(__("plugin_controller_wentwrong"), $json);

        if(!$this->file->exists("system/plugins/installables/{$request["name"]}/plugin.php"))
            $this->pluginError(__("plugin_controller_wentwrong"), $json);

        $pluginData = json_decode($this->file->get("system/plugins/installables/{$request["name"]}/plugin.json"), true);

        if(isset($pluginData["models"]) && !empty($pluginData["models"])):
            try { 
                foreach($pluginData["models"] as $model):
                    require "system/plugins/installables/{$request["name"]}/models/{$model}.php";
                endforeach;
            } catch(Exception $e){
                $this->pluginError(__("plugin_controller_wentwrong"), $json);
            }
        endif;

        try {
            $pluginActions = require "system/plugins/installables/{$request["name"]}/plugin.php";
        } catch (Exception $e) {
            $this->pluginError(__("plugin_controller_wentwrong"), $json);
        }

        if(!isset($pluginActions[$request["action"]]))
            $this->pluginError(__("plugin_controller_wentwrong"), $json);
        
        try {
            $pluginActions[$request["action"]]($request);
        } catch (Exception $e) {
            $this->pluginError(___(__("plugin_controller_error"), [$e->getMessage()]), $json);
        }
    }

    private function pluginError($message, $json = false){
        if($json):
            response(500, $message);
        else:
            $this->smarty->assign([
                "title" => __("plugin_page_errortitle"),
                "page"=> "plugin/plugin.error",
                "message" => $message
            ]);

            $this->smarty->display(template . "/header.tpl");
            $this->smarty->display(template . "/pages/errors/plugin.error.tpl");
            $this->smarty->display(template . "/footer.tpl");

            die;
        endif;
	}
}