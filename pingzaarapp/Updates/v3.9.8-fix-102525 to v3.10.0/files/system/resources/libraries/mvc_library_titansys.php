<?php

use GuzzleHttp\Client;

class MVC_Library_Titansys
{
    private $guzzle;

    public function __construct()
    {
        $this->guzzle = new Client();
    }

    public function getGeoIp($ip)
    {
        // Was a forward to geoplugin.net via the titansys proxy. geoplugin.net itself
        // has since shut down free access (verified live: every request now returns
        // HTTP 403 "geoPlugin API is no longer available for free use"), so it can't be
        // called directly either. ip-api.com is a free, keyless equivalent — verified
        // live to return {"status":"success","country":"..."} on success and
        // {"status":"fail",...} (no "country" key) on failure.
        try {
        	$geoip = json_decode($this->guzzle->get("http://ip-api.com/json/{$ip}", [
                "timeout" => 3,
				"connect_timeout" => 3,
				"allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents(), true);

			return isset($geoip["status"]) && $geoip["status"] == "success" && !empty($geoip["country"])
				? $geoip["country"]
				: false;
        } catch(Exception $e){
            return false;
        }
    }

    public function calculatePartnerSendPrice($currency, $device_rate, $cache)
    {
        $cache->container("system.payments", true);

        // Guard on the same key this method stores and reads. It previously
        // checked "rates", which convertSystemCurrencyToUsd owns, so once that
        // ran first this method skipped its own fetch and read a missing
        // "exchange" — returning false for every partner price.
        if(!$cache->has("exchange")):
            try {
                if(!defined("system_currency_api_key") || empty(system_currency_api_key)):
                    @file_put_contents("system/storage/temporary/error.log",
                        "Currency Error: no currency_api_key configured (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
                    return false;
                endif;

                $rates = json_decode($this->guzzle->get(
                    "https://api.exchangerate-api.com/v4/latest/USD?access_key=" . urlencode(system_currency_api_key), [
                        "http_errors" => false,
                        "timeout"     => 30
                    ])->getBody()->getContents(), true);

                if($rates["status"] == 200):
                    $cache->set("exchange", $rates, 43200);
                else:
                    return false;
                endif;
            } catch(Exception $e){
                return false;
            }
        endif;

        $rates = $cache->get("exchange");

        try {
            // Validate currency exists in rates data
            if(!isset($rates["data"][strtoupper($currency)])):
                return false;
            endif;

            $currency_rate = $rates["data"][strtoupper($currency)];

            // Check for zero to prevent division by zero
            if($currency_rate == 0):
                return false;
            endif;

            $base_rate = $rates["data"]["USD"] / $currency_rate;
            $usd_price = ($base_rate * $device_rate) * $rates["data"]["USD"];
            $final_amount = (float) ($usd_price * $rates["data"][strtoupper(system_currency)]);

            // Don't use abs() - reject negative amounts from negative rates
            if($final_amount <= 0):
                return false;
            endif;

            return $final_amount;
        } catch (Exception $e) {
            return false;
        }
    }

    public function convertSystemCurrencyToUsd($amount, $cache)
    {
        $cache->container("system.payments", true);

        if(!$cache->has("rates")):
            try {
                if(!defined("system_currency_api_key") || empty(system_currency_api_key)):
                    @file_put_contents("system/storage/temporary/error.log",
                        "Currency Error: no currency_api_key configured (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
                    return false;
                endif;

                $rates = json_decode($this->guzzle->get(
                    "https://api.exchangerate-api.com/v4/latest/USD?access_key=" . urlencode(system_currency_api_key), [
                        "http_errors" => false,
                        "timeout"     => 30
                    ])->getBody()->getContents(), true);

                if($rates["status"] == 200):
                    $cache->set("rates", $rates, 43200);
                else:
                    return false;
                endif;
            } catch (Exception $e) {
                return false;
            }
        endif;

        $rates = $cache->get("rates");

        try {
            $base_rate = $rates["data"]["USD"] / $rates["data"][strtoupper(system_currency)];

            $converted_amount = $amount * $base_rate;

            return (float) round($converted_amount, 2);
        } catch (Exception $e) {
            return false;
        }
    }

    public function convertBaseToTarget($amount, $base, $target, $cache)
    {
        $cache->container("system.payments", true);

        if(!$cache->has("rates")):
            try {
                if(!defined("system_currency_api_key") || empty(system_currency_api_key)):
                    @file_put_contents("system/storage/temporary/error.log",
                        "Currency Error: no currency_api_key configured (" . date("m/d/Y g:i A") . ")\n\n", FILE_APPEND);
                    return false;
                endif;

                $rates = json_decode($this->guzzle->get(
                    "https://api.exchangerate-api.com/v4/latest/USD?access_key=" . urlencode(system_currency_api_key), [
                        "http_errors" => false,
                        "timeout"     => 30
                    ])->getBody()->getContents(), true);

                if($rates["status"] == 200):
                    $cache->set("rates", $rates, 43200);
                else:
                    return false;
                endif;
            } catch (Exception $e) {
                return false;
            }
        endif;

        $rates = $cache->get("rates");

        try {
            $php_to_usd = $rates["data"][$base];
            $usd_to_brl = $rates["data"][$target];
            $converted_amount = $amount * ($usd_to_brl / $php_to_usd);

            return (float) round($converted_amount, 2); 
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Thin wrapper kept so all 19 existing call sites (`$this->titansys->aiModeration(...)`
     * across requests.php and api.php) don't need to change. Delegates to the local AI
     * library's moderate(), which calls OpenAI's moderation endpoint directly — updating
     * 19 call sites in 2 files would have been far more invasive than keeping this one
     * forwarding method. MVC_Library_Titansys has no injected access to sibling
     * libraries, so it reaches the controller singleton the same way MVC_Model reaches
     * the database (system/resources/mvc_model.php:32).
     */
    public function aiModeration($apiKey, $message, $attachment)
    {
        return mvc::instance()->controller->aiprovider->moderate($apiKey, $message, $attachment);
    }

    // aiConversationReset() removed — its two callers (requests.php ~7667 and ~8333)
    // now call $this->aiprovider->resetForUser(logged_id) directly. See
    // MVC_Library_Ai::resetForUser() for why a per-user reset (not per-AI-key) is the
    // correct replacement.
}