<?php

use GuzzleHttp\Client;

class MVC_Library_Whatsapp 
{
	private $guzzle;

	public function __construct()
	{
		$this->guzzle = new Client();
	}

	public function check($url, $port)
	{
		try {
			$check = json_decode($this->guzzle->get("{$url}:{$port}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());
			
			return $check->data->version;
		} catch(Exception $e){
			return false;
		}
	}

	public function total($secret, $url, $port)
	{
		try {
			$total = json_decode($this->guzzle->get("{$url}:{$port}/accounts/total/" . sha1(site_url) . "/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());
			
            return $total->data;
		} catch(Exception $e){
			return false;
		}
	}

	public function create($secret, $uid, $hash, $wsid, $url, $port, $unique = false, $api_token = "none")
	{
		try {
        	$create = json_decode($this->guzzle->post("{$url}:{$port}/accounts/create/{$secret}", [
        		"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
	            "form_params" => [
	            	"system_token" => system_token,
	            	"site_unique" => sha1(site_url),
	            	"site_url" => rtrim(site_url(false, true), "/"),
					"api_token" => $api_token,
					"wsid" => $wsid,
	            	"unique" => $unique ? $unique : uniqid(time() . $hash),
	            	"uid" => $uid,
	            	"hash" => $hash,
	            	"os" => system_site_name
	            ],
				"timeout" => 60,
				"connect_timeout" => 60,
	            "allow_redirects" => true,
	            "http_errors" => false,
                "verify" => false
	        ])->getBody()->getContents());

	        if($create->status == 200):
	        	return $create->data->qr;
	        else:
        		return false;
        	endif;
        } catch(Exception $e){
        	return false;
        }
	}

	public function update($secret, $account, $url, $port)
	{
		try {
			$update = json_decode($this->guzzle->post("{$url}:{$port}/accounts/update/" . sha1(site_url) . "/{$account["unique"]}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
	            "form_params" => [
					"wsid" => $account["wsid"],
	            	"receive_chats" => $account["receive_chats"],
	            	"random_send" => $account["random_send"],
	            	"random_min" => $account["random_min"],
	            	"random_max" => $account["random_max"]
	            ],
				"timeout" => 60,
				"connect_timeout" => 60,
	            "allow_redirects" => true,
	            "http_errors" => false,
                "verify" => false
	        ])->getBody()->getContents());

	    	return $update->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function delete($secret, $url, $port, $unique)
	{
		try {
			$delete = json_decode($this->guzzle->get("{$url}:{$port}/accounts/delete/" . sha1(site_url) . "/{$unique}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 60,
				"connect_timeout" => 60,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $delete->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function status($secret, $url, $port, $unique)
	{
		try {
			$status = json_decode($this->guzzle->get("{$url}:{$port}/accounts/status/" . sha1(site_url) . "/{$unique}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());
			
            return $status->data;
		} catch(Exception $e){
			return false;
		}
	}

	public function send($secret, $url, $port, $unique)
	{
		try {
			$send = json_decode($this->guzzle->get("{$url}:{$port}/chats/send/" . sha1(site_url) . "/{$unique}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $send->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function sendPriority($secret, $url, $port, $unique, $id, $recipient, $message)
	{
		try {
			$send = json_decode($this->guzzle->post("{$url}:{$port}/chats/send/" . sha1(site_url) . "/{$unique}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"form_params" => [
					"id" => $id,
					"recipient" => $recipient,
					"message" => $message
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $send->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function download($file_lib, $secret, $url, $port, $unique, $file, $id)
	{
		try {
			$fileName = explode(".", $file);
			$fileExtension = end($fileName);
			$uploadPath = "uploads/whatsapp/received/{$unique}/{$id}.{$fileExtension}";

			$file_lib->mkdir("uploads/whatsapp/received/{$unique}");

			$download = $this->guzzle->get("{$url}:{$port}/files/download/" . sha1(site_url) . "/{$unique}/{$secret}/{$file}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"sink" => $uploadPath,
				"timeout" => 120,
				"connect_timeout" => 120,
				"allow_redirects" => true,
				"http_errors" => false,
                "verify" => false
            ]);

			if(filesize($uploadPath) <= 1024)
				$file_lib->delete($uploadPath);

            return true;
		} catch(Exception $e){
			return false;
		}
	}

	public function garbage($url, $port, $days)
	{
		try {
			$garbage = json_decode($this->guzzle->get("{$url}:{$port}/files/garbage/{$days}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 120,
				"connect_timeout" => 120,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $garbage->status == 200;
		} catch(Exception $e){
			return false;
		}
	}

	public function delete_chat($secret, $url, $port, $unique, $hash, $cid, $id)
	{
		try {
			$delete = json_decode($this->guzzle->get("{$url}:{$port}/chats/delete/" . sha1(site_url) . "/{$unique}/{$hash}/{$cid}/{$id}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $delete->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function start_campaign($secret, $url, $port, $unique, $hash, $cid)
	{
		try {
			$start = json_decode($this->guzzle->get("{$url}:{$port}/chats/campaign/start/" . sha1(site_url) . "/{$unique}/{$hash}/{$cid}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $start->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function stop_campaign($secret, $url, $port, $unique, $hash, $cid)
	{
		try {
			$stop = json_decode($this->guzzle->get("{$url}:{$port}/chats/campaign/stop/" . sha1(site_url) . "/{$unique}/{$hash}/{$cid}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $stop->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function delete_campaign($secret, $url, $port, $unique, $hash, $cid)
	{	
		try {
			$delete = json_decode($this->guzzle->get("{$url}:{$port}/chats/campaign/remove/" . sha1(site_url) . "/{$unique}/{$hash}/{$cid}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 60,
				"connect_timeout" => 60,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents());

            return $delete->status;
		} catch(Exception $e){
			return false;
		}
	}

	public function get_groups($secret, $url, $port, $unique)
	{
		try {
			$groups = json_decode($this->guzzle->get("{$url}:{$port}/contacts/groups/" . sha1(site_url) . "/{$unique}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 120,
				"connect_timeout" => 120,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents(), true);

			if($groups["status"] == 200):
				return $groups["data"];
			else:
				return false;
			endif;
		} catch(Exception $e){
			return false;
		}
	}

	public function validate($secret, $url, $port, $unique, $address)
	{
		try {
			$validate = json_decode($this->guzzle->get("{$url}:{$port}/contacts/validate/" . sha1(site_url) . "/{$unique}/{$address}/{$secret}", [
				"headers" => [
					"ngrok-skip-browser-warning" => uniqid(),
					"Bypass-Tunnel-Reminder" => uniqid(),
					"X-Pinggy-No-Screen" => uniqid()
				],
				"timeout" => 30,
				"connect_timeout" => 30,
                "allow_redirects" => true,
                "http_errors" => false,
                "verify" => false
            ])->getBody()->getContents(), true);

			if($validate["status"] == 200): 
				$phone = explode("@", $validate["data"]["jid"]);

				return [
					"jid" => $validate["data"]["jid"],
					"phone" => "+{$phone[0]}"
				];
			else:
				return false;
			endif;
		} catch(Exception $e){
			return false;
		}
	}
}