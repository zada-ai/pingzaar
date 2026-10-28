<?php

class Unsubscribe_Controller extends MVC_Controller
{
	public function index()
    {
        set_template("dashboard");

        $uid = $this->sanitize->string($this->url->segment(3));
        $phone = $this->sanitize->string($this->url->segment(4));

        if(!$this->smarty->templateExists(template . "/pages/misc/unsubscribe.tpl"))
            $this->header->redirect(site_url);

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

        set_logged();

        if($this->system->checkUser($uid) < 1)
            $this->header->redirect(site_url);

        $userAccount = $this->system->getUser($uid);

        set_language($userAccount["language"], $userAccount["rtl"]);

        try {
            $number = $this->phone->parse($phone);

            $number->format(Brick\PhoneNumber\PhoneNumberFormat::INTERNATIONAL);

            if(!$number->isValidNumber())
                $this->header->redirect(site_url());

            // No number-type filter here, deliberately. The send paths use
            // is_messagable_number() to skip numbers an SMS cannot reach, but an
            // opt-out must never be turned away: refusing to record one because
            // the number looks like a landline is worse than recording one that
            // was never going to be messaged anyway.
            $phone = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

            if($this->system->checkUnsubscribed($uid, $phone) < 1):
                $this->system->create("unsubscribed", [
                    "uid" => $uid,
                    "phone" => $phone
                ]);
            endif;
        } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
            // Ignore
        }

        $vars = [
            "title" => __("unsubscribe_header_title"),
            "page" => "misc/unsubscribe"
        ];

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(template . "/pages/misc/unsubscribe.tpl");
        $this->smarty->display(template . "/footer.tpl");
    }
}