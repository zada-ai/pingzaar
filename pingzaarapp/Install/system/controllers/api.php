<?php

/**
 * API Controller
 * @last-modified 2025-04-22
 */

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\ValidationException;

class Api_Controller extends MVC_Controller
{
	public function index()
	{
        $this->header->allow();

        if(!$this->session->has("logged"))
            response(401);

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        set_logged($this->session->get("logged"));

        $countries = \CountryCodes::get("alpha2", "country");

        try {
            $phoneSample = $this->phone->getExampleNumber(logged_country, Brick\PhoneNumber\PhoneNumberType::MOBILE);
            $localPhone = $phoneSample->format(Brick\PhoneNumber\PhoneNumberFormat::NATIONAL);
            $country = $countries[$phoneSample->getRegionCode()];
        } catch(Exception $e){
            $phoneSample = "+63123456789";
            $localPhone = "09123456789";
            $country = $countries["PH"];
        }
        
        renderApiSpec("api_spec", [
            "site_name" => system_site_name,
            "site_url" => site_url(false, true),
            "phone_number" => $phoneSample,
            "local_phone" => str_replace(" ", "", $localPhone),
            "country" => $country
        ]);
	}

    public function send()
    {
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $request = $this->sanitize->array($_REQUEST);
        $service = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["secret"]))
            response(400, "Invalid Parameters!");

        if($this->api->checkApikey($request["secret"]) < 1)
            response(401, "Invalid API secret supplied!");

        $api = $this->api->getApikey($request["secret"]);

        $subscription = set_subscription(
            $this->system->checkSubscription($api["uid"]), 
            $this->system->getSubscription(false, $api["uid"]), 
            $this->system->getSubscription(false, false, true)
        );

        date_default_timezone_set($this->api->getUserTimezone($api["uid"]));
        
        switch($service):
            case "otp":
                if(!in_array("otp", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["message"]))
                    response(400, "Invalid Parameters!");

                if(isset($request["expire"])):
                    if(!$this->sanitize->isInt($request["expire"])):
                        response(400, "Invalid Parameters!");
                    endif;
                endif;

                $this->cache->container("api.otp.{$request["secret"]}", true);

                $unique = false;

                while(!$unique):
                    $otp = rand(100000, 999999);

                    if(!$this->cache->has($otp)):
                        $this->cache->set($otp, true, isset($request["expire"]) ? ($request["expire"] < 1 ? 1 : $request["expire"]) : 300);

                        $unique = true;
                    endif;
                endwhile;

                $request["message"] = $this->lex->parse($request["message"], [
                    "otp" => $otp
                ]);

                $otpId = false;

                if($request["type"] == "sms"):
                    if(!in_array("sms", explode(",", $subscription["services"]))):
                        response(403, "Subscription has no permission to use SMS services!");
                    endif;

                    if(!isset($request["mode"], $request["phone"]))
                        response(400, "Invalid Parameters!");

                    if(!$this->file->exists("system/storage/temporary/firebase.json")):
                        response(500, "System configuration error!");
                    endif;

                    try {
                        $number = $this->phone->parse($request["phone"], $api["country"]);

                        $number->format(Brick\PhoneNumber\PhoneNumberFormat::INTERNATIONAL);

                        if(!$number->isValidNumber())
                            response(400, "Invalid phone number!");

                        if(!is_messagable_number($number))
                            response(400, "Invalid phone number!");

                        $request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
                        $country = $number->getRegionCode();
                    } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                        response(400, "Invalid phone number!");
                    }

                    if(!$this->sanitize->length($request["message"], system_message_min))
                        response(400, "OTP message is too short!");

                    if(system_message_max > 0):
                        if($this->sanitize->length($request["message"], system_message_max, 2))
                            response(400, "OTP message is too long!");
                    endif;

                    if($request["mode"] == "devices"):
                        if(!isset($request["device"]))
                            response(400, "Invalid Parameters!");

                        if(isset($request["sim"])):
                            $request["sim"] = $request["sim"] < 2 ? 1 : 2;
                        else:
                            $request["sim"] = 1;
                        endif;

                        if(empty($subscription))
                            response(403, "Account is not subscribed to any premium package!");

                        if(!$this->sanitize->isInt($request["sim"]))
                            response(400, "Invalid Parameters!");

                        if(limitation($subscription["send_limit"], $this->system->countQuota($api["uid"], "sent")))
                            response(403, "Maximum allowed number of sent messages has been reached!");

                        if($this->system->checkDevice($api["uid"], $request["device"], "did") < 1)
                            response(404, "Device doesn't exist!");

                        $device = $this->system->getDevice($api["uid"], $request["device"], "did");
                    else:
                        if($request["mode"] != "credits")
                            response(400, "Invalid Parameters!");

                        if(!isset($request["gateway"]))
                            response(400, "Invalid Parameters!");

                        if($this->sanitize->isInt($request["gateway"])):
                            $gateways = $this->system->getGateways();

                            if(!array_key_exists($request["gateway"], $gateways)):
                                response(404, "Specified gateway doesn't exist!");
                            endif;

                            if(!$this->file->exists("system/gateways/" . md5($request["gateway"]) . ".php"))
                                response(404, "Specified gateway doesn't exist!");

                            require "system/gateways/" . md5($request["gateway"]) . ".php";
                        else:
                            $device = $this->system->getDevice(false, $request["gateway"], "global");

                            if($device):
                                if($device["global_device"] > 1):
                                    response(403, "Device is not available!");
                                endif;
                            else:
                                response(404, "Device doesn't exist!");
                            endif;
                        endif;
                    endif;

                    if($request["mode"] == "devices"):
                        $otpId = $this->system->create("sent", [
                            "cid" => 0,
                            "uid" => $api["uid"],
                            "did" => $request["device"],
                            "gateway" => 0,
                            "sim" => $request["sim"] < 2 ? 1 : 2,
                            "mode" => 1,
                            "phone" => $request["phone"],
                            "message" => $this->spintax->process(footermark($subscription["footermark"], $request["message"], system_message_mark)),
                            "status" => 1,
                            "status_code" => false,
                            "priority" => 1,
                            "api" => 1,
                            "create_date" => date("Y-m-d H:i:s", time())
                        ]);
                    else:
                        $credits = $this->system->getCredits($api["uid"]);

                        if($this->sanitize->isInt($request["gateway"])):
                            try {
                                $gatewayHandler = require "system/gateways/" . md5($request["gateway"]) . ".php";
                            } catch(Exception $e){
                                response(500, "We encountered a gateway error!");
                            }

                            $pricing = json_decode($gateways[$request["gateway"]]["pricing"], true);

                            if(array_key_exists(strtolower($country), $pricing["countries"])):
                                $price = $pricing["countries"][strtolower($country)];
                            else:
                                $price = $pricing["default"];
                            endif;

                            if($credits < $price)
                                response(500, "Not enough credits to send this message!");

                            $gateway = $gateways[$request["gateway"]];

                            $message = $this->spintax->process($request["message"]);

                            $send = $gatewayHandler["send"]($request["phone"], $message, $this);

                            if($send):
                                $create = $this->system->create("sent", [
                                    "cid" => 0,
                                    "uid" => $api["uid"],
                                    "did" => false,
                                    "gateway" => $request["gateway"],
                                    "api" => 0,
                                    "sim" => 0,
                                    "mode" => 2,
                                    "priority" => 0,
                                    "phone" => $request["phone"],
                                    "message" => $message,
                                    "status" => $gateway["callback"] < 2 ? 2 : 3,
                                    "status_code" => false,
                                    "create_date" => date("Y-m-d H:i:s", time())
                                ]);

                                if($create):
                                    $otpId = $create;

                                    if($gateway["callback"] < 2):
                                        $this->cache->container("system.gateways");

                                        $this->cache->set("{$gateway["callback_id"]}.{$send}", $create);
                                    else:
                                        $this->process->_sanitize = $this->sanitize;

                                        $hooks = $this->process->actionHooks($api["uid"], 1, 1, $request["phone"], $message, $this->device->getActions($api["uid"], 1));

                                        if(!empty($hooks)):
                                            foreach($hooks as $hook):
                                                $this->system->create("events", [
                                                    "uid" => $api["uid"],
                                                    "type" => 2,
                                                    "create_date" => date("Y-m-d H:i:s", time())
                                                ]);
                                            endforeach;
                                        endif;

                                        $this->system->credits($api["uid"], "decrease", $price);
                                    endif;
                                endif;
                            else:
                                $this->system->create("sent", [
                                    "cid" => 0,
                                    "uid" => $api["uid"],
                                    "did" => false,
                                    "gateway" => $request["gateway"],
                                    "api" => 0,
                                    "sim" => 0,
                                    "mode" => 2,
                                    "priority" => 0,
                                    "phone" => $request["phone"],
                                    "message" => $message,
                                    "status" => 4,
                                    "status_code" => false,
                                    "create_date" => date("Y-m-d H:i:s", time())
                                ]);

                                response(500, "Gateway was unable to send your message!");
                            endif;
                        else:
                            $currency = country($device["country"])->getCurrency()["iso_4217_code"];
                            $final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);

                            if($final_price):
                                if($credits < $final_price):
                                    response(403, "Not enough credits to send this message!");
                                endif;

                                $slots = explode(",", $device["global_slots"]);

                                $otpId = $this->system->create("sent", [
                                    "cid" => 0,
                                    "uid" => $api["uid"],
                                    "did" => $request["gateway"],
                                    "gateway" => 0,
                                    "sim" => count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2),
                                    "mode" => 2,
                                    "phone" => $request["phone"],
                                    "message" => $this->spintax->process($request["message"]),
                                    "status" => 1,
                                    "status_code" => false,
                                    "priority" => $device["global_priority"],
                                    "api" => 1,
                                    "create_date" => date("Y-m-d H:i:s", time())
                                ]);
                            endif;
                        endif;
                    endif;

                    if($request["mode"] == "devices" || !$this->sanitize->isInt($request["gateway"])):
                        $this->fcm->sendWithToken($device["fcm_token"], [
                            "action" => "message_request"
                        ]);
                    endif;
                else:
                    if(!in_array("whatsapp", explode(",", $subscription["services"]))):
                        response(403, "Subscription has no permission to use WhatsApp services!");
                    endif;
                    
                    if($request["type"] != "whatsapp")
                        response(400, "Invalid Parameters!");

                    if(!isset($request["phone"], $request["account"]))
                        response(400, "Invalid Parameters!");

                    if(!isset($request["priority"])):
                        $request["priority"] = 2;
                    else:
                        if(!$this->sanitize->isInt($request["priority"]))
                            response(400, "Priority parameter must be an integer value!");
                    endif;

                    if(empty($subscription))
                        response(403, "Account is not subscribed to any premium package!");

                    if($this->system->checkWaAccount($api["uid"], $request["account"], "unique") < 1)
                        response(404, "WhatsApp account doesn't exist!");

                    if($this->system->checkQuota($api["uid"]) < 1):
                        $this->system->create("quota", [
                            "uid" => $api["uid"],
                            "sent" => 0,
                            "received" => 0,
                            "wa_sent" => 0,
                            "wa_received" => 0,
                            "ussd" => 0
                        ]);
                    endif;

                    $waServer = $this->system->getWaServer($request["account"], "unique");

                    if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
                        response(500, "Unable to connect to WhatsApp servers!");

                    $account = $this->system->getWaAccount($api["uid"], $request["account"], "unique");

                    $status = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

                    if(!$status || !in_array($status, ["connected"]))
                        response(500, "WhatsApp account is disconnected!");

                    if(limitation($subscription["wa_send_limit"], $this->system->countQuota($api["uid"], "wa_sent")))
                        response(403, "You have reached the maximum number of allowed chats!");

                    try {
                        $number = $this->phone->parse($request["phone"], $api["country"]);

                        if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
                            response(400, "Invalid phone number!");

                        $request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

                        if($number->getRegionCode() == "MX"):
                            $request["phone"] = formatMexicoNumWa($request["phone"]);
                        endif;
                    } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                        response(400, "Invalid phone number!");
                    }

                    $filtered = [
                        "cid" => 0,
                        "uid" => $api["uid"],
                        "wid" => $account["wid"],
                        "unique" => $account["unique"],
                        "phone" => $request["phone"],
                        "message" => json_encode([
                            "text" => $this->spintax->process(footermark($subscription["footermark"], $request["message"], system_message_mark))
                        ]),
                        "status" => 1,
                        "priority" => $request["priority"] < 2 ? 1 : 2,
                        "api" => 1,
                        "create_date" => date("Y-m-d H:i:s", time())
                    ];
                    
                    $create = $this->system->create("wa_sent", $filtered);

                    if($create):
                        $otpId = $create;

                        if($filtered["priority"] < 2):
                            $sendPriority = $this->wa->sendPriority($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], $create, $filtered["phone"], $filtered["message"]);
                            
                            if($sendPriority):
                                if($sendPriority == 200):
                                    $this->system->increment($api["uid"], "wa_sent");
                                else:
                                    response(500, "Failed sending WhatsApp chat!");
                                endif;
                            else:
                                response(500, "Unable to connect to WhatsApp servers!");
                            endif;
                        else:
                            $addQueue = $this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

                            if($addQueue):
                                if($addQueue != 200):
                                    response(500, "Failed adding chat to WhatsApp queue!");
                                endif;
                            else:
                                response(500, "Unable to connect to WhatsApp servers!");
                            endif;
                        endif;
                    else:
                        response(500, "Something went wrong!");
                    endif;
                endif;

                response(200, "OTP has been sent!", [
                    "phone" => $request["phone"],
                    "message" => $request["message"],
                    "messageId" => $otpId,
                    "otp" => (int) $otp
                ]);

                break;
            case "sms":
                if(!in_array("sms", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use SMS services!");

                if(!in_array("sms_send", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["mode"], $request["phone"], $request["message"]))
                    response(400, "Invalid Parameters!");

                if(!$this->file->exists("system/storage/temporary/firebase.json")):
                    response(500, "System configuration error!");
                endif;

                try {
                    $number = $this->phone->parse("+" . ltrim($request["phone"], "+"), $api["country"]);

                    if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
                        response(400, "Invalid phone number!");

                    $phone = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
                    $country = $number->getRegionCode();
                } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                    response(400, "Invalid phone number!");
                }

                if(!$this->sanitize->length($request["message"], system_message_min))
                    response(400, "Message is too short!");

                if(system_message_max > 0):
                    if($this->sanitize->length($request["message"], system_message_max, 2))
                        response(400, "Message is too long!");
                endif;

                if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
                    $this->system->create("logger", [
                        "uid" => $api["uid"],
                        "type" => "ai_moderation",
                        "reason" => "Inappropriate Content!",
                        "content" => "Type: SMS, Message: {$request["message"]}",
                        "create_date" => date("Y-m-d H:i:s", time())
                    ]);

                    response(400, "We detected inappropriate content in your message!");
                endif;

                if($request["mode"] == "devices"):
                    if(!isset($request["device"]))
                        response(400, "Invalid Parameters!");

                    if(isset($request["sim"])):
                        if(!$this->sanitize->isInt($request["sim"]))
                            response(400, "Sim parameter must be an integer value!");

                        $request["sim"] = $request["sim"] < 2 ? 1 : 2;
                    else:
                        $request["sim"] = 1;
                    endif;

                    if(!isset($request["priority"])):
                        $request["priority"] = 0;
                    else:
                        if(!$this->sanitize->isInt($request["priority"]))
                            response(400, "Priority parameter must be an integer value!");
                    endif;

                    if(empty($subscription))
                        response(403, "Account is not subscribed to any premium package!");

                    if(!$this->sanitize->isInt($request["sim"]))
                        response(400, "Invalid Parameters!");

                    if(!$this->sanitize->isInt($request["priority"]))
                        response(400, "Invalid Parameters!");

                    if(limitation($subscription["send_limit"], $this->system->countQuota($api["uid"], "sent")))
                        response(403, "Maximum allowed number of sent messages has been reached!");

                    if($this->system->checkDevice($api["uid"], $request["device"], "did") < 1)
                        response(404, "Device doesn't exist!");

                    $device = $this->system->getDevice($api["uid"], $request["device"], "did");
                else:
                    if($request["mode"] != "credits")
                        response(400, "Invalid Parameters!");

                    if(!isset($request["gateway"]))
                        response(400, "Invalid Parameters!");

                    if($this->sanitize->isInt($request["gateway"])):
                        $gateways = $this->system->getGateways();

                        if(!array_key_exists($request["gateway"], $gateways)):
                            response(404, "Specified gateway doesn't exist!");
                        endif;

                        if(!$this->file->exists("system/gateways/" . md5($request["gateway"]) . ".php"))
                            response(404, "Specified gateway doesn't exist!");

                        try {
                            $gatewayHandler = require "system/gateways/" . md5($request["gateway"]) . ".php";
                        } catch(Exception $e){
                            response(500, "We encountered a gateway error!");
                        }
                    else:
                        $device = $this->system->getDevice(false, $request["gateway"], "global");

                        if($device):
                            if($device["uid"] == $api["uid"]):
                                response(403, "You cannot send messages with credits using your own devices!");
                            endif;

                            if($device["global_device"] > 1):
                                response(403, "Device is not available!");
                            endif;
                        else:
                            response(404, "Device doesn't exist!");
                        endif;
                    endif;
                endif;

                if(isset($request["shortener"])):
                    if(!$this->sanitize->isInt($request["shortener"]))
                        response(400, "Invalid Parameters!");

                    if($request["shortener"] > 0):
                        if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
                            response(404, "Specified shortener doesn't exist!");

                        $messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

                        if(!empty($messageLinks)):
                            try {
                                require "system/shorteners/" . md5($request["shortener"]) . ".php";
                            } catch(Exception $e){
                                response(500, "We encountered a shortener error!");
                            }

                            foreach($messageLinks as $key => $value):
                                $shortLink = shortenUrl($value, $this);

                                if($shortLink):
                                    $request["message"] = str_replace($value, $shortLink, $request["message"]);
                                endif;
                            endforeach;
                        endif;
                    endif;
                endif;

                $createId = false;

                if($request["mode"] == "devices"):
                    $createId = $this->system->create("sent", [
                        "cid" => 0,
                        "uid" => $api["uid"],
                        "did" => $request["device"],
                        "gateway" => 0,
                        "sim" => $request["sim"] < 2 ? 1 : 2,
                        "mode" => 1,
                        "phone" => $phone,
                        "message" => $this->spintax->process(footermark($subscription["footermark"], $request["message"], system_message_mark)),
                        "status" => 1,
                        "status_code" => false,
                        "priority" => $request["priority"] < 2 ? 1 : 2,
                        "api" => 1,
                        "create_date" => date("Y-m-d H:i:s", time())
                    ]);
                else:
                    $credits = $this->system->getCredits($api["uid"]);

                    if($this->sanitize->isInt($request["gateway"])):
                        $pricing = json_decode($gateways[$request["gateway"]]["pricing"], true);

                        if(array_key_exists(strtolower($country), $pricing["countries"])):
                            $price = $pricing["countries"][strtolower($country)];
                        else:
                            $price = $pricing["default"];
                        endif;

                        if($credits < $price)
                            response(403, "Not enough credits to send this message!");

                        $gateway = $gateways[$request["gateway"]];

                        $message = $this->spintax->process($request["message"]);

                        $send = $gatewayHandler["send"]($request["phone"], $message, $this);

                        if($send):
                            $create = $this->system->create("sent", [
                                "cid" => 0,
                                "uid" => $api["uid"],
                                "did" => false,
                                "gateway" => $request["gateway"],
                                "api" => 1,
                                "sim" => 0,
                                "mode" => 2,
                                "priority" => 0,
                                "phone" => $phone,
                                "message" => $message,
                                "status" => $gateway["callback"] < 2 ? 2 : 3,
                                "status_code" => false,
                                "create_date" => date("Y-m-d H:i:s", time())
                            ]);

                            if($create):
                                if($gateway["callback"] < 2):
                                    $this->cache->container("system.gateways");

                                    $this->cache->set("{$gateway["callback_id"]}.{$send}", $create);

                                    response(200, "Message has been queued for sending!", [
                                        "messageId" => $create
                                    ]);
                                else:
                                    $this->process->_sanitize = $this->sanitize;
                
                                    $hooks = $this->process->actionHooks($api["uid"], 1, 1, $phone, $message, $this->device->getActions($api["uid"], 1));

                                    if(!empty($hooks)):
                                        foreach($hooks as $hook):
                                            $this->system->create("events", [
                                                "uid" => $api["uid"],
                                                "type" => 2,
                                                "create_date" => date("Y-m-d H:i:s", time())
                                            ]);
                                        endforeach;
                                    endif;

                                    $this->system->credits($api["uid"], "decrease", $price);

                                    response(200, "Message has been sent!", [
                                        "messageId" => $create
                                    ]);
                                endif;
                            endif;
                        else:
                            $createId = $this->system->create("sent", [
                                "cid" => 0,
                                "uid" => $api["uid"],
                                "did" => false,
                                "gateway" => $request["gateway"],
                                "api" => 1,
                                "sim" => 0,
                                "mode" => 2,
                                "priority" => 0,
                                "phone" => $phone,
                                "message" => $message,
                                "status" => 4,
                                "status_code" => false,
                                "create_date" => date("Y-m-d H:i:s", time())
                            ]);

                            response(500, "Gateway was unable to send your message!", [
                                "messageId" => $createId
                            ]);
                        endif;
                    else:
                        $currency = country($device["country"])->getCurrency()["iso_4217_code"];
                        $final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);

                        if($final_price):
                            if($credits < $final_price):
                                response(403, "Not enough credits to send this message!");
                            endif;

                            $slots = explode(",", $device["global_slots"]);

                            $createId = $this->system->create("sent", [
                                "cid" => 0,
                                "uid" => $api["uid"],
                                "did" => $request["gateway"],
                                "gateway" => 0,
                                "sim" => count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2),
                                "mode" => 2,
                                "phone" => $phone,
                                "message" => $this->spintax->process($request["message"]),
                                "status" => 1,
                                "status_code" => false,
                                "priority" => $device["global_priority"],
                                "api" => 1,
                                "create_date" => date("Y-m-d H:i:s", time())
                            ]);
                        endif;
                    endif;
                endif;

                if($request["mode"] == "devices" || !$this->sanitize->isInt($request["gateway"])):
                    $this->fcm->sendWithToken($device["fcm_token"], [
                        "action" => "message_request"
                    ]);
                endif;

                response(200, "Message has been queued for sending!", [
                    "messageId" => $createId
                ]);

                break;
            case "sms.bulk":
                if(!in_array("sms", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use SMS services!");

                if(!in_array("sms_send_bulk", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["mode"], $request["campaign"], $request["message"]))
                    response(400, "Invalid Parameters!");

                if(!isset($request["numbers"]) && !isset($request["groups"]))
                    response(400, "Invalid Parameters!");

                if(!$this->file->exists("system/storage/temporary/firebase.json")):
                    response(500, "System configuration error!");
                endif;

                if(!$this->sanitize->length($request["message"], system_message_min))
                    response(400, "Message is too short!");

                if(system_message_max > 0):
                    if($this->sanitize->length($request["message"], system_message_max, 2))
                        response(400, "Message is too long!");
                endif;

                if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
                    $this->system->create("logger", [
                        "uid" => $api["uid"],
                        "type" => "ai_moderation",
                        "reason" => "Inappropriate Content!",
                        "content" => "Type: SMS Bulk, Message: {$request["message"]}",
                        "create_date" => date("Y-m-d H:i:s", time())
                    ]);

                    response(400, "We detected inappropriate content in your message!");
                endif;

                if($request["mode"] == "devices"):
                    if(!isset($request["device"]))
                        response(400, "Invalid Parameters!");

                    if(isset($request["sim"])):
                        if(!$this->sanitize->isInt($request["sim"]))
                            response(400, "Sim parameter must be an integer value!");

                        $request["sim"] = $request["sim"] < 2 ? 1 : 2;
                    else:
                        $request["sim"] = 1;
                    endif;

                    if(!isset($request["priority"])):
                        $request["priority"] = 0;
                    else:
                        if(!$this->sanitize->isInt($request["priority"]))
                            response(400, "Priority parameter must be an integer value!");
                    endif;

                    if(empty($subscription))
                        response(403, "You are not subscribed to any premium package!");

                    if(!$this->sanitize->isInt($request["sim"]))
                        response(400, "Invalid Parameters!");

                    if(!$this->sanitize->isInt($request["priority"]))
                        response(400, "Invalid Parameters!");

                    if($this->system->checkDevice($api["uid"], $request["device"], "did") < 1)
                        response(404, "Device doesn't exist!");

                    $device = $this->system->getDevice($api["uid"], $request["device"], "did");
                else:
                    if($request["mode"] != "credits")
                        response(400, "Invalid Parameters!");

                    if(!isset($request["gateway"]))
                        response(400, "Invalid Parameters!");

                    if($this->sanitize->isInt($request["gateway"])):
                        $gateways = $this->system->getGateways();

                        if(!array_key_exists($request["gateway"], $gateways)):
                            response(404, "Specified gateway doesn't exist!");
                        endif;

                        if(!$this->file->exists("system/gateways/" . md5($request["gateway"]) . ".php"))
                            response(404, "Specified gateway doesn't exist!");

                        try {
                            $gatewayHandler = require "system/gateways/" . md5($request["gateway"]) . ".php";
                        } catch(Exception $e){
                            response(500, "We encountered a gateway error!");
                        }
                    else:
                        $device = $this->system->getDevice(false, $request["gateway"], "global");

                        if($device):
                            if($device["uid"] == $api["uid"]):
                                response(500, "You cannot send messages with credits using your own devices!");
                            endif;

                            if($device["global_device"] > 1):
                                response(403, "Device is not available!");
                            endif;
                        else:
                            response(404, "Device doesn't exist!");
                        endif;
                    endif;
                endif;

                $contactBook = [];

                if(isset($request["numbers"])):
                    $numbers = explode(",", trim($request["numbers"]));

                    if(is_array($numbers) && !empty($numbers) && !empty($numbers[0])):
                        foreach($numbers as $number):
                            $rejected = false;

                            try {
                                $phone = $this->phone->parse("+" . ltrim($number, "+"), $api["country"]);

                                if(!$phone->isValidNumber() && $phone->getRegionCode() != "BR")
                                    $rejected = true;

                                $phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
                                $country = $phone->getRegionCode();
                            } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                                $rejected = true;
                            }

                            if(!$rejected):
                                if($request["mode"] != "devices" && $this->sanitize->isInt($request["gateway"])):
                                    $pricing = json_decode($gateways[$request["gateway"]]["pricing"], true);
    
                                    if(array_key_exists(strtolower($country), $pricing["countries"])):
                                        $price = $pricing["countries"][strtolower($country)];
                                    else:
                                        $price = $pricing["default"];
                                    endif;
                                else:
                                    $price = 0;
                                endif;

                                $contactBook[] = [
                                    "name" => $phoneNumber,
                                    "phone" => $phoneNumber,
                                    "group" => "Unknown",
                                    "country" => $country,
                                    "price" => $price
                                ];
                            endif;
                        endforeach;
                    endif;
                endif;

                if(isset($request["groups"])):
                    $groups = explode(",", trim($request["groups"]));

                    if(is_array($groups) && !empty($groups) && !empty($groups[0])):
                        foreach($groups as $group):
                            if($this->system->checkGroup($api["uid"], $group) > 0):
                                $contacts = $this->system->getContactsByGroup($api["uid"], $group);

                                if(!empty($contacts)):
                                    foreach($contacts as $contact):
                                        try {
                                            $phone = $this->phone->parse($contact["phone"], $api["country"]);
                                            $country = $phone->getRegionCode();
                                        } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                                            // ignore
                                        }

                                        if($request["mode"] != "devices" && $this->sanitize->isInt($request["gateway"])):
                                            $pricing = json_decode($gateways[$request["gateway"]]["pricing"], true);
            
                                            if(array_key_exists(strtolower($country), $pricing["countries"])):
                                                $price = $pricing["countries"][strtolower($country)];
                                            else:
                                                $price = $pricing["default"];
                                            endif;
                                        else:
                                            $price = 0;
                                        endif;

                                        $contactBook[] = [
                                            "name" => $contact["name"],
                                            "phone" => $contact["phone"],
                                            "group" => $contact["group"],
                                            "country" => $country,
                                            "price" => $price
                                        ];
                                    endforeach;
                                endif;
                            endif;
                        endforeach;
                    endif;
                endif;

                if(empty($contactBook))
                    response(400, "Invalid Parameters!");

                if(isset($request["shortener"])):
                    if(!$this->sanitize->isInt($request["shortener"]))
                        response(400, "Invalid Parameters!");

                    if($request["shortener"] > 0):
                        if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
                            response(404, "Specified shortener doesn't exist!");

                        $messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

                        if(!empty($messageLinks)):
                            try {
                                require "system/shorteners/" . md5($request["shortener"]) . ".php";
                            } catch(Exception $e){
                                response(500, "We encountered a shortener error!");
                            }

                            foreach($messageLinks as $key => $value):
                                $shortLink = shortenUrl($value, $this);

                                if($shortLink):
                                    $request["message"] = str_replace($value, $shortLink, $request["message"]);
                                endif;
                            endforeach;
                        endif;
                    endif;
                endif;

                $smsCampaign = $this->system->create("campaigns", [
                    "uid" => $api["uid"],
                    "did" => $request["mode"] == "devices" ? $request["device"] : ($this->sanitize->isInt($request["gateway"]) ? false : $request["gateway"]),
                    "gateway" => $request["mode"] == "credits" && $this->sanitize->isInt($request["gateway"]) ? $request["gateway"] : 0,
                    "mode" => $request["mode"] == "devices" ? 1 : 2,
                    "status" => 1,
                    "name" => $request["campaign"],
                    "contacts" => count($contactBook),
                    "create_date" => date("Y-m-d H:i:s", time())
                ]);

                $sendCounter = 0;

                foreach($contactBook as $contact):
                    if($request["mode"] == "devices"):
                        if(!limitation($subscription["send_limit"], $this->system->countQuota($api["uid"], "sent"))):
                            $rejectLimit = false;

                            if(!$rejectLimit):
                                $this->system->create("sent", [
                                    "cid" => $smsCampaign,
                                    "uid" => $api["uid"],
                                    "did" => $request["device"],
                                    "gateway" => 0,
                                    "sim" => $request["sim"] < 2 ? 1 : 2,
                                    "mode" => 1,
                                    "phone" => $contact["phone"],
                                    "message" => $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], $request["message"], system_message_mark), [
                                        "contact" => [
                                            "name" => $contact["name"],
                                            "number" => $contact["phone"]
                                        ],
                                        "group" => [
                                            "name" => $contact["group"]
                                        ],
                                        "date" => [
                                            "now" => date("F j, Y"),
                                            "time" => date("h:i A") 
                                        ]
                                    ])),
                                    "status" => 1,
                                    "status_code" => false,
                                    "priority" => $request["priority"] < 2 ? 1 : 2,
                                    "api" => 1,
                                    "create_date" => date("Y-m-d H:i:s", time())
                                ]);

                                $sendCounter++;
                            endif;
                        endif;
                    else:
                        $credits = $this->system->getCredits($api["uid"]);

                        if($this->sanitize->isInt($request["gateway"])):
                            if($credits >= $contact["price"]):
                                $gateway = $gateways[$request["gateway"]];

                                $message = $this->spintax->process($request["message"]);

                                $send = $gatewayHandler["send"]($contact["phone"], $message, $this);

                                if($send):
                                    $create = $this->system->create("sent", [
                                        "cid" => $smsCampaign,
                                        "uid" => $api["uid"],
                                        "did" => false,
                                        "gateway" => $request["gateway"],
                                        "api" => 1,
                                        "sim" => 0,
                                        "mode" => 2,
                                        "priority" => 0,
                                        "phone" => $contact["phone"],
                                        "message" => $message,
                                        "status" => $gateway["callback"] < 2 ? 2 : 3,
                                        "status_code" => false,
                                        "create_date" => date("Y-m-d H:i:s", time())
                                    ]);

                                    if($create):
                                        if($gateway["callback"] < 2):
                                            $this->cache->container("system.gateways");

                                            $this->cache->set("{$gateway["callback_id"]}.{$send}", $create);
                                        else:
                                            $this->process->_sanitize = $this->sanitize;
                        
                                            $hooks = $this->process->actionHooks($api["uid"], 1, 1, $contact["phone"], $message, $this->device->getActions($api["uid"], 1));

                                            if(!empty($hooks)):
                                                foreach($hooks as $hook):
                                                    $this->system->create("events", [
                                                        "uid" => $api["uid"],
                                                        "type" => 2,
                                                        "create_date" => date("Y-m-d H:i:s", time())
                                                    ]);
                                                endforeach;
                                            endif;

                                            $this->system->credits($api["uid"], "decrease", $contact["price"]);
                                        endif;
                                    endif;
                                else:
                                    $this->system->create("sent", [
                                        "cid" => $smsCampaign,
                                        "uid" => $api["uid"],
                                        "did" => false,
                                        "gateway" => $request["gateway"],
                                        "api" => 1,
                                        "sim" => 0,
                                        "mode" => 2,
                                        "priority" => 0,
                                        "phone" => $contact["phone"],
                                        "message" => $message,
                                        "status" => 4,
                                        "status_code" => false,
                                        "create_date" => date("Y-m-d H:i:s", time())
                                    ]);
                                endif;
                            endif;
                        else:
                            $currency = country($device["country"])->getCurrency()["iso_4217_code"];
                            $final_price = $this->titansys->calculatePartnerSendPrice($currency, $device["rate"], $this->cache);

                            if($final_price && $credits >= ($final_price * count($contactBook))):
                                $slots = explode(",", $device["global_slots"]);

                                $sim = count($slots) > 1 ? rand(1, 2) : ($slots[0] < 2 ? 1 : 2);

                                $rejectLimit = false;

                                if(!$rejectLimit):
                                    $this->system->create("sent", [
                                        "cid" => $smsCampaign,
                                        "uid" => $api["uid"],
                                        "did" => $request["gateway"],
                                        "gateway" => 0,
                                        "sim" => $sim,
                                        "mode" => 2,
                                        "phone" => $contact["phone"],
                                        "message" => $this->spintax->process($this->lex->parse($request["message"], [
                                            "contact" => [
                                                "name" => $contact["name"],
                                                "number" => $contact["phone"]
                                            ],
                                            "group" => [
                                                "name" => $contact["group"]
                                            ],
                                            "date" => [
                                                "now" => date("F j, Y"),
                                                "time" => date("h:i A") 
                                            ]
                                        ])),
                                        "status" => 1,
                                        "status_code" => false,
                                        "priority" => $device["global_priority"],
                                        "api" => 1,
                                        "create_date" => date("Y-m-d H:i:s", time())
                                    ]);
                                endif;
                            endif;
                        endif;
                    endif;
                endforeach;

                if($request["mode"] == "devices" || !$this->sanitize->isInt($request["gateway"])):
                    if($sendCounter < 1):
                        $this->system->delete($api["uid"], $smsCampaign, "campaigns");
                        response(403, "You have reached the maximum sent messages for your current package!");
                    endif;

                    if($sendCounter < count($contactBook)):
                        $this->system->update($smsCampaign, $api["uid"], "campaigns", [
                            "contacts" => $sendCounter
                        ]);
                    endif;
                endif;

                if($request["mode"] == "devices" || !$this->sanitize->isInt($request["gateway"])):
                    $this->fcm->sendWithToken($device["fcm_token"], [
                        "action" => "message_request"
                    ]);
                endif;

                response(200, "Bulk messages has been queued for sending!");

                break;
            case "whatsapp":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("wa_send", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["recipient"], $request["account"], $request["message"]))
                    response(400, "Invalid Parameters!");

                if(isset($request["type"]) && !in_array($request["type"], ["text", "media", "document"])):
                    $request["type"] = "text";
                endif;
    
                if(!isset($request["type"])):
                    $request["type"] = "text";
                endif;

                if(!isset($request["priority"])):
                    $request["priority"] = 2;
                else:
                    if(!$this->sanitize->isInt($request["priority"]))
                        response(400, "Priority parameter must be an integer value!");
                endif;

                if(empty($subscription))
                    response(403, "Account is not subscribed to any premium package!");

                if($this->system->checkWaAccount($api["uid"], $request["account"], "unique") < 1)
                    response(404, "WhatsApp account doesn't exist!");

                if($this->system->checkQuota($api["uid"]) < 1):
                    $this->system->create("quota", [
                        "uid" => $api["uid"],
                        "sent" => 0,
                        "received" => 0,
                        "wa_sent" => 0,
                        "wa_received" => 0,
                        "ussd" => 0
                    ]);
                endif;

                $waServer = $this->system->getWaServer($request["account"], "unique");

                if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
                    response(500, "Unable to connect to WhatsApp servers!");

                $account = $this->system->getWaAccount($api["uid"], $request["account"], "unique");

                $status = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

                if(!$status || !in_array($status, ["connected"]))
                    response(500, "WhatsApp account is disconnected!");

                if(limitation($subscription["wa_send_limit"], $this->system->countQuota($api["uid"], "wa_sent")))
                    response(403, "You have reached the maximum number of allowed chats!");

                if(!find("@g.us", $request["recipient"])):
                    try {
                        $number = $this->phone->parse("+" . ltrim($request["recipient"], "+"), $api["country"]);

                        if(!$number->isValidNumber() && $number->getRegionCode() != "BR")
                            response(400, "Invalid phone number!");

                        $request["recipient"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

                        if($number->getRegionCode() == "MX"):
                            $request["recipient"] = formatMexicoNumWa($request["recipient"]);
                        endif;
                    } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                        response(400, "Invalid phone number!");
                    }
                endif;

                if(in_array($request["type"], ["text"])):
                    if(!$this->sanitize->length($request["message"], system_message_min))
                        response(400, "Chat message is too short!");

                    if(system_message_max > 0):
                        if($this->sanitize->length($request["message"], system_message_max, 2)):
                            response(400, "Chat message is too long!");
                        endif;
                    endif;

                    if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
                        $this->system->create("logger", [
                            "uid" => $api["uid"],
                            "type" => "ai_moderation",
                            "reason" => "Inappropriate Content!",
                            "content" => "Type: WhatsApp, Message: {$request["message"]}",
                            "create_date" => date("Y-m-d H:i:s", time())
                        ]);

                        response(400, "We detected inappropriate content in your message!");
                    endif;
                endif;

                if(isset($request["shortener"])):
                    if(!$this->sanitize->isInt($request["shortener"]))
                        response(400, "Invalid Parameters!");

                    if($request["shortener"] > 0):
                        if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
                            response(404, "Specified shortener doesn't exist!");

                        $messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

                        if(!empty($messageLinks)):
                            try {
                                require "system/shorteners/" . md5($request["shortener"]) . ".php";
                            } catch(Exception $e){
                                response(500, "We encountered a shortener error!");
                            }

                            foreach($messageLinks as $key => $value):
                                $shortLink = shortenUrl($value, $this);

                                if($shortLink):
                                    $request["message"] = str_replace($value, $shortLink, $request["message"]);
                                endif;
                            endforeach;
                        endif;
                    endif;
                endif;

                $request["message"] = $this->spintax->process(footermark($subscription["footermark"], $request["message"], system_message_mark));

                switch($request["type"]):
                    case "media":
                        if(isset($request["media_url"])):
                            if(!$this->sanitize->isUrl($request["media_url"]))
                                response(400, "You provided an invalid media url!");

                            if(!isset($request["media_type"]))
                                response(400, "Please declare the media type!");

                            if($request["media_type"] == "image"):
                                // image
                                $message = [
                                    "image" => [
                                        "url" => $request["media_url"]
                                    ],
                                    "caption" => $request["message"]
                                ];

                                if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], $request["media_url"])):
                                    $this->system->create("logger", [
                                        "uid" => $api["uid"],
                                        "type" => "ai_moderation",
                                        "reason" => "Inappropriate Content!",
                                        "content" => empty($request["message"]) ? "Type: WhatsApp, Media: <a href=\"{$request["media_url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>" : "Type: WhatsApp, Message: {$request["message"]}, Media: <a href=\"{$request["media_url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>",
                                        "create_date" => date("Y-m-d H:i:s", time())
                                    ]);

                                    response(400, "We detected inappropriate content in your media!");
                                endif;
                            elseif($request["media_type"] == "audio"):
                                // audio
                                $message = [
                                    "audio" => [
                                        "url" => $request["media_url"]
                                    ],
                                    "caption" => false
                                ];
                            else:
                                // video
                                $message = [
                                    "video" => [
                                        "url" => $request["media_url"]
                                    ],
                                    "caption" => $request["message"]
                                ];
                            endif;
                        else:
                            try {
                                $this->upload->upload($_FILES["media_file"]);
                                if($this->upload->uploaded):
                                    if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4", "mp3", "ogg"]))
                                        response(400, "Invalid media file!");
                                
                                    $mediaName = "{$api["hash"]}_" . uniqid($api["hash"], true);
                                
                                    $this->upload->mime_check = false;
                                    $this->upload->file_new_name_body = $mediaName;
                                    $this->upload->file_overwrite = true;
                                    $this->upload->process("uploads/whatsapp/sent/{$api["uid"]}/");
                                
                                    if($this->upload->processed):
                                        if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
                                            // image
                                            $message = [
                                                "image" => [
                                                    "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
                                                ],
                                                "caption" => $request["message"]
                                            ];

                                            if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], $message["image"]["url"])):
                                                $this->system->create("logger", [
                                                    "uid" => $api["uid"],
                                                    "type" => "ai_moderation",
                                                    "reason" => "Inappropriate Content!",
                                                    "content" => empty($request["message"]) ? "Type: WhatsApp, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>" : "Type: WhatsApp, Message: {$request["message"]}, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>",
                                                    "create_date" => date("Y-m-d H:i:s", time())
                                                ]);

                                                response(400, "We detected inappropriate content in your media!");
                                            endif;
                                        elseif(in_array($this->upload->file_src_name_ext, ["mp3", "ogg"])):
                                            // audio
                                            $message = [
                                                "audio" => [
                                                    "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
                                                ],
                                                "caption" => false
                                            ];
                                        else:
                                            // video
                                            $message = [
                                                "video" => [
                                                    "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
                                                ],
                                                "caption" => $request["message"]
                                            ];
                                        endif;
                                
                                        $this->upload->clean();
                                    else:
                                        response(400, "Invalid media file!");
                                    endif;
                                else:
                                    response(400, "Invalid media file!");
                                endif;
                            } catch(Exception $e){
                                response(400, "Invalid media file!");
                            }
                        endif;

                        break;
                    case "document":
                        if(isset($request["document_url"])):
                            if(!$this->sanitize->isUrl($request["document_url"]))
                                response(400, "You provided an invalid document url!");

                            if(!isset($request["document_type"]))
                                response(400, "Please declare the document type!");

                            switch($request["document_type"]):
                                case "pdf":
                                    $docMimetype = "application/pdf";

                                    break;
                                case "xml":
                                    $docMimetype = "application/xml";
                                    
                                    break;
                                case "xls":
                                    $docMimetype = "application/excel";
                                    
                                    break;
                                case "xlsx":
                                    $docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
                                    
                                    break;
                                case "doc":
                                    $docMimetype = "application/msword";
                                    
                                    break;
                                case "docx":
                                    $docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
                                    
                                    break;
                                default:
                                    response(400, "Invalid Document Type!");
                            endswitch;

                            if(isset($request["document_name"]) & !empty($request["document_name"])):
                                $message = [
                                    "document" => [
                                        "url" => $request["document_url"]
                                    ],
                                    "fileName" => $request["document_name"],
                                    "mimetype" => $docMimetype,
                                    "caption" => $request["message"]
                                ];
                            else:
                                $message = [
                                    "document" => [
                                        "url" => $request["document_url"]
                                    ],
                                    "mimetype" => $docMimetype,
                                    "caption" => $request["message"]
                                ];
                            endif;
                        else:
                            try {
                                $this->upload->upload($_FILES["document_file"]);
                                if($this->upload->uploaded):
                                    switch($this->upload->file_src_name_ext):
                                        case "pdf":
                                            $docMimetype = "application/pdf";

                                            break;
                                        case "xml":
                                            $docMimetype = "application/xml";
                                            
                                            break;
                                        case "xls":
                                            $docMimetype = "application/excel";
                                            
                                            break;
                                        case "xlsx":
                                            $docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
                                            
                                            break;
                                        case "doc":
                                            $docMimetype = "application/msword";
                                            
                                            break;
                                        case "docx":
                                            $docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
                                            
                                            break;
                                        default:
                                            response(400, "Invalid Document File!");
                                    endswitch;

                                    $fileName = $this->upload->file_src_name;
                                    $docName = "{$api["hash"]}_" . uniqid($api["hash"], true);

                                    $this->upload->mime_check = false;
                                    $this->upload->file_new_name_body = $docName;
                                    $this->upload->file_overwrite = true;
                                    $this->upload->process("uploads/whatsapp/sent/{$api["uid"]}/");

                                    if($this->upload->processed):
                                        $message = [
                                            "document" => [
                                                "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$docName}.{$this->upload->file_src_name_ext}", true)
                                            ],
                                            "fileName" => $fileName,
                                            "mimetype" => $docMimetype,
                                            "caption" => $request["message"]
                                        ];

                                        $this->upload->clean();
                                    else:
                                        response(400, "Invalid Document File!");
                                    endif;
                                else:
                                    response(400, "Invalid Document File!");
                                endif;
                            } catch(Exception $e){
                                response(400, "Invalid Document File!");
                            }
                        endif;

                        break;
                    default:
                        $message = [
                            "text" => $request["message"]
                        ];
                endswitch;

                if(!isset($message))
					response(500, "Sorry, we are unable to process your message.");

                $filtered = [
                    "cid" => 0,
                    "uid" => $api["uid"],
                    "wid" => $account["wid"],
                    "unique" => $account["unique"],
                    "phone" => $request["recipient"],
                    "message" => json_encode($message),
                    "status" => 1,
                    "priority" => $request["priority"] < 2 ? 1 : 2,
                    "api" => 1,
                    "create_date" => date("Y-m-d H:i:s", time())
                ];
                
                $create = $this->system->create("wa_sent", $filtered);

                if($create):
                    if($filtered["priority"] < 2):
                        $sendPriority = $this->wa->sendPriority($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], $create, $filtered["phone"], $filtered["message"]);
                        
                        if($sendPriority):
                            if($sendPriority == 200):
                                $this->system->increment($api["uid"], "wa_sent");

                                response(200, "WhatsApp chat has been sent!", [
                                    "messageId" => $create
                                ]);
                            endif;
                        endif;

                        $this->system->update($create, $api["uid"], "wa_sent", [
                            "status" => 4
                        ]);

                        response(500, "Failed sending priority WhatsApp chat!");
                    else:
                        $addQueue = $this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

                        if($addQueue):
                            response(200, "WhatsApp chat has been queued for sending!", [
                                "messageId" => $create
                            ]);
                        else:
                            response(500, "Unable to connect to WhatsApp servers!", [
                                "messageId" => $create
                            ]);
                        endif;
                    endif;
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "whatsapp.bulk":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("wa_send_bulk", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["account"], $request["campaign"], $request["message"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->length($request["campaign"]))
                    response(500, "Campaign name is too short!");

                if(isset($request["type"]) && !in_array($request["type"], ["text", "media", "document"])):
                    $request["type"] = "text";
                endif;
    
                if(!isset($request["type"])):
                    $request["type"] = "text";
                endif;

                if(!isset($request["recipients"]) && !isset($request["groups"]))
                    response(400, "Invalid Parameters!");

                if(in_array($request["type"], ["text"])):
                    if(!$this->sanitize->length($request["message"], system_message_min))
                        response(400, "Message is too short!");

                    if(system_message_max > 0):
                        if($this->sanitize->length($request["message"], system_message_max, 2)):
                            response(400, "Message is too long!");
                        endif;
                    endif;

                    if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], false)):
                        $this->system->create("logger", [
                            "uid" => $api["uid"],
                            "type" => "ai_moderation",
                            "reason" => "Inappropriate Content!",
                            "content" => "Type: WhatsApp Bulk, Message: {$request["message"]}",
                            "create_date" => date("Y-m-d H:i:s", time())
                        ]);

                        response(400, "We detected inappropriate content in your message!");
                    endif;
                endif;

                if(empty($subscription))
                    response(403, "You are not subscribed to any premium package!");

                if($this->system->checkWaAccount($api["uid"], $request["account"], "unique") < 1)
                    response(404, "WhatsApp account doesn't exist!");

                if($this->system->checkQuota($api["uid"]) < 1):
                    $this->system->create("quota", [
                        "uid" => $api["uid"],
                        "sent" => 0,
                        "received" => 0,
                        "wa_sent" => 0,
                        "wa_received" => 0,
                        "ussd" => 0
                    ]);
                endif;

                $waServer = $this->system->getWaServer($request["account"], "unique");

                if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
                    response(500, "Unable to connect to WhatsApp servers!");

                $account = $this->system->getWaAccount($api["uid"], $request["account"], "unique");

                $status = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

                if(!$status || !in_array($status, ["connected"]))
                    response(500, "WhatsApp account is not connected!");

                $contactBook = [];

                if(isset($request["recipients"])):
                    $numbers = explode(",", trim($request["recipients"]));

                    if(is_array($numbers) && !empty($numbers) && !empty($numbers[0])):
                        foreach($numbers as $number):
                            $rejected = false;

                            if(!find("@g.us", $number)):
                                try {
                                    $phone = $this->phone->parse("+" . ltrim($number, "+"), $api["country"]);

                                    if(!$phone->isValidNumber() && $phone->getRegionCode() != "BR")
                                        $rejected = true;

                                    $phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

                                    if($phone->getRegionCode() == "MX"):
                                        $phoneNumber = formatMexicoNumWa($phoneNumber);
                                    endif;
                                } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                                    $rejected = true;
                                }
                            else:
                                $phoneNumber = $number;
                            endif;

                            if(!$rejected):
                                $contactBook[] = [
                                    "name" => $phoneNumber,
                                    "phone" => $phoneNumber,
                                    "group" => "Unknown"
                                ];
                            endif;
                        endforeach;
                    endif;
                endif;

                if(isset($request["groups"])):
                    $groups = explode(",", trim($request["groups"]));

                    if(is_array($groups) && !empty($groups) && !empty($groups[0])):
                        foreach($groups as $group):
                            if($this->system->checkGroup($api["uid"], $group) > 0):
                                $contacts = $this->system->getContactsByGroup($api["uid"], $group);
                                
                                if(!empty($contacts)):
                                    foreach($contacts as $contact):
                                        try {
                                            $phone = $this->phone->parse($contact["phone"]);

                                            $phoneNumber = $phone->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

                                            if($phone->getRegionCode() == "MX"):
                                                $phoneNumber = formatMexicoNumWa($phoneNumber);
                                            endif;
                                        } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                                            // ignore
                                        }

                                        $contactBook[] = [
                                            "name" => $contact["name"],
                                            "phone" => $phoneNumber,
                                            "group" => $contact["group"]
                                        ];
                                    endforeach;
                                endif;
                            endif;
                        endforeach;
                    endif;
                endif;

                if(empty($contactBook))
                    response(400, "Invalid Parameters!");

                if(isset($request["shortener"])):
                    if(!$this->sanitize->isInt($request["shortener"]))
                        response(400, "Invalid Parameters!");

                    if($request["shortener"] > 0):
                        if(!$this->file->exists("system/shorteners/" . md5($request["shortener"]) . ".php"))
                            response(404, "Specified shortener doesn't exist!");

                        $messageLinks = (new VStelmakh\UrlHighlight\UrlHighlight)->getUrls($request["message"]);

                        if(!empty($messageLinks)):
                            try {
                                require "system/shorteners/" . md5($request["shortener"]) . ".php";
                            } catch(Exception $e){
                                response(500, "We encountered a shortener error!");
                            }

                            foreach($messageLinks as $key => $value):
                                $shortLink = shortenUrl($value, $this);

                                if($shortLink):
                                    $request["message"] = str_replace($value, $shortLink, $request["message"]);
                                endif;
                            endforeach;
                        endif;
                    endif;
                endif;

                switch($request["type"]):
                    case "media":
                        if(isset($request["media_url"])):
                            if(!$this->sanitize->isUrl($request["media_url"]))
                                response(400, "You provided an invalid media url!");

                            if(!isset($request["media_type"]))
                                response(400, "Please declare the media type!");

                            if($request["media_type"] == "image"):
                                // image
                                $message = [
                                    "image" => [
                                        "url" => $request["media_url"]
                                    ],
                                    "caption" => $request["message"]
                                ];

                                if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], $request["media_url"])):
                                    $this->system->create("logger", [
                                        "uid" => $api["uid"],
                                        "type" => "ai_moderation",
                                        "reason" => "Inappropriate Content!",
                                        "content" => empty($request["message"]) ? "Type: WhatsApp Bulk, Media: <a href=\"{$request["media_url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>" : "Type: WhatsApp Bulk, Message: {$request["message"]}, Media: <a href=\"{$request["media_url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>",
                                        "create_date" => date("Y-m-d H:i:s", time())
                                    ]);

                                    response(400, "We detected inappropriate content in your media!");
                                endif;
                            elseif($request["media_type"] == "audio"):
                                // audio
                                $message = [
                                    "audio" => [
                                        "url" => $request["media_url"]
                                    ],
                                    "caption" => false
                                ];
                            else:
                                // video
                                $message = [
                                    "video" => [
                                        "url" => $request["media_url"]
                                    ],
                                    "caption" => $request["message"]
                                ];
                            endif;
                        else:
                            try {
                                $this->upload->upload($_FILES["media_file"]);
                                if($this->upload->uploaded):
                                    if(!in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif", "mp4", "mp3", "ogg"]))
                                        response(400, "Invalid media file!");

                                    $mediaName = "{$api["hash"]}_" . uniqid($api["hash"], true);

                                    $this->upload->mime_check = false;
                                    $this->upload->file_new_name_body = $mediaName;
                                    $this->upload->file_overwrite = true;
                                    $this->upload->process("uploads/whatsapp/sent/{$api["uid"]}/");

                                    if($this->upload->processed):
                                        if(in_array($this->upload->file_src_name_ext, ["jpg", "jpeg", "png", "gif"])):
                                            // image
                                            $message = [
                                                "image" => [
                                                    "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
                                                ],
                                                "caption" => $request["message"]
                                            ];

                                            if(system_ai_moderation < 2 && $this->titansys->aiModeration(system_openai_apikey, $request["message"], $message["image"]["url"])):
                                                $this->system->create("logger", [
                                                    "uid" => $api["uid"],
                                                    "type" => "ai_moderation",
                                                    "reason" => "Inappropriate Content!",
                                                    "content" => empty($request["message"]) ? "Type: WhatsApp Bulk, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>" : "Type: WhatsApp Bulk, Message: {$request["message"]}, Media: <a href=\"{$message["image"]["url"]}\" class=\"btn btn-sm btn-primary\" target=\"_blank\">Open Link</a>",
                                                    "create_date" => date("Y-m-d H:i:s", time())
                                                ]);

                                                response(400, "We detected inappropriate content in your media!");
                                            endif;
                                        elseif(in_array($this->upload->file_src_name_ext, ["mp3", "ogg"])):
                                            // audio
                                            $message = [
                                                "audio" => [
                                                    "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
                                                ],
                                                "caption" => false
                                            ];
                                        else:
                                            // video
                                            $message = [
                                                "video" => [
                                                    "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$mediaName}.{$this->upload->file_src_name_ext}", true)
                                                ],
                                                "caption" => $request["message"]
                                            ];
                                        endif;

                                        $this->upload->clean();
                                    else:
                                        response(500, "Invalid media file!");
                                    endif;
                                else:
                                    response(500, "Invalid media file!");
                                endif;
                            } catch(Exception $e){
                                response(500, "Invalid media file!");
                            }
                        endif;

                        break;
                    case "document":
                        if(isset($request["document_url"])):
                            if(!$this->sanitize->isUrl($request["document_url"]))
                                response(400, "You provided an invalid document url!");

                            if(!isset($request["document_type"]))
                                response(400, "Please declare the document type!");

                            switch($request["document_type"]):
                                case "pdf":
                                    $docMimetype = "application/pdf";

                                    break;
                                case "xml":
                                    $docMimetype = "application/xml";
                                    
                                    break;
                                case "xls":
                                    $docMimetype = "application/excel";
                                    
                                    break;
                                case "xlsx":
                                    $docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
                                    
                                    break;
                                case "doc":
                                    $docMimetype = "application/msword";
                                    
                                    break;
                                case "docx":
                                    $docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
                                    
                                    break;
                                default:
                                    response(400, "Invalid Document Type!");
                            endswitch;

                            if(isset($request["document_name"]) & !empty($request["document_name"])):
                                $message = [
                                    "document" => [
                                        "url" => $request["document_url"]
                                    ],
                                    "fileName" => $request["document_name"],
                                    "mimetype" => $docMimetype,
                                    "caption" => $request["message"]
                                ];
                            else:
                                $message = [
                                    "document" => [
                                        "url" => $request["document_url"]
                                    ],
                                    "mimetype" => $docMimetype,
                                    "caption" => $request["message"]
                                ];
                            endif;
                        else:
                            try {
                                $this->upload->upload($_FILES["document_file"]);
                                if($this->upload->uploaded):
                                    switch($this->upload->file_src_name_ext):
                                        case "pdf":
                                            $docMimetype = "application/pdf";

                                            break;
                                        case "xml":
                                            $docMimetype = "application/xml";
                                            
                                            break;
                                        case "xls":
                                            $docMimetype = "application/excel";
                                            
                                            break;
                                        case "xlsx":
                                            $docMimetype = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
                                            
                                            break;
                                        case "doc":
                                            $docMimetype = "application/msword";
                                            
                                            break;
                                        case "docx":
                                            $docMimetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
                                            
                                            break;
                                        default:
                                            response(400, "Invalid Document File!");
                                    endswitch;
                                    
                                    $fileName = $this->upload->file_src_name;
                                    $docName = "{$api["hash"]}_" . uniqid($api["hash"], true);

                                    $this->upload->mime_check = false;
                                    $this->upload->file_new_name_body = $docName;
                                    $this->upload->file_overwrite = true;
                                    $this->upload->process("uploads/whatsapp/sent/{$api["uid"]}/");

                                    if($this->upload->processed):
                                        $message = [
                                            "document" => [
                                                "url" => site_url("uploads/whatsapp/sent/{$api["uid"]}/{$docName}.{$this->upload->file_src_name_ext}", true)
                                            ],
                                            "fileName" => $fileName,
                                            "mimetype" => $docMimetype,
                                            "caption" => $request["message"]
                                        ];

                                        $this->upload->clean();
                                    else:
                                        response(400, "Invalid Document File!");
                                    endif;
                                else:
                                    response(400, "Invalid Document File!");
                                endif;
                            } catch(Exception $e){
                                response(400, "Invalid Document File!");
                            }
                        endif;

                        break;
                    default:
                        $message = [
                            "text" => $request["message"]
                        ];
                endswitch;

                if(!isset($message))
					response(500, "Sorry, we are unable to process your message.");

                $waCampaign = $this->system->create("wa_campaigns", [
                    "uid" => $api["uid"],
                    "wid" => $account["wid"],
                    "unique" => $account["unique"],
                    "type" => $request["type"],
                    "status" => 1,
                    "name" => $request["campaign"],
                    "contacts" => count($contactBook),
                    "processed" => 0,
                    "create_date" => date("Y-m-d H:i:s", time())
                ]);

                $sendCounter = 0;

                $msgText = isset($message["text"]) ? $message["text"] : $message["caption"];

                $sendIdContainer = [];

                foreach($contactBook as $contact):
                    if(!limitation($subscription["wa_send_limit"], $this->system->countQuota($api["uid"], "wa_sent"))):
                        if(isset($message["text"]) || isset($message["caption"])):
                            $messageContainer = $message;

                            $formatMessage = $this->spintax->process($this->lex->parse(footermark($subscription["footermark"], $msgText, system_message_mark), [
                                "contact" => [
                                    "name" => $contact["name"],
                                    "number" => $contact["phone"]
                                ],
                                "group" => [
                                    "name" => $contact["group"]
                                ],
                                "unsubscribe" => [
                                    "command" => "STOP",
                                    "link" => site_url("unsubscribe/{$api["uid"]}/{$contact["phone"]}", true)
                                ],
                                "date" => [
                                    "now" => date("F j, Y"),
                                    "time" => date("h:i A") 
                                ]
                            ]));

                            if(isset($messageContainer["text"])):
                                $messageContainer["text"] = $formatMessage;
                            else:
                                $messageContainer["caption"] = $formatMessage;
                            endif;
                        endif;

                        $sendId = $this->system->create("wa_sent", [
                            "cid" => $waCampaign,
                            "uid" => $api["uid"],
                            "wid" => $account["wid"],
                            "unique" => $account["unique"],
                            "phone" => $contact["phone"],
                            "message" => json_encode($messageContainer),
                            "status" => 1,
                            "priority" => 2,
                            "api" => 1,
                            "create_date" => date("Y-m-d H:i:s", time())
                        ]);

                        if($sendId):
                            $sendIdContainer[] = $sendId;
                            $sendCounter++;
                        endif;
                    endif;
                endforeach;

                if($sendCounter > 0):
                    $addQueue = $this->wa->send($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);

                    if($addQueue):
                        if($addQueue == 200):
                            response(200, "{$sendCounter} chats has been queued for sending!", [
                                "campaignId" => $waCampaign,
                                "messageIds" => $sendIdContainer
                            ]);
                        else:
                            response(500, "Failed adding chats to WhatsApp queue!", [
                                "campaignId" => $waCampaign,
                                "messageIds" => $sendIdContainer
                            ]);
                        endif;
                    else:
                        response(500, "Unable to connect to WhatsApp servers!", [
                            "campaignId" => $waCampaign,
                            "messageIds" => $sendIdContainer
                        ]);
                    endif;
                else:
                    response(500, "No chats were queued. Please check the sending limit of your package!");
                endif;

                break;
            case "ussd":
                if(!in_array("android_ussd", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use Android USSD service!");

                if(!in_array("ussd", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["code"], $request["sim"], $request["device"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["sim"]))
                    response(400, "Invalid Parameters!");

                if(empty($subscription))
                    response(403, "You are not subscribed to any premium package!");

                if(limitation($subscription["ussd_limit"], $this->system->countQuota($api["uid"], "ussd")))
                    response(403, "Maximum number of USSD requests has been reached!");

                if($this->system->checkDevice($api["uid"], $request["device"], "did") < 1)
                    response(404, "Device doesn't exist!");

                $device = $this->system->getDevice($api["uid"], $request["device"], "did");

                if(!$device)
                    response(404, "Device doesn't exist!");

                $filtered = [
                    "uid" => $api["uid"],
                    "code" => $request["code"],
                    "did" => $request["device"],
                    "sim" => $request["sim"] < 2 ? 1 : 2,
                    "response" => false,
                    "status" => 1,
                    "create_date" => date("Y-m-d H:i:s", time())
                ];

                if($this->system->create("ussd", $filtered)):
                    // TODO: Send USSD request fcm notification

                    response(200, "USSD request has been queued!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
    }

    public function remote()
    {
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $request = $this->sanitize->array($_REQUEST);
        $service = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["secret"]))
            response(400, "Invalid Parameters!");

        if($this->api->checkApikey($request["secret"]) < 1)
            response(401, "Invalid API secret supplied!");

        $api = $this->api->getApikey($request["secret"]);

        $permissions = explode(",", $api["permissions"]);

        if(!is_array($permissions))
            response(403, "Insufficient Permissions!");

        switch($service):
            case "start.sms":
                if(!in_array("start_sms_campaign", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if($this->api->checkSmsCampaign($request["campaign"], $api["uid"]) < 1)
                    response(400, "Invalid Parameters!");

                $campaign = $this->api->getSmsCampaign($request["campaign"], $api["uid"]);

                if($campaign["mode"] > 1 && $this->sanitize->isInt($campaign["gateway"]))
                    response(200, "Action is not supported in this campaign!");

                if($this->system->update($request["campaign"], $api["uid"], "campaigns", [
                    "status" => 1
                ])):
                    $device = $this->system->getDevice($api["uid"], $campaign["device_uid"], "did");
                    
                    $this->fcm->sendWithToken($device["fcm_token"], [
                        "action" => "campaign_request",
                        "campaign_id" => (int) $campaign["id"],
                        "campaign_status" => "start"
                    ]);
                endif;

                response(200, "SMS campaign has been resumed!");

                break;
            case "stop.sms":
                if(!in_array("stop_sms_campaign", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if($this->api->checkSmsCampaign($request["campaign"], $api["uid"]) < 1)
                    response(400, "Invalid Parameters!");

                $campaign = $this->api->getSmsCampaign($request["campaign"], $api["uid"]);

                if($campaign["mode"] > 1 && $this->sanitize->isInt($campaign["gateway"]))
                    response(200, "Action is not supported in this campaign!");
                
                if($this->system->update($request["campaign"], $api["uid"], "campaigns", [
                    "status" => 2
                ])):
                    $device = $this->system->getDevice($api["uid"], $campaign["device_uid"], "did");
                        
                    $this->fcm->sendWithToken($device["fcm_token"], [
                        "action" => "campaign_request",
                        "campaign_id" => (int) $campaign["id"],
                        "campaign_status" => "stop"
                    ]);
                endif;

                response(200, "SMS campaign has been paused!");

                break;
            case "start.chats":
                if(!in_array("start_wa_campaign", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if($this->api->checkWaCampaign($request["campaign"], $api["uid"]) < 1)
                    response(400, "Invalid Parameters!");

                $campaign = $this->api->getWaCampaign($request["campaign"], $api["uid"]);

                if($this->system->update($request["campaign"], $api["uid"], "wa_campaigns", [
                    "status" => 1
                ])):
                    try {
                        $account = $this->system->getWaAccount($api["uid"], $campaign["wid"], "wid");
                        $waServer = $this->system->getWaServer($account["unique"], "unique");

                        $this->wa->start_campaign($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], $api["hash"], $request["campaign"]);
                    } catch(Exception $e){
                        // Ignore
                    }
                endif;

                response(200, "WhatsApp campaign has been resumed!");

                break;
            case "stop.chats":
                if(!in_array("stop_wa_campaign", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["campaign"]))
                    response(400, "Invalid Parameters!");

                if($this->api->checkWaCampaign($request["campaign"], $api["uid"]) < 1)
                    response(400, "Invalid Parameters!");

                $campaign = $this->api->getWaCampaign($request["campaign"], $api["uid"]);
                
                if($this->system->update($request["campaign"], $api["uid"], "wa_campaigns", [
                    "status" => 2
                ])):
                    try {
                        $account = $this->system->getWaAccount($api["uid"], $campaign["wid"], "wid");
                        $waServer = $this->system->getWaServer($account["unique"], "unique");

                        $this->wa->stop_campaign($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"], $api["hash"], $request["campaign"]);
                    } catch(Exception $e){
                        // Ignore
                    }
                endif;

                response(200, "WhatsApp campaign has been paused!");

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
    }

    public function validate()
    {
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $request = $this->sanitize->array($_REQUEST);
        $service = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["secret"]))
            response(400, "Invalid Parameters!");

        if($this->api->checkApikey($request["secret"]) < 1)
            response(401, "Invalid API secret supplied!");

        $api = $this->api->getApikey($request["secret"]);

        $permissions = explode(",", $api["permissions"]);

        if(!is_array($permissions))
            response(403, "Insufficient Permissions!");

        switch($service):
            case "whatsapp":
                if(!in_array("validate_wa_phone", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["unique"], $request["phone"]))
                    response(400, "Invalid Parameters!");

                if($this->system->checkWaAccount($api["uid"], $request["unique"], "unique") < 1)
                    response(404, "WhatsApp account doesn't exist!");
                
                $waServer = $this->system->getWaServer($request["unique"], "unique");
                
                if($waServer && !$this->wa->check($waServer["url"], $waServer["port"]))
                    response(500, "Unable to connect to WhatsApp servers!");

                $validate = $this->wa->validate($waServer["secret"], $waServer["url"], $waServer["port"], $request["unique"], $request["phone"]);

                response($validate ? 200 : 404, 
                    $validate ? "WhatsApp phone number is valid!" : "WhatsApp phone number does not exist!", 
                    $validate ? $validate : false
                );

                break;
            default:
                response(500, "Invalid API Endpoint!");
        endswitch;
    }

	public function get()
	{
		$this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $request = $this->sanitize->array($_REQUEST);
        $service = $this->sanitize->string($this->url->segment(4));

        if(!in_array($service, ["wa.qr", "wa.info"])):
            if(!isset($request["secret"])):
                response(400, "Invalid Parameters!");
            endif;
        else:
            if(!isset($request["token"])):
                response(400, "Invalid Parameters!");
            endif;

            if(in_array($service, ["wa.qr", "wa.info"])):
                $this->cache->container("system.whatsapp", true);
            endif;

            if(!$this->cache->has($request["token"])):
                response(401, "Invalid token supplied!");
            endif;

            $qrToken = $this->cache->get($request["token"]);

            $request["secret"] = $qrToken["secret"];
            $request["qrstring"] = $qrToken["qrstring"];

            if($service == "wa.info" && isset($qrToken["wa_info"])):
                $request["wa_info"] = $qrToken["wa_info"];
            endif;
        endif;

        if($this->api->checkApikey($request["secret"]) < 1)
            response(401, "Invalid API secret supplied!");

        $api = $this->api->getApikey($request["secret"]);

        $permissions = explode(",", $api["permissions"]);

        if(!is_array($permissions))
            response(403, "Insufficient Permissions!");

        $subscription = set_subscription(
            $this->system->checkSubscription($api["uid"]), 
            $this->system->getSubscription(false, $api["uid"]), 
            $this->system->getSubscription(false, false, true)
        );

        switch($service):
            case "otp":
                if(!in_array("otp", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["otp"]))
                    response(400, "Invalid Parameters!");

                $this->cache->container("api.otp.{$request["secret"]}", true);

                if($this->cache->has($request["otp"])):
                    $this->cache->delete($request["otp"]);
                    response(200, "OTP has been verified!");
                endif;

                response(403, "OTP is invalid or expired!");

                break;
            case "sms.message":
                if(!isset($request["id"], $request["type"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!in_array($request["type"], ["sent", "received"]))
                    response(400, "Invalid Parameters!");

                if(!in_array("get_message", explode(",", $api["permissions"])))
                        response(403, "This API key doesn't have permission to use this endpoint!");

                if($request["type"] == "sent"):
                    if(!in_array("get_sms_sent", explode(",", $api["permissions"])))
                        response(403, "This API key doesn't have permission to use this endpoint!");

                    if($this->api->checkSmsSent($request["id"], $api["uid"]) < 1)
                        response(404, "Message doesn't exist!");
                    
                    $smsSent = $this->api->getSmsSent($request["id"], $api["uid"]);
                    
                    if($smsSent):
                        $sentFilter = [
                            "id" => (int) $smsSent["id"],
                            "mode" => $smsSent["mode"] < 2 ? "devices" : "credits",
                            "sender" => $smsSent["mode"] < 2 ? $smsSent["did"] : ($smsSent["gateway"] > 0 ? $smsSent["gateway_name"] : $smsSent["did"]),
                            "sender_type" => $smsSent["mode"] < 2 ? "device" : ($smsSent["gateway"] > 0 ? "gateway" : "partner_device"),
                            "sim" => (int) ($smsSent["mode"] < 2 ? $smsSent["sim"] : ($smsSent["gateway"] > 0 ? 0 : $smsSent["sim"])),
                            "priority" => $smsSent["mode"] < 2 ? ($smsSent["priority"] < 2 ? false : true) : ($smsSent["gateway"] > 0 ? false : ($smsSent["priority"] < 2 ? false : true)),
                            "api" => $smsSent["api"] < 2 ? true : false,
                            "status" => smsStatusParser($smsSent["status"]),
                            "status_code" => $smsSent["status_code"],
                            "recipient" => $smsSent["phone"],
                            "message" => $smsSent["message"],
                            "created" => strtotime($smsSent["create_date"])
                        ];

                        response(200, "Message was found!", $sentFilter);
                    else:
                        response(500, "Something went wrong!");
                    endif;
                else:
                    if(!in_array("get_sms_received", explode(",", $api["permissions"])))
                        response(403, "This API key doesn't have permission to use this endpoint!");

                    if($this->api->checkSmsReceived($request["id"], $api["uid"]) < 1)
                        response(404, "Message doesn't exist!");
                    
                    $receivedSent = $this->api->getSmsReceived($request["id"], $api["uid"]);
                    
                    if($receivedSent):
                        $receivedFilter = [
                            "id" => (int) $receivedSent["id"],
                            "device" => $receivedSent["did"],
                            "sender" => $receivedSent["phone"],
                            "message" => $receivedSent["message"],
                            "created" => strtotime($receivedSent["receive_date"])
                        ];

                        response(200, "Message was found!", $receivedFilter);
                    else:
                        response(500, "Something went wrong!");
                    endif;
                endif;

                break;
            case "wa.message":
                if(!isset($request["id"], $request["type"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!in_array($request["type"], ["sent", "received"]))
                    response(400, "Invalid Parameters!");

                if($request["type"] == "sent"):
                    if(!in_array("get_wa_sent", explode(",", $api["permissions"])))
                        response(403, "This API key doesn't have permission to use this endpoint!");

                    if($this->api->checkWaSent($request["id"], $api["uid"]) < 1)
                        response(404, "Message doesn't exist!");
                    
                    $waSent = $this->api->getWaSent($request["id"], $api["uid"]);
                    
                    if($waSent):
                        $account = explode(":", $waSent["wid"]);

                        try {
                            $msgDecode = json_decode($waSent["message"], true, JSON_THROW_ON_ERROR);
                            $waMessage = isset($msgDecode["text"]) ? $msgDecode["text"] : $msgDecode["caption"];
                        } catch(Exception $e){
                            $waMessage = $waSent["message"];
                        }

                        try {
                            $msgDecode = json_decode($waSent["message"], true, JSON_THROW_ON_ERROR);

                            if(isset($msgDecode["image"])):
                                $downloadLink = $msgDecode["image"]["url"];
                            elseif(isset($msgDecode["video"])):
                                $downloadLink = $msgDecode["video"]["url"];
                            elseif(isset($msgDecode["document"])):
                                $downloadLink = $msgDecode["document"]["url"];
                            else:
                                $downloadLink = false;
                            endif;
                        } catch(Exception $e){
                            $downloadLink = false;
                        }

                        switch($waSent["status"]):
                            case 1:
                                $chatStatus = "pending";
                                break;
                            case 2:
                                $chatStatus = "queued";
                                break;
                            case 3:
                                $chatStatus = "sent";
                                break;
                            case 4:
                                $chatStatus = "failed";
                                break;
                            default:
                                $chatStatus = "unknown";
                        endswitch;

                        $sentFilter = [
                            "id" => (int) $waSent["id"],
                            "account" => "+{$account[0]}",
                            "status" => $chatStatus,
                            "api" => $waSent["api"] < 2 ? true : false,
                            "recipient" => $waSent["phone"],
                            "message" => $waMessage,
                            "attachment" => $downloadLink,
                            "created" => strtotime($waSent["create_date"])
                        ];

                        response(200, "Message was found!", $sentFilter);
                    else:
                        response(500, "Something went wrong!");
                    endif;
                else:
                    if(!in_array("get_wa_received", explode(",", $api["permissions"])))
                        response(403, "This API key doesn't have permission to use this endpoint!");

                    if($this->api->checkWaReceived($request["id"], $api["uid"]) < 1)
                        response(404, "Message doesn't exist!");
                    
                    $waReceived = $this->api->getWaReceived($request["id"], $api["uid"]);
                    
                    if($waReceived):
                        $attachmentLink = false;

                        try {
                            $fileName = checkFile($waReceived["id"], "uploads/whatsapp/received/{$waReceived["unique"]}");

                            if($fileName):
                                $attachmentLink = site_url("uploads/whatsapp/received/{$waReceived["unique"]}/{$fileName}", true);
                            endif;
                        } catch(Exception $e){
                            $attachmentLink = false;
                        }

                        $account = explode(":", $waReceived["wid"]);
                        $receivedFilter = [
                            "id" => (int) $waReceived["id"],
                            "account" => "+{$account[0]}",
                            "recipient" => $waReceived["phone"],
                            "message" => $waReceived["message"],
                            "attachment" => $attachmentLink,
                            "created" => strtotime($waReceived["receive_date"])
                        ];

                        response(200, "Message was found!", $receivedFilter);
                    else:
                        response(500, "Something went wrong!");
                    endif;
                endif;

                break;
            case "credits":
                if(!in_array("get_credits", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                response(200, "Remaining Credits", [
                    "credits" => $api["credits"],
                    "currency" => system_currency
                ]);

                break;
            case "earnings":
                if(!in_array("get_earnings", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                response(200, "Partner Earnings", [
                    "earnings" => $api["earnings"],
                    "currency" => system_currency
                ]);

                break;
            case "subscription":
                if(!in_array("get_subscription", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(empty($subscription))
                    response(403, "Account is not subscribed to any premium package!");

                $package = [
                    "name" => $subscription["name"],
                    "usage" => [
                        "sms_send" => [
                            "used" => (int) $this->system->countQuota($api["uid"], "sent"),
                            "limit" => (int) $subscription["send_limit"]
                        ],
                        "sms_receive" => [
                            "used" => (int) $this->system->countQuota($api["uid"], "received"),
                            "limit" => (int) $subscription["receive_limit"]
                        ],
                        "ussd" => [
                            "used" => (int) $this->system->countQuota($api["uid"], "ussd"),
                            "limit" => (int) $subscription["ussd_limit"]
                        ],
                        "contacts" => [
                            "used" => (int) $this->system->countContacts($api["uid"]),
                            "limit" => (int) $subscription["contact_limit"]
                        ],
                        "devices" => [
                            "used" => (int) $this->system->countDevices($api["uid"]),
                            "limit" => (int) $subscription["device_limit"]
                        ],
                        "apikeys" => [
                            "used" => (int) $this->system->countKeys($api["uid"]),
                            "limit" => (int) $subscription["key_limit"]
                        ],
                        "webhooks" => [
                            "used" => (int) $this->system->countWebhooks($api["uid"]),
                            "limit" => (int) $subscription["webhook_limit"]
                        ],
                        "actions" => [
                            "used" => (int) $this->system->countActions($api["uid"]),
                            "limit" => (int) $subscription["action_limit"]
                        ],
                        "scheduled" => [
                            "used" => (int) $this->system->countScheduled($api["uid"]),
                            "limit" => (int) $subscription["scheduled_limit"]
                        ],
                        "wa_send" => [
                            "used" => (int) $this->system->countQuota($api["uid"], "wa_sent"),
                            "limit" => (int) $subscription["wa_send_limit"]
                        ],
                        "wa_receive" => [
                            "used" => (int) $this->system->countQuota($api["uid"], "wa_received"),
                            "limit" => (int) $subscription["wa_receive_limit"]
                        ],
                        "wa_accounts" => [
                            "used" => (int) $this->system->countWaAccounts($api["uid"]),
                            "limit" => (int) $subscription["wa_account_limit"]
                        ]
                    ]
                ];

                response(200, "Subscription Package", $package);

                break;
            case "sms.sent":
                if(!in_array("sms", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use SMS services!");

                if(!in_array("get_sms_sent", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $messages = $this->api->getSms($api["uid"], "sent", "sent", abs($page), abs($limit));

                $messageArray = [];

                if(!empty($messages)):
                    foreach($messages as $message):
                        $messageArray[] = [
                            "id" => (int) $message["id"],
                            "mode" => $message["mode"] < 2 ? "devices" : "credits",
                            "sender" => $message["mode"] < 2 ? $message["did"] : ($message["gateway"] > 0 ? $message["gateway_name"] : $message["did"]),
                            "sender_type" => $message["mode"] < 2 ? "device" : ($message["gateway"] > 0 ? "gateway" : "partner_device"),
                            "sim" => (int) ($message["mode"] < 2 ? $message["sim"] : ($message["gateway"] > 0 ? 0 : $message["sim"])),
                            "priority" => $message["mode"] < 2 ? ($message["priority"] < 2 ? false : true) : ($message["gateway"] > 0 ? false : ($message["priority"] < 2 ? false : true)),
                            "api" => $message["api"] < 2 ? true : false,
                            "status" => smsStatusParser($message["status"]),
                            "status_code" => $message["status_code"],
                            "recipient" => $message["phone"],
                            "message" => $message["message"],
                            "created" => strtotime($message["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Sent SMS Messages", $messageArray);

                break;
            case "sms.campaigns":
                if(!in_array("sms", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use SMS services!");

                if(!in_array("get_sms_campaigns", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $campaigns = $this->api->getSmsCampaigns($api["uid"], abs($page), abs($limit));

                $campaignArray = [];

                if(!empty($campaigns)):
                    foreach($campaigns as $campaign):
                        $pendingSms = $this->api->checkSmsCampaignPending($api["uid"], $campaign["id"]);

                        $campaignArray[] = [
                            "id" => (int) $campaign["id"],
                            "sender" => $campaign["mode"] < 2 ? $campaign["did"] : ($campaign["gateway"] > 0 ? $campaign["gateway"] : $campaign["did"]),
                            "sender_type" => $campaign["mode"] < 2 ? "device" : ($campaign["gateway"] > 0 ? "gateway" : "partner_device"),
                            "status" => $campaign["gateway"] > 0 ? "none" : ($pendingSms < 1 ? "completed" : ($campaign["status"] < 2 ? "running" : "paused")),
                            "name" => $campaign["name"],
                            "contacts" => (int) $campaign["contacts"],
                            "created" => strtotime($campaign["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "SMS Campaigns", $campaignArray);

                break;
            case "sms.pending":
                if(!in_array("sms", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use SMS services!");

                if(!in_array("get_sms_pending", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $messages = $this->api->getSms($api["uid"], "sent", "pending", abs($page), abs($limit));

                $messageArray = [];

                if(!empty($messages)):
                    foreach($messages as $message):
                        $messageArray[] = [
                            "id" => (int) $message["id"],
                            "mode" => $message["mode"] < 2 ? "devices" : "credits",
                            "sender" => $message["mode"] < 2 ? $message["did"] : ($message["gateway"] > 0 ? $message["gateway_name"] : $message["did"]),
                            "sender_type" => $message["mode"] < 2 ? "device" : ($message["gateway"] > 0 ? "gateway" : "partner_device"),
                            "sim" => (int) ($message["mode"] < 2 ? $message["sim"] : ($message["gateway"] > 0 ? 0 : $message["sim"])),
                            "priority" => $message["mode"] < 2 ? ($message["priority"] < 2 ? false : true) : ($message["gateway"] > 0 ? false : ($message["priority"] < 2 ? false : true)),
                            "api" => $message["api"] < 2 ? true : false,
                            "recipient" => $message["phone"],
                            "message" => $message["message"],
                            "created" => strtotime($message["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Pending SMS Messages", $messageArray);

                break;
            case "sms.received":
                if(!in_array("sms", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use SMS services!");

                if(!in_array("get_sms_received", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $messages = $this->api->getSms($api["uid"], "received", false, abs($page), abs($limit));

                $messageArray = [];

                if(!empty($messages)):
                    foreach($messages as $message):
                        $messageArray[] = [
                            "id" => (int) $message["id"],
                            "device" => $message["did"],
                            "sender" => $message["phone"],
                            "message" => $message["message"],
                            "created" => strtotime($message["receive_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Received SMS Messages", $messageArray);

                break;
            case "wa.servers":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("create_whatsapp", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(empty($subscription))
                    response(400, "Account is not subscribed to any premium package!");

                $waServers = $this->system->getWaServers($subscription["pid"]);

                if(empty($waServers))
                    response(404, "No WhatsApp servers available for your subscription!");

                $waServerList = [];

                foreach($waServers as $waServer):
                    $waStatus = $this->wa->check($waServer["url"], $waServer["port"]);
                    $totalConnected = $this->wa->total($waServer["secret"], $waServer["url"], $waServer["port"]);
                    
                    try {
                        $availConnect = $totalConnected <= $waServer["accounts"] ?? false;
                    } catch(Exception $e){
                        $availConnect = false;
                    }

                    $waServerList[] = [
                        "id" => (int) $waServer["id"],
                        "name" => $waServer["name"],
                        "status" => $waStatus ? "online" : "offline",
                        "available" => $availConnect
                    ];
                endforeach;

                response(200, "WhatsApp Servers", $waServerList);

                break;
            case "wa.qr":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("create_whatsapp", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!$this->sanitize->length($request["qrstring"], 200))
                    response(400, "QR string is too short to be valid!");
                
                try {
                    $writer = new PngWriter();

                    $generate = QrCode::create($request["qrstring"])
                        ->setEncoding(new Encoding("UTF-8"))
                        ->setErrorCorrectionLevel(ErrorCorrectionLevel::Low)
                        ->setSize(500)
                        ->setMargin(0)
                        ->setRoundBlockSizeMode(RoundBlockSizeMode::Margin)
                        ->setForegroundColor(new Color(0, 0, 0))
                        ->setBackgroundColor(new Color(255, 255, 255));

                    $qrcode = $writer->write($generate);

                    header("Content-Type: {$qrcode->getMimeType()}");

                    echo $qrcode->getString();

                    die;
                } catch(Exception $e){
                    response(500, "Something went wrong while generating the image!");
                }

                break;
            case "wa.info":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("create_whatsapp", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["wa_info"]))
                    response(301, "Waiting for WhatsApp information!");
                
                response(200, "WhatsApp Information", $request["wa_info"]);

                break;
            case "wa.sent":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("get_wa_sent", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $chats = $this->api->getChats($api["uid"], "sent", "sent", abs($page), abs($limit));

                $chatArray = [];

                if(!empty($chats)):
                    foreach($chats as $chat):
                        $account = explode(":", $chat["wid"]);

                        try {
                            $msgDecode = json_decode($chat["message"], true, JSON_THROW_ON_ERROR);
                            $waMessage = isset($msgDecode["text"]) ? $msgDecode["text"] : $msgDecode["caption"];
                        } catch(Exception $e){
                            $waMessage = $chat["message"];
                        }

                        try {
                            $msgDecode = json_decode($chat["message"], true, JSON_THROW_ON_ERROR);

                            if(isset($msgDecode["image"])):
                                $downloadLink = $msgDecode["image"]["url"];
                            elseif(isset($msgDecode["video"])):
                                $downloadLink = $msgDecode["video"]["url"];
                            elseif(isset($msgDecode["document"])):
                                $downloadLink = $msgDecode["document"]["url"];
                            else:
                                $downloadLink = false;
                            endif;
                        } catch(Exception $e){
                            $downloadLink = false;
                        }

                        $chatArray[] = [
                            "id" => (int) $chat["id"],
                            "account" => "+{$account[0]}",
                            "status" => $chat["status"] < 4 ? "sent" : "failed",
                            "api" => $chat["api"] < 2 ? true : false,
                            "recipient" => $chat["phone"],
                            "message" => $waMessage,
                            "attachment" => $downloadLink,
                            "created" => strtotime($chat["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Sent WhatsApp Chats", $chatArray);

                break;
            case "wa.campaigns":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("get_wa_campaigns", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $campaigns = $this->api->getWaCampaigns($api["uid"], abs($page), abs($limit));

                $campaignArray = [];

                if(!empty($campaigns)):
                    foreach($campaigns as $campaign):
                        $account = explode(":", $campaign["wid"]);
                        $pendingChats = $this->api->checkWaCampaignPending($api["uid"], $campaign["id"]);

                        $campaignArray[] = [
                            "id" => (int) $campaign["id"],
                            "account" => "+{$account[0]}",
                            "type" => $campaign["type"],
                            "status" => $pendingChats < 1 ? "completed" : ($campaign["status"] < 2 ? "running" : "paused"),
                            "name" => $campaign["name"],
                            "contacts" => (int) $campaign["contacts"],
                            "created" => strtotime($campaign["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "WhatsApp Campaigns", $campaignArray);

                break;
            case "wa.pending":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("get_wa_pending", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $chats = $this->api->getChats($api["uid"], "sent", "pending", abs($page), abs($limit));

                $chatArray = [];

                if(!empty($chats)):
                    foreach($chats as $chat):
                        $account = explode(":", $chat["wid"]);

                        try {
                            $msgDecode = json_decode($chat["message"], true, JSON_THROW_ON_ERROR);
                            $waMessage = isset($msgDecode["text"]) ? $msgDecode["text"] : $msgDecode["caption"];
                        } catch(Exception $e){
                            $waMessage = $chat["message"];
                        }

                        try {
                            $msgDecode = json_decode($chat["message"], true, JSON_THROW_ON_ERROR);
                            
                            if(isset($msgDecode["image"])):
                                $downloadLink = $msgDecode["image"]["url"];
                            elseif(isset($msgDecode["video"])):
                                $downloadLink = $msgDecode["video"]["url"];
                            elseif(isset($msgDecode["document"])):
                                $downloadLink = $msgDecode["document"]["url"];
                            else:
                                $downloadLink = false;
                            endif;
                        } catch(Exception $e){
                            $downloadLink = false;
                        }

                        $chatArray[] = [
                            "id" => (int) $chat["id"],
                            "account" => "+{$account[0]}",
                            "api" => $chat["api"] < 2 ? true : false,
                            "recipient" => $chat["phone"],
                            "message" => $waMessage,
                            "attachment" => $downloadLink,
                            "created" => strtotime($chat["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Pending WhatsApp Chats", $chatArray);

                break;
            case "wa.received":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("get_wa_received", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $chats = $this->api->getChats($api["uid"], "received", false, abs($page), abs($limit));

                $chatArray = [];

                if(!empty($chats)):
                    foreach($chats as $chat):
                        $attachmentLink = false;

                        try {
                            $fileName = checkFile($chat["id"], "uploads/whatsapp/received/{$chat["unique"]}");

                            if($fileName):
                                $attachmentLink = site_url("uploads/whatsapp/received/{$chat["unique"]}/{$fileName}", true);
                            endif;
                        } catch(Exception $e){
                            $attachmentLink = false;
                        }

                        $account = explode(":", $chat["wid"]);
                        $chatArray[] = [
                            "id" => (int) $chat["id"],
                            "account" => "+{$account[0]}",
                            "recipient" => $chat["phone"],
                            "message" => $chat["message"],
                            "attachment" => $attachmentLink,
                            "created" => strtotime($chat["receive_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Received WhatsApp Chats", $chatArray);

                break;
            case "wa.groups":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("get_wa_groups", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["unique"])):
                    response(400, "Invalid Parameters!");
                endif;

                $waServer = $this->system->getWaServer($request["unique"], "unique");

                if($waServer):
                    if(!$this->wa->check($waServer["url"], $waServer["port"]))
                        response(500, "Unable to connect to WhatsApp servers!");

                    $getGroups = $this->wa->get_groups($waServer["secret"], $waServer["url"], $waServer["port"], $request["unique"]);

                    $groupArray = [];

                    if($getGroups):
                        if(!empty($getGroups)):
                            foreach($getGroups as $group):
                                $groupArray[] = [
                                    "gid" => $group["id"],
                                    "name" => $group["subject"]
                                ];
                            endforeach;
                        endif;
                    endif;

                    response(200, "WhatsApp Groups", $groupArray);
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "contacts":
                if(!in_array("get_contacts", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $contacts = $this->api->getContacts($api["uid"], abs($page), abs($limit));

                $contactArray = [];

                if(!empty($contacts)):
                    foreach($contacts as $contact):
                        $groups = explode(",", $contact["groups"]);
                        $contactArray[] = [
                            "id" => (int) $contact["id"],
                            "groups" => is_array($groups) ? $groups : [],
                            "phone" => $contact["phone"],
                            "name" => $contact["name"]
                        ];
                    endforeach;
                endif;

                response(200, "Saved Contacts", $contactArray);

                break;
            case "groups":
                if(!in_array("get_groups", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $groups = $this->api->getGroups($api["uid"], abs($page), abs($limit));

                $groupArray = [];

                if(!empty($groups)):
                    foreach($groups as $group):
                        $groupArray[] = [
                            "id" => (int) $group["id"],
                            "name" => $group["name"]
                        ];
                    endforeach;
                endif;

                response(200, "Contact Groups", $groupArray);

                break;
            case "unsubscribed":
                if(!in_array("get_unsubscribed", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $unsubscribed = $this->api->getUnsubscribed($api["uid"], abs($page), abs($limit));

                $unsubscribeArray = [];

                if(!empty($unsubscribed)):
                    foreach($unsubscribed as $unsubscribe):
                        $unsubscribeArray[] = [
                            "id" => (int) $unsubscribe["id"],
                            "phone" => $unsubscribe["phone"],
                            "created" => strtotime($unsubscribe["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Unsubscribed Contacts", $unsubscribeArray);

                break;
            case "ussd":
                if(!in_array("android_ussd", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use Android USSD service!");

                if(!in_array("get_ussd", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $ussds = $this->api->getUssd($api["uid"], abs($page), abs($limit));

                $ussdArray = [];

                if(!empty($ussds)):
                    foreach($ussds as $ussd):
                        $ussdArray[] = [
                            "id" => (int) $ussd["id"],
                            "device" => $ussd["did"],
                            "sim" => $ussd["sim"] < 1 ? 1 : 2,
                            "code" => $ussd["code"],
                            "response" => $ussd["response"],
                            "status" => $ussd["status"] < 2 ? "pending" : ($ussd["status"] == 2 ? "queued" : "completed"),
                            "created" => strtotime($ussd["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "USSD Requests", $ussdArray);

                break;
            case "wa.accounts":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("get_wa_accounts", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $accounts = $this->api->getWaAccounts($api["uid"], abs($page), abs($limit));

                $accountArray = [];

                if(!empty($accounts)):
                    foreach($accounts as $account):
                        $waServer = $this->system->getWaServer($account["unique"], "unique");

                        if($waServer && $this->wa->check($waServer["url"], $waServer["port"])):
                            try {
                                $status = $this->wa->status($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);
        
                                if($status):
                                    switch($status):
                                        case "connected":
                                            $accountStatus = "connected";
                                            break;
                                        case "connecting":
                                            $accountStatus = "connecting";
                                            break;
                                        case "disconnecting":
                                            $accountStatus = "disconnecting";
                                            break;
                                        default:
                                            $accountStatus = "disconnected";
                                    endswitch;
                                else:
                                    $accountStatus = "disconnected";
                                endif;
                            } catch(Exception $e){
                                $accountStatus = "unknown";
                            }
                        else:
                            $accountStatus = "unknown";
                        endif;

                        $phone = explode(":", $account["wid"]);
                        $accountArray[] = [
                            "id" => (int) $account["id"],
                            "phone" => "+{$phone[0]}",
                            "unique" => $account["unique"],
                            "status" => $accountStatus,
                            "created" => strtotime($account["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "WhatsApp Accounts", $accountArray);

                break;
            case "devices":
                if(!in_array("get_devices", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(isset($request["page"])):
                    if(!$this->sanitize->isInt($request["page"]))
                        response(400, "Invalid Parameters!");

                    $page = $request["page"];
                else:
                    $page = 1;
                endif;

                if(isset($request["limit"])):
                    if(!$this->sanitize->isInt($request["limit"]))
                        response(400, "Invalid Parameters!");

                    $limit = $request["limit"];
                else:
                    $limit = 10;
                endif;

                $devices = $this->api->getDevices($api["uid"], abs($page), abs($limit));

                $deviceArray = [];

                if(!empty($devices)):
                    foreach($devices as $device):
                        switch(round((int) $device["version"])):
                            case 4:
                                $android = "Android KitKat";
                                break;
                            case 5:
                                $android = "Android Lollipop";
                                break;
                            case 6:
                                $android = "Android Marshmallow";
                                break;
                            case 7:
                                $android = "Android Nougat";
                                break;
                            case 8:
                                $android = "Android Oreo";
                                break;
                            case 9:
                                $android = "Android Pie";
                                break;
                            case 10:
                                $android = "Android 10";
                                break;
                            case 11:
                                $android = "Android 11";
                                break;
                            case 12:
                                $android = "Android 12";
                                break;
                            case 13:
                                $android = "Android 13";
                                break;
                            case 14:
                                $android = "Android 14";
                                break;
                            case 15:
                                $android = "Android 15";
                                break;
                            case 16:
                                $android = "Android 16";
                                break;
                            default:
                                $android = "Unknown";
                        endswitch;

                        $slots = explode(",", $device["global_slots"]);
                        $currency = country($device["country"])->getCurrency()["iso_4217_code"];

                        $deviceArray[] = [
                            "id" => (int) $device["id"],
                            "unique" => $device["did"],
                            "name" => $device["name"],
                            "version" => $android,
                            "manufacturer" => $device["manufacturer"],
                            "random_send" => $device["random_send"] < 2 ? true : false,
                            "random_min" => (int) $device["random_min"],
                            "random_max" => (int) $device["random_max"],
                            "partner" => $device["global_device"] < 2 ? true : false,
                            "partner_sim" => is_array($slots) ? $slots : [],
                            "partner_priority" => $device["global_priority"] < 2 ? true : false,
                            "partner_country" => strtoupper($device["country"]),
                            "partner_rate" => (float) $device["rate"],
                            "partner_currency" => strtoupper($currency),
                            "created" => strtotime($device["create_date"])
                        ];
                    endforeach;
                endif;

                response(200, "Android Devices", $deviceArray);

                break;
            case "rates":
                if(!in_array("sms", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use SMS services!");

                if(!in_array("get_rates", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                $gateways = $this->api->getGateways();

                $gatewayArray = [];

                if(!empty($gateways)):
                    foreach($gateways as $gateway):
                        $gatewayArray[] = [
                            "id" => (int) $gateway["id"],
                            "name" => $gateway["name"],
                            "currency" => strtoupper(system_currency),
                            "pricing" => json_decode($gateway["pricing"], true)
                        ];
                    endforeach;
                endif;

                $partners = $this->api->getPartners($api["uid"]);

                $partnerArray = [];

                if(!empty($partners)):
                    foreach($partners as $partner):
                        switch(round((int) $partner["version"])):
                            case 4:
                                $android = "Android KitKat";
                                break;
                            case 5:
                                $android = "Android Lollipop";
                                break;
                            case 6:
                                $android = "Android Marshmallow";
                                break;
                            case 7:
                                $android = "Android Nougat";
                                break;
                            case 8:
                                $android = "Android Oreo";
                                break;
                            case 9:
                                $android = "Android Pie";
                                break;
                            case 10:
                                $android = "Android 10";
                                break;
                            case 11:
                                $android = "Android 11";
                                break;
                            case 12:
                                $android = "Android 12";
                                break;
                            case 13:
                                $android = "Android 13";
                                break;
                            case 14:
                                $android = "Android 14";
                                break;
                            case 15:
                                $android = "Android 15";
                                break;
                            case 16:
                                $android = "Android 16";
                                break;
                            default:
                                $android = "Unknown";
                        endswitch;

                        $slots = explode(",", $partner["slots"]);
                        $currency = country($partner["country"])->getCurrency()["iso_4217_code"];

                        $partnerArray[] = [
                            "unique" => $partner["unique"],
                            "name" => $partner["name"],
                            "version" => $android,
                            "priority" => $partner["priority"] < 2 ? true : false,
                            "sim" => $slots,
                            "country" => strtoupper($partner["country"]),
                            "currency" => strtoupper($currency),
                            "rate" => (int) $partner["rate"],
                            "owner" => $partner["owner"],
                            "status" => "offline"
                        ];
                    endforeach;
                endif;

                response(200, "Gateway Rates", [
                    "gateways" => $gatewayArray,
                    "partners" => $partnerArray
                ]);

                break;
            case "shorteners":
                if(!in_array("get_shorteners", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                $shorteners = $this->api->getShorteners($api["uid"]);

                $shortenerArray = [];

                if(!empty($shorteners)):
                    foreach($shorteners as $shortener):
                        $shortenerArray[] = [
                            "id" => (int) $shortener["id"],
                            "name" => $shortener["name"],
                        ];
                    endforeach;
                endif;

                response(200, "Available Shorteners", $shortenerArray);

                break;
            default:
                response(400, "Invalid API Endpoint!");
        endswitch;
	}

	public function create()
	{
        $this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $request = $this->sanitize->array($_REQUEST);
        $service = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["secret"]))
            response(400, "Invalid Parameters!");

        if($this->api->checkApikey($request["secret"]) < 1)
            response(401, "Invalid API secret supplied!");

        $api = $this->api->getApikey($request["secret"]);

        $permissions = explode(",", $api["permissions"]);

        if(!is_array($permissions))
            response(403, "Insufficient Permissions!");

        $subscription = set_subscription(
            $this->system->checkSubscription($api["uid"]), 
            $this->system->getSubscription(false, $api["uid"]), 
            $this->system->getSubscription(false, false, true)
        );

        date_default_timezone_set($this->api->getUserTimezone($api["uid"]));

        switch($service):
            case "wa.link":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("create_whatsapp", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                // MODIFIED: sid parameter is now optional
                // if(!isset($request["sid"]))
                //     response(400, "Invalid Parameters!");

                if(empty($subscription))
                    response(400, "Account is not subscribed to any premium package!");

                if(limitation($subscription["wa_account_limit"], $this->system->countWaAccounts($api["uid"])))
                    response(400, "You have reached the maximum allowed WhatsApp accounts for your package!");

                // MODIFIED: Automatic server selection logic
                $waServer = null;
                
                if(isset($request["sid"]) && !empty($request["sid"])):
                    // Manual server selection - user specified a server ID
                    $waServer = $this->system->getWaServer($request["sid"], "id");

                    if(!$waServer)
                        response(400, "WhatsApp server is not available!");

                    // Check if manually selected server has capacity
                    if($this->wa->check($waServer["url"], $waServer["port"])):
                        $currentCount = $this->wa->total($waServer["secret"], $waServer["url"], $waServer["port"]);
                        
                        if($currentCount !== false && $currentCount >= $waServer["accounts"]):
                            response(403, "Selected WhatsApp server is at full capacity ({$currentCount}/{$waServer['accounts']} accounts)");
                        endif;
                    else:
                        response(500, "Unable to connect to WhatsApp servers!");
                    endif;
                else:
                    // Automatic server selection - find first available server
                    $packageServers = $this->system->getWaServers($subscription["pid"]);
                    
                    if(empty($packageServers))
                        response(500, "No WhatsApp servers available for your package!");
                    
                    foreach($packageServers as $serverId => $server):
                        // Check server connection
                        if($this->wa->check($server["url"], $server["port"])):
                            // Check server capacity
                            $currentCount = $this->wa->total($server["secret"], $server["url"], $server["port"]);
                            
                            if($currentCount !== false && $currentCount < $server["accounts"]):
                                $waServer = $server;
                                break;
                            endif;
                        endif;
                    endforeach;
                    
                    if(!$waServer)
                        response(503, "All WhatsApp servers are at full capacity. Please try again later.");
                endif;

                // Create QR code with selected server
                if($this->wa->check($waServer["url"], $waServer["port"])):
                    $token = sha1(uniqid(time(), true));
                    
                    $qrString = $this->wa->create($waServer["secret"], $api["uid"], $api["hash"], $waServer["id"], $waServer["url"], $waServer["port"], false, $token);

                    if($qrString):
                        $this->cache->container("system.whatsapp", true);
                        $this->cache->set($token, [
                            "secret" => $request["secret"],
                            "qrstring" => $qrString
                        ], 600);

                        response(200, "WhatsApp QRCode has been created!", [
                            "qrstring" => $qrString,
                            "qrimagelink" => site_url("api/get/wa.qr?token={$token}", true),
                            "infolink" => site_url("api/get/wa.info?token={$token}", true),
                            "server_id" => $waServer["id"],
                            "server_name" => $waServer["name"]
                        ]);
                    else:
                        response(500, "Unable to generate WhatsApp QRCode!");
                    endif;
                else:
                    response(500, "Unable to connect to WhatsApp servers!");
                endif;

                break;
            case "wa.relink":
                if(!in_array("whatsapp", explode(",", $subscription["services"])))
                    response(403, "Subscription has no permission to use WhatsApp services!");

                if(!in_array("create_whatsapp", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                // MODIFIED: sid parameter is now optional, only unique is required
                if(!isset($request["unique"]))
                    response(400, "Invalid Parameters! 'unique' parameter is required.");

                if(empty($subscription))
                    response(400, "Account is not subscribed to any premium package!");

                if($this->system->checkWaAccount($api["uid"], $request["unique"], "unique") < 1)
					response(404, "WhatsApp account doesn't exist!");

                // Get the current account details
                $currentAccount = $this->system->getWaAccount($api["uid"], $request["unique"], "unique");
                
                // Check if account is already connected before creating new QR code
                $currentWaServer = $this->system->getWaServer($currentAccount["wsid"], "id");
                if($currentWaServer && $this->wa->check($currentWaServer["url"], $currentWaServer["port"])) {
                    try {
                        $currentStatus = $this->wa->status($currentWaServer["secret"], $currentWaServer["url"], $currentWaServer["port"], $currentAccount["unique"]);
                        
                        if($currentStatus && in_array($currentStatus, ["connected"])) {
                            response(200, "WhatsApp account is already connected", [
                                "already_connected" => true,
                                "connection_status" => $currentStatus,
                                "server_id" => (int) $currentWaServer["id"],
                                "server_name" => $currentWaServer["name"],
                                "unique" => $currentAccount["unique"],
                                "wid" => $currentAccount["wid"]
                            ]);
                        }
                    } catch(Exception $e) {
                        // Continue with relink process if status check fails
                    }
                }
                
                // MODIFIED: Automatic server selection logic for relink
                $waServer = null;
                
                if(isset($request["sid"]) && !empty($request["sid"])):
                    // Manual server selection for relink
                    $waServer = $this->system->getWaServer($request["sid"], "id");

                    if(!$waServer)
                        response(400, "WhatsApp server is not available!");

                    // Check if manually selected server has capacity (excluding current account)
                    if($this->wa->check($waServer["url"], $waServer["port"])):
                        $currentCount = $this->wa->total($waServer["secret"], $waServer["url"], $waServer["port"]);
                        
                        // If relinking to same server, capacity check is not needed
                        // If relinking to different server, check capacity
                        if($waServer["id"] != $currentAccount["wsid"]):
                            if($currentCount !== false && $currentCount >= $waServer["accounts"]):
                                response(403, "Selected WhatsApp server is at full capacity ({$currentCount}/{$waServer['accounts']} accounts)");
                            endif;
                        endif;
                    else:
                        response(500, "Unable to connect to WhatsApp servers!");
                    endif;
                else:
                    // Automatic server selection for relink - prefer current server if available
                    $packageServers = $this->system->getWaServers($subscription["pid"]);
                    
                    if(empty($packageServers))
                        response(500, "No WhatsApp servers available for your package!");
                    
                    // First try current server
                    if(isset($packageServers[$currentAccount["wsid"]])):
                        $currentServer = $packageServers[$currentAccount["wsid"]];
                        if($this->wa->check($currentServer["url"], $currentServer["port"])):
                            $waServer = $currentServer;
                        endif;
                    endif;
                    
                    // If current server not available, find another
                    if(!$waServer):
                        foreach($packageServers as $serverId => $server):
                            if($this->wa->check($server["url"], $server["port"])):
                                $currentCount = $this->wa->total($server["secret"], $server["url"], $server["port"]);
                                
                                if($currentCount !== false && $currentCount < $server["accounts"]):
                                    $waServer = $server;
                                    break;
                                endif;
                            endif;
                        endforeach;
                    endif;
                    
                    if(!$waServer)
                        response(503, "All WhatsApp servers are at full capacity. Please try again later.");
                endif;

                // Delete from old server and create on selected server
                if($this->wa->check($waServer["url"], $waServer["port"])):
                    // Delete from current server (might be same or different)
                    $oldServer = $this->system->getWaServer($currentAccount["wsid"], "id");
                    if($oldServer && $this->wa->check($oldServer["url"], $oldServer["port"])):
                        $this->wa->delete($oldServer["secret"], $oldServer["url"], $oldServer["port"], $request["unique"]);
                    endif;

                    // Create on selected server
                    $qrString = $this->wa->create($waServer["secret"], $api["uid"], $api["hash"], $waServer["id"], $waServer["url"], $waServer["port"], $request["unique"]);

                    if($qrString):
                        $token = sha1(uniqid(time(), true));

                        $this->cache->container("system.whatsapp", true);
                        $this->cache->set($token, [
                            "secret" => $request["secret"],
                            "qrstring" => $qrString
                        ], 600);

                        // Update account with new server ID if changed
                        if($waServer["id"] != $currentAccount["wsid"]):
                            $this->system->updateWaAccount(false, $request["unique"], [
                                "wsid" => $waServer["id"]
                            ]);
                        endif;

                        response(200, "WhatsApp QRCode has been created!", [
                            "already_connected" => false,
                            "qrstring" => $qrString,
                            "qrimagelink" => site_url("api/get/wa.qr?token={$token}", true),
                            "server_id" => (int) $waServer["id"],
                            "server_name" => $waServer["name"],
                            "server_changed" => ($waServer["id"] != $currentAccount["wsid"]),
                            "unique" => $currentAccount["unique"],
                            "wid" => $currentAccount["wid"]
                        ]);
                    else:
                        response(500, "Unable to generate WhatsApp QRCode!");
                    endif;
                else:
                    response(500, "Unable to connect to WhatsApp servers!");
                endif;

                break;
            case "contact":
                if(!in_array("create_contact", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["name"], $request["phone"], $request["groups"]))
                    response(400, "Invalid Parameters!");

                if(empty($subscription))
                    response(400, "Account is not subscribed to any premium package!");

                if(limitation($subscription["contact_limit"], $this->system->countContacts($api["uid"])))
                    response(400, "You have reached the maximum allowed contacts!");

                if(!$this->sanitize->length($request["name"]))
                    response(400, "Contact name is too short!");

                try {
                    $number = $this->phone->parse($request["phone"], $api["country"]);

                    $number->format(Brick\PhoneNumber\PhoneNumberFormat::INTERNATIONAL);

                    if (!$number->isValidNumber())
                        response(400, "Invalid phone number!");

                    if(!is_messagable_number($number))
                        response(400, "Invalid phone number!");

                    $request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
                } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                    response(400, "Invalid phone number!");
                }

                $groups = explode(",", $request["groups"]);

                if(!is_array($groups))
                    response(400, "Invalid Parameters!");

                foreach($groups as $group):
                    if($this->system->checkGroup($api["uid"], $group) < 1)
                        response(400, "Group #{$group} is invalid!");
                endforeach;

                if($this->system->checkNumber($api["uid"], $request["phone"]) > 0)
                    response(400, "Phone number already exist in your contact book!");

                $filtered = [
                    "uid" => $api["uid"],
                    "groups" => implode(",", $groups),
                    "phone" => $request["phone"],
                    "name" => $request["name"]
                ];

                if($this->system->create("contacts", $filtered)):
                    $this->cache->container("autocomplete.contacts.{$api["hash"]}");
                    $this->cache->clear();
                    $this->cache->container("contacts.{$api["hash"]}");
                    $this->cache->clear();
                    $this->cache->container("user.{$api["hash"]}");
                    $this->cache->clear();

                    response(200, "Contact has been created!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "group":
                if(!in_array("create_group", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["name"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->length($request["name"]))
                    response(400, "Group name is too short!");

                $filtered = [
                    "uid" => $api["uid"],
                    "name" => $request["name"]
                ];

                if($this->system->create("groups", $filtered)):
                    response(200, "Contact group has been created!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            default:
                response(400, "Invalid API Endpoint!");
        endswitch;
	}

	public function delete()
	{
		$this->header->allow();

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSettings());
        endif;

        set_system($this->cache->getAll());

        $request = $this->sanitize->array($_REQUEST);
        $service = $this->sanitize->string($this->url->segment(4));

        if(!isset($request["secret"]))
            response(400, "Invalid Parameters!");

        if($this->api->checkApikey($request["secret"]) < 1)
            response(401, "Invalid API secret supplied!");

        $api = $this->api->getApikey($request["secret"]);

        $permissions = explode(",", $api["permissions"]);

        if(!is_array($permissions))
            response(403, "Insufficient Permissions!");

        switch($service):
            case "contact":
                if(!in_array("delete_contact", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete($api["uid"], $request["id"], "contacts")):
                    $this->cache->container("autocomplete.contacts.{$api["hash"]}");
                    $this->cache->clear();
                    $this->cache->container("contacts.{$api["hash"]}");
                    $this->cache->clear();
                    $this->cache->container("user.{$api["hash"]}");
                    $this->cache->clear();

                    response(200, "Contact has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "group":
                if(!in_array("delete_group", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete($api["uid"], $request["id"], "groups")):
                    response(200, "Contact group has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "unsubscribed":
                if(!in_array("delete_unsubscribed", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete($api["uid"], $request["id"], "unsubscribed")):
                    response(200, "Unsubscribed contact has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "sms.sent":
                if(!in_array("delete_sms_sent", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                $sent = $this->system->getSent($request["id"]);

                if($this->system->delete($api["uid"], $request["id"], "sent")):
                    try {
                        $device = $this->system->getDevice($api["uid"], $sent["did"], "did");

                        $this->fcm->sendWithToken($device["fcm_token"], [
                            "action" => "message_delete",
							"message_id" => $request["id"]
                        ]);
                    } catch(Exception $e) {
                        // Ignore
                    }
                    
                    response(200, "Sent SMS has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "sms.campaign":
                if(!in_array("delete_sms_campaign", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->api->checkSmsCampaign($request["campaign"], $api["uid"]) < 1)
                    response(400, "Invalid Parameters!");

                $campaign = $this->system->getSmsCampaign($api["uid"], $request["id"]);

                if($this->system->delete($api["uid"], $request["id"], "campaigns")):
                    if($this->system->clearCampaignSms($api["uid"], $request["id"])):
                        $device = $this->system->getDevice($api["uid"], $campaign["did"], "did");

                        $this->fcm->sendWithToken($device["fcm_token"], [
                            "action" => "campaign_delete",
                            "campaign_id" => $request["id"]
                        ]);
                    endif;

                    response(200, "SMS campaign has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "sms.received":
                if(!in_array("delete_sms_received", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete($api["uid"], $request["id"], "received")):
                    response(200, "Received SMS has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "wa.account":
                $account = $this->system->getWaAccount($api["uid"], $request["unique"], "unique");
                
                if($account):
                    if($this->system->delete($api["uid"], $account["id"], "wa_accounts")):
                        $waServer = $this->system->getWaServer($account["unique"], "unique");

                        try {
                            $this->wa->delete($waServer["secret"], $waServer["url"], $waServer["port"], $account["unique"]);
                        } catch(Exception $e){
                            // Ignore
                        }

                        response(200, "WhatsApp account has been deleted!");
                    else:
                        response(500, "Something went wrong!");
                    endif;
                else:
                    response(404, "WhatsApp account doesn't exist!");
                endif;

                break;
            case "wa.sent":
                if(!in_array("delete_wa_sent", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                $sent = $this->system->getWaSent($request["id"]);

                if($this->system->delete($api["uid"], $request["id"], "wa_sent")):
                    try {
                        if($sent["priority"] > 1):
                            $waServer = $this->system->getWaServer($sent["unique"], "unique");
                            
                            if(!$this->wa->check($waServer["url"], $waServer["port"]))
                                response(500, "Unable to connect to WhatsApp servers!");
    
                            if($this->system->delete($api["uid"], $request["id"], "wa_sent")):
                                if($sent["status"] < 3):
                                    try {
                                        $this->wa->delete_chat($waServer["secret"], $waServer["url"], $waServer["port"], $sent["unique"], $api["hash"], $sent["cid"], $request["id"]);
                                    } catch(Exception $e){
                                        // Ignore
                                    }
                                endif;
                            endif;
                        else:
                            $this->system->delete($api["uid"], $request["id"], "wa_sent");
                        endif;
                    } catch(Exception $e){
                        // Ignore
                    }

                    try {
                        $msgDecode = json_decode($sent["message"], true, JSON_THROW_ON_ERROR);
                        
                        if(!isset($msgDecode["autoreply"])):
                            if(isset($msgDecode["image"])):
                                $fileUrl = explode("/", $msgDecode["image"]["url"]);
                            endif;
        
                            if(isset($msgDecode["audio"])):
                                $fileUrl = explode("/", $msgDecode["audio"]["url"]);
                            endif;
        
                            if(isset($msgDecode["video"])):
                                $fileUrl = explode("/", $msgDecode["video"]["url"]);
                            endif;
        
                            if(isset($msgDecode["document"])):
                                $fileUrl = explode("/", $msgDecode["document"]["url"]);
                            endif;
                            
                            if(isset($fileUrl)):
                                $this->file->delete("uploads/whatsapp/sent/{$api["uid"]}/" . end($fileUrl));
                            endif;
                        endif;
                    } catch(Exception $e){
                        // Ignore
                    }

                    response(200, "Sent WhatsApp chat has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "wa.campaign":
                if(!in_array("delete_wa_campaign", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->api->checkWaCampaign($request["id"], $api["uid"]) < 1)
                    response(400, "Invalid Parameters!");

                $campaign = $this->system->getWaCampaign($api["uid"], $request["id"], "id");

                if($this->system->delete($api["uid"], $request["id"], "wa_campaigns")):
                    if($this->system->clearCampaignChats($api["uid"], $request["id"])):
                        try {
                            $waServer = $this->system->getWaServer($campaign["unique"], "unique");

                            $this->wa->delete_campaign($waServer["secret"], $waServer["url"], $waServer["port"], $campaign["unique"], $api["hash"], $request["id"]);
                        } catch(Exception $e){
                            // Ignore
                        }
                    endif;

                    response(200, "WhatsApp campaign has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "wa.received":
                if(!in_array("delete_wa_received", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                if($this->system->delete($api["uid"], $request["id"], "wa_received")):
                    response(200, "Received WhatsApp chat has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            case "ussd":
                if(!in_array("delete_ussd", explode(",", $api["permissions"])))
                    response(403, "This API key doesn't have permission to use this endpoint!");

                if(!isset($request["id"]))
                    response(400, "Invalid Parameters!");

                if(!$this->sanitize->isInt($request["id"]))
                    response(400, "Invalid Parameters!");

                $ussd = $this->system->getUssd($request["id"], $api["uid"]);

                if($this->system->delete($api["uid"], $request["id"], "ussd")):
                    if($ussd):
                        $device = $this->system->getDevice($api["uid"], $ussd["did"], "did");
                        
                        if($device):
                            $this->fcm->sendWithToken($device["fcm_token"], [
                                "action" => "ussd_delete",
                                "ussd_id" => $ussd["id"]
                            ]);
                        endif;
                    endif;
                    
                    response(200, "USSD request has been deleted!");
                else:
                    response(500, "Something went wrong!");
                endif;

                break;
            default:
                response(400, "Invalid API Endpoint!");
        endswitch;
	}
}