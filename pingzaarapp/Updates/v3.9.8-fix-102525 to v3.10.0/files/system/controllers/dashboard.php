<?php
/**
 * Dashboard Controller
 * @desc This class is used to manage the dashboard 
 * @author TitanSystems 
 */

class Dashboard_Controller extends MVC_Controller
{
    public function index()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $vars = $this->prepareSystem();

        $this->cache->container("user." . logged_hash, true);

        if(!$this->cache->has("messages.ratio") || !$this->cache->has("messages.total")):
            $success = $this->system->getSentSuccessCount(logged_id);
            $failed = $this->system->getSentFailedCount(logged_id);
            $received = $this->system->getReceivedCount(logged_id);

            $this->cache->set("messages.ratio", [
                "success" => $success > 0 ? abs(round(($success / ($success + $failed)) * 100, 2)) : 0,
                "failed" => $failed > 0 ? abs(round(($failed / ($success + $failed)) * 100, 2)) : 0
            ], 86400);
            
            $this->cache->set("messages.total", [
                "sent" => number_format($success + $failed),
                "received" => number_format($received)
            ], 86400);
        endif;

        $vars["title"] = __("dashboard_title_default");
        $vars["page"] = "default";
        $vars["data"] = [
            "ratio" => $this->cache->get("messages.ratio"),
            "total" => $this->cache->get("messages.total"),
            "count" => [
                "devices" => $this->system->countDevices(logged_id),
                "accounts" => $this->system->countWaAccounts(logged_id)
            ],
            "balance" => $this->system->getBalance(logged_id),
            "partner" => $this->system->getPartnership(logged_id)
        ];

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/default.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function pages()
    {   
        set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/misc/page.tpl"))
            $this->header->redirect(site_url("dashboard/pages/notfound"));

        $vars = $this->prepareSystem();

        switch($page){
            case "notfound":
                $vars["title"] = __("pages_error404_title");
                $vars["page"] = "errors/404.error";

                break;
            default:
                if(!$this->session->has("logged"))
                    $this->header->redirect(site_url("dashboard/auth"));

                $id = $this->url->segment(4);
                $slug = $this->url->segment(5);
        
                if(!$this->sanitize->isInt($id))
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
        
                if(empty($slug))
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
        
                if($this->system->checkPage($id) < 1)
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
        
                $this->cache->container("system.pages");
        
                if(!$this->cache->has($id)):
                    $this->cache->set($id, $this->system->getPage($id));
                endif;
        
                $content = $this->cache->get($id);
        
                if($content["slug"] != $slug)
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
        
                $roles = explode(",", $content["roles"]);
        
                if(!in_array(1, $roles)):
                    if(!in_array(logged_role, $roles)):
                        $this->header->redirect(site_url("dashboard/pages/notfound"));
                    endif;
                endif;
                
                $vars["title"] = $content["name"];
                $vars["page"] = "misc/page";
                $vars["data"] = [
                    "page" => $content,
                    "content" => $this->smarty->fetch("string: {$this->sanitize->htmlDecode($content["content"])}")
                ];
        }

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/{$vars["page"]}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function misc()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/misc/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        switch($page){
            case "rates.gateways":
                if(!in_array("sms", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("misc_gatewayrates_title");

                break;
            case "rates.partners":
                if(!in_array("sms", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("misc_partnerrates_title");

                break;
            default:
                $vars["title"] = __("misc_packages_title");
                $vars["data"]["packages"] = $this->widget->getPackages(true, system_freemodel < 2 ? true : false);
        }

        $vars["page"] = "misc/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/misc/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function sms()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/user/sms/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        if(!in_array("sms", $vars["services"])):
            $this->header->redirect(site_url("dashboard/pages/notfound"));
        endif;

        switch($page){
            case "sent":
                $vars["title"] = __("and_droid_sent_3");

                break;
            case "received":
                $vars["title"] = __("and_droid_rev_3");

                break;
            case "campaigns":
                $vars["title"] = __("pages_smscampaigns_header");

                break;
            case "scheduled":
                $vars["title"] = __("messages_scheduled_title");

                break;
            case "transactions":
                $vars["title"] = __("pages_smstransactions_header");

                break;
            default:
                $vars["title"] = __("tabs_smspage_queuetitle");
        }

        $vars["page"] = "user/sms/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/sms/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function whatsapp()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/user/whatsapp/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        if(!in_array("whatsapp", $vars["services"])):
            $this->header->redirect(site_url("dashboard/pages/notfound"));
        endif;

        switch($page){
            case "sent":
                $vars["title"] = __("and_what_sent_3");

                break;
            case "received":
                $vars["title"] = __("and_what_rev_3");

                break;
            case "campaigns":
                $vars["title"] = __("pages_wacampaigns_header");

                break;
            case "scheduled":
                $vars["title"] = __("messages_scheduled_title");

                break;
            case "groups":
                $vars["title"] = __("pages_wagroups_header");

                break;
            default:
                $vars["title"] = __("tabs_wapage_queuetitle");
        }

        $vars["page"] = "user/whatsapp/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/whatsapp/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function hosts()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/user/hosts/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        switch($page){
            case "whatsapp":
                if(!in_array("whatsapp", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_usub110");

                break;
            default:
                if(!in_array("sms", $vars["services"]) && !in_array("android_ussd", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_usub104");
        }

        $vars["page"] = "user/hosts/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/hosts/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function android()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/user/android/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        switch($page){
            case "ussd":
                if(!in_array("android_ussd", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_droid_ussd_3");

                break;
        }

        $vars["page"] = "user/android/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/android/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function contacts()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/user/contacts/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        switch($page){
            case "groups":
                $vars["title"] = __("dashboard_contacts_tabgroupstitle");

                break;
            case "unsubscribed":
                $vars["title"] = __("and_con_unsub_3");

                break;
            default:
                $vars["title"] = __("dashboard_contacts_tabsavedtitle");
        }

        $vars["page"] = "user/contacts/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/contacts/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function tools()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/user/tools/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        switch($page){
            case "webhooks":
                if(!in_array("webhooks", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("dashboard_tools_tabhookstitle");

                break;
            case "actions":
                if(!in_array("actions", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_tool_act_3");

                break;
            case "templates":
                if(!in_array("templates", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("dashboard_messages_tabtemplatestitle");

                break;
            case "flows":
                if(!in_array("flows", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("flow_page_header");

                break;
            case "logger":
                $vars["title"] = __("logger_page_header");

                break;
            default:
                if(!in_array("api", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("dashboard_tools_tabkeystitle");
        }

        $vars["page"] = "user/tools/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/tools/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function ai()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/user/ai/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        if(!in_array("ai", $vars["services"])):
            $this->header->redirect(site_url("dashboard/pages/notfound"));
        endif;

        switch($page){
            case "plugins":
                $vars["title"] = __("ai_menu_title_aikeys");

                break;
            default:
                $vars["title"] = __("dashboard_title_aikeys");
        }

        $vars["page"] = "user/ai/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/user/ai/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }


    public function admin()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/admin/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        if(!is_admin):
            $this->header->redirect(site_url("dashboard"));
        endif;

        switch($page){
            case "users":
                $vars["title"] = __("dashboard_admin_tabuserstitle");

                break;
            case "roles":
                $vars["title"] = __("dashboard_roles_title");

                break;
            case "packages":
                $vars["title"] = __("dashboard_admin_tabpackagestitle");

                break;
            case "vouchers":
                $vars["title"] = __("dashboard_vouchers_title");

                break;
            case "subscriptions":
                $vars["title"] = __("dashboard_admin_tabsubscriptionstitle");

                break;
            case "transactions":
                $vars["title"] = __("dashboard_admin_tabtransactionstitle");

                break;
            case "payouts":
                $vars["title"] = __("and_admin_payout_3");

                break;
            case "pages":
                $vars["title"] = __("dashboard_pages_title");

                break;
            case "marketing":
                $vars["title"] = __("administration_landing_marketing");

                break;
            case "languages":
                $vars["title"] = __("dashboard_admin_tablanguagestitle");

                break;
            case "waservers":
                $vars["title"] = __("dashboard_admin_waserverstitle");

                break;
            case "gateways":
                $vars["title"] = __("and_admin_gate_3");

                break;
            case "shorteners":
                $vars["title"] = __("administration_landing_shorteners");

                break;
            case "plugins":
                $vars["title"] = __("dashboard_admin_pluginstitle");

                break;
            default:
                $vars["title"] = __("dashboard_title_admin");
                $vars["data"]["gateway"] = $this->file->exists("uploads/builder/gateway.apk");
        }

        $vars["page"] = "admin/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/admin/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function docs()
    {
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/docs/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        $vars = $this->prepareSystem();

        switch($page){
            case "admin":
                if(!permission("manage_api")):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_admin_api_3");

                break;
            case "webhooks":
                if(!in_array("webhooks", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_dash_pg_doc_line34");

                break;
            case "actions":
                if(!in_array("actions", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_dash_pg_doc_line40");

                break;
            case "android":
                if(!in_array("sms", $vars["services"]) && !in_array("android_ussd", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("dashboard_docsnew_androidtitle");

                break;
            case "partners":
                if(!in_array("sms", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("and_droid_dev_13");

                break;
            default:
                if(!in_array("api", $vars["services"])):
                    $this->header->redirect(site_url("dashboard/pages/notfound"));
                endif;

                $vars["title"] = __("table_whatsappsent_apititle");
        }

        $vars["page"] = "docs/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/docs/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    public function auth()
    {
        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        if($this->session->has("logged"))
            $this->header->redirect(site_url("dashboard"));
        else
            set_template("dashboard");

        $page = ($this->sanitize->string($this->url->segment(4)) ?: "default");

        if(!$this->smarty->templateExists(template . "/pages/auth/{$page}.tpl"))
            $this->header->redirect(site_url("dashboard"));

        set_logged($this->session->get("logged"));

        set_language(logged_language, $this->system->isLanguageRtl(logged_language));

        $this->cache->container("system.plugins");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getPlugins());
        endif;

        set_plugins($this->cache->getAll());

        switch($page){
            case "register":
                if(system_registrations > 1)
                    $this->header->redirect(site_url("dashboard/auth"));

                $confirmId = $this->sanitize->string($this->url->segment(5));

                if(!empty($confirmId)):
                    $this->cache->container("register.confirm", true);

                    if($this->cache->has($confirmId)):
                        if($this->system->update($this->cache->get($confirmId), false, "users", [
                            "confirmed" => 1
                        ])):
                            $this->cache->delete($confirmId);
                            
                            $confirmBool = true;
                        else:
                            $confirmBool = false;
                        endif;
                    else:
                        $confirmBool = false;
                    endif;
                else:
                    $confirmBool = false;
                endif;

                $vars = [
                    "title" => __("dashboard_title_register"),
                    "data" => [
                        "timezones" => $this->timezones->generate(),
                        "countries" => \CountryCodes::get("alpha2", "country"),
                        "confirm" => $confirmBool
                    ]
                ];

                break;
            case "forgot": 
                $vars = [
                    "title" => __("dashboard_title_forgot")
                ];

                break;
            default:
                $vars = [
                    "title" => __("dashboard_title_login")
                ];
        }

        $vars["page"] = "auth/{$page}";

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/auth/{$page}.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }

    private function prepareSystem()
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

        $subscription = set_subscription(
            $this->system->checkSubscription(logged_id), 
            $this->system->getSubscription(false, logged_id), 
            $this->system->getSubscription(false, false, true)
        );

        if(logged_id):
            $partner = $this->system->getPartnership(logged_id);

            return [
                "partner" => $partner ? ($partner < 2 ? true : false) : false,
                "subscription" => $subscription,
                "services" => explode(",", $subscription["services"])
            ];
        else:
            return [];
        endif;
    }
}