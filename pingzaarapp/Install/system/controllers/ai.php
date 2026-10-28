<?php

class Ai_Controller extends MVC_Controller
{
    public function index()
    {
        $this->header->allow();

        response(200);
    }

    public function plugins()
    {
        $this->header->allow();

        $token = $this->url->segment(4);
        $action = $this->url->segment(5);

        if(!isset($token, $action))
            response(500);

        if($token != system_token)
            response(500);

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if($this->ai->checkAction($action) < 1)
            response(500);

        $getAction = $this->ai->getAction($action);

        if(!$getAction)
            response(500);

        if(empty($getAction["ai_plugins"]))
            response(500);
        
        $getAiKey = $this->ai->getAiKey($getAction["ai_key"]);

        if(!$getAiKey)
            response(500);

        $pluginSchemas = [];
        $explodePlugins = explode(",", $getAction["ai_plugins"]);

        foreach($explodePlugins as $explodePlugin):
            if(str_starts_with($explodePlugin, "ai-")):
                try {
                    $globalPlugin = require "system/plugins/installables/{$explodePlugin}/plugin.php";
                    $globalPlugin["schema"]["endpoint"] = site_url("plugin?uid={$getAiKey["uid"]}&name={$explodePlugin}&json=true", true);
                    $pluginSchemas[] = $globalPlugin["schema"];
                } catch(Exception $e) {
                    // Ignore
                }
            endif;
        endforeach;

        $plugins = $this->ai->getActionPlugins($action);

        if(!empty($plugins)):
            foreach($plugins as $plugin):
                if(isset($plugin["schema"])):
                    try {
                        $decodedSchema = json_decode($plugin["schema"], true, JSON_THROW_ON_ERROR);
                        $decodedSchema["endpoint"] = !empty($plugin["endpoint"]) ? $plugin["endpoint"] : false;
                    } catch(Exception $e) {
                        $decodedSchema = false;
                    }

                    if($decodedSchema):
                        $pluginSchemas[] = $decodedSchema;
                    endif;
                endif;
            endforeach;
        endif;

        response(200, false, $pluginSchemas);
    }
}
