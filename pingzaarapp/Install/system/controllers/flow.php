<?php

class Flow_Controller extends MVC_Controller
{
    public function index()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("flow");

        if(!$this->smarty->templateExists(template . "/pages/default.tpl"))
            $this->header->redirect(site_url("dashboard"));
    
        $this->prepareSystem();

        $flowId = $this->sanitize->string($this->url->segment(3)) ?: false;
        
        if($flowId):
            if(!$this->sanitize->isInt($flowId))
                $this->header->redirect(site_url("dashboard/pages/notfound"));

            if($this->flow->checkFlow($flowId, logged_id) > 0):
               $flowData = $this->flow->getFlow($flowId, logged_id);
            else:
                $this->header->redirect(site_url("dashboard/pages/notfound"));
            endif;
        else:
            $flowData = false;
        endif;

        $vars = [
            "title" => __("flow_header_title"),
            "page" => "flow",
            "data" => [
                "flow" => $flowData
            ]
        ];

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/pages/default.tpl");
    }

    public function start()
    {
        $this->header->allow(site_url);

        $this->prepareSystem();

        if(!$this->session->has("logged"))
            response(303);

        response(200, false, [
            'devices' => $this->flow->getDevices(logged_id),
            'wa_accounts' => $this->flow->getWaAccounts(logged_id),
            'ai_keys' => $this->flow->getAiKeys(logged_id),
            'ai_plugins' =>  $this->flow->getAiPlugins(logged_id),
            'contact_groups' => $this->flow->getContactGroups(logged_id)
        ]);
    }

    public function save()
    {
        $this->header->allow(site_url);

        $this->prepareSystem();

        if(!$this->session->has("logged"))
            response(303);

        $request = $this->sanitize->array($_POST, ["flow_data"]);

        if(!isset($request["source"], $request['flow_data'])):
            response(500, __("response_invalid"));
        endif;

        if(!in_array($request["source"], ["sms", "whatsapp"])):
            response(500, __("response_invalid"));
        endif;
        
        try {
            $flow_data = json_decode($request['flow_data'], true, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            response(500, __("response_invalid"));
        }

        if(!isset($flow_data['drawflow'], $flow_data['drawflow']['Home'], $flow_data['drawflow']['Home']['data'])):
            response(500, __("response_invalid"));
        endif;

        // Validate AI reply nodes
        $nodes = $flow_data['drawflow']['Home']['data'];
        foreach ($nodes as $node) {
            if ($node['name'] === 'ai_reply') {
                if (!isset($node['data']['key']) || empty($node['data']['key'])) {
                    response(500, __("flow_response_ai_key_required"));
                }
            }
        }

        $filtered = [
            "uid" => logged_id,
            "name" => "FLOW_" . uniqid(),
            "source" => $request["source"],
            "flow_data" => json_encode($flow_data)
        ];

        if(isset($request["id"])):
            $dbAction = $this->system->update($request["id"], logged_id, "flows", [
                "source" => $filtered["source"],
                "flow_data" => $filtered["flow_data"]
            ]);
        else:
            $dbAction = $this->system->create("flows", $filtered);
        endif;

        if($dbAction):
            response(200, __("flow_response_saved"), [
                "flow_id" => isset($request["id"]) ? $request["id"] : $dbAction
            ]);
        else:
            response(500, __("response_went_wrong"));
        endif;
    }

    public function upload()
    {
        $this->header->allow(site_url);

        $this->prepareSystem();

        if(!$this->session->has("logged"))
            response(303);

        if(!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK):
            response(400, __("flow_response_nofile"));
        endif;

        // Validate file size (10MB limit)
        $max_size = 10 * 1024 * 1024;
        if ($_FILES['file']['size'] > $max_size):
            response(400, __("flow_response_file_too_large"));
        endif;

        $file = $_FILES['file'];
        $node_id = $_POST['node_id'] ?? null;

        // The node id becomes a path segment, so it must not be able to escape
        // uploads/flow/. flow.js only ever sends "input_<timestamp>_<base36>".
        if(!$node_id || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $node_id)):
            response(400, __("response_invalid"));
        endif;

        // Never trust the client-supplied extension: uploads/ is inside the document
        // root, so an unrestricted extension is remote code execution.
        //
        // The list covers what the send_image / send_video / send_audio / send_document
        // nodes actually deliver. Deliberately absent are the types a browser will
        // execute in this site's own origin when the stored URL is opened directly —
        // svg, html, xhtml, xml and xsl(t) all can, and files under uploads/ are served
        // by the web server without passing through the application at all.
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if(!in_array($ext, [
            "jpg", "jpeg", "png", "gif", "webp",                      // image
            "mp4", "3gp", "mov", "webm",                              // video
            "mp3", "ogg", "oga", "m4a", "aac", "amr", "wav",          // audio
            "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx",       // document
            "txt", "csv", "rtf", "zip"
        ])):
            response(400, __("flow_response_invalid_filetype"));
        endif;

        $filename = uniqid() . '.' . $ext;
        $upload_dir = 'uploads/flow/' . logged_id . '/' . $node_id;

        if(!is_dir($upload_dir)):
            mkdir($upload_dir, 0755, true);
        endif;
        
        $destination = $upload_dir . '/' . $filename;
        
        if(move_uploaded_file($file['tmp_name'], $destination)):
            response(200, __("flow_response_fileuploaded"), [
                'fileUrl' => $destination
            ]);
        else:
            response(500, __("response_went_wrong"));
        endif;
    }

    private function prepareSystem(): void
    {
        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        set_logged($this->session->get("logged"));

        set_language(logged_language, logged_rtl);
    }
}