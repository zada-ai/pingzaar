<?php

class Device_Model extends MVC_Model
{
    public function checkUserId($id)
    {
        $this->db->query("SELECT id FROM users WHERE id = ?", [
            $id
        ]);

        return $this->db->num_rows();
    }

    public function checkUserEmail($email)
    {
        $this->db->query("SELECT id FROM users WHERE email = ?", [
            $email
        ]);

        return $this->db->num_rows();
    }

	public function checkDevice($did)
    {
        $this->db->query("SELECT id FROM devices WHERE did = ?", [
            $did
        ]);
        
        return $this->db->num_rows();
    }

    public function checkSuspension($uid)
    {
        $this->db->query("SELECT id FROM users WHERE id = ? AND suspended > 0", [
            $uid
        ]);

        return $this->db->num_rows();
    }

    public function checkReceived($rid, $uid, $did)
    {
        $this->db->query("SELECT id FROM received WHERE rid = ? AND uid = ? AND did = ?", [
            $rid,
            $uid,
            $did
        ]);

        return $this->db->num_rows();
    }

    public function getDevice($did)
    {
        return $this->db->query_one("SELECT id, uid, did, name, version, manufacturer, random_send, random_min, random_max, receive_sms, global_device, global_priority, global_slots, country, rate, create_date FROM devices WHERE did = ?", [
            $did
        ]);
    }

    public function getUssd($id)
    {
        return $this->db->query_one("SELECT id, uid, did, code, sim, status, create_date FROM ussd WHERE id = ?", [
            $id
        ]);
    }

    public function getPendingMessages($did, $diff)
    {
        $query = <<<SQL
SELECT s.id AS id, s.uid AS uid, IF(c.id, c.id, 0) AS cid, s.sim AS sim, s.phone AS recipient, s.message AS message, s.priority AS priority
FROM sent s
LEFT JOIN campaigns c ON s.cid = c.id
WHERE s.did = ? AND s.status < 2
LIMIT {$diff}
SQL;

        $this->db->query($query, [
            $did
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[] = $row;

            return $rows;
        else:
            return [];
        endif;
    }

    public function getPendingUssd($did)
    {
        $query = <<<SQL
SELECT id, uid, sim, code
FROM ussd
WHERE did = ? AND status < 2
SQL;

        $this->db->query($query, [
            $did
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[] = $row;

            return $rows;
        else:
            return [];
        endif;
    }

    public function getWebhooks($uid, $event)
    {
        $query = <<<SQL
SELECT id, secret, url, events
FROM webhooks
WHERE uid = ? AND FIND_IN_SET(?, events)
SQL;

        $this->db->query($query, [
            $uid,
            $event
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[] = $row;

            return $rows;
        else:
            return [];
        endif;
    }

    public function getActions($uid, $type)
    {
        $query = <<<SQL
SELECT a.id, a.type, a.source, a.`event`, a.priority, a.`match`, a.sim, a.ai_key, k.id AS ai_key_id, k.prompt AS ai_prompt, k.provider AS `provider`, k.post_prompt AS ai_post_prompt, k.model AS ai_model, k.history AS ai_history, k.max_tokens AS ai_max_tokens, k.vision AS ai_vision, k.transcription AS ai_transcription, k.apikey AS ai_apikey, a.ai_plugins, a.device, a.keywords, a.link, a.message
FROM actions a
LEFT JOIN ai_keys k ON a.ai_key = k.id
WHERE a.uid = ? AND a.type = ? AND a.source < 2
SQL;

        $this->db->query($query, [
            $uid,
            $type
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[] = $row;

            return $rows;
        else:
            return [];
        endif;
    }

    /**
     * Issues a single-use pairing token for a user.
     *
     * Any token this user still has outstanding is discarded first: only the QR
     * currently on screen should work, so reopening the modal invalidates the
     * code in an older screenshot.
     */
    public function createPairingToken($uid, $ttlMinutes = 10)
    {
        $this->db->query("DELETE FROM pairing WHERE uid = ?", [$uid]);

        $token = bin2hex(random_bytes(24));

        $this->db->query("INSERT INTO pairing (uid, token, expire_date) VALUES (?, ?, ?)", [
            $uid,
            $token,
            date("Y-m-d H:i:s", strtotime("+{$ttlMinutes} minutes"))
        ]);

        return $token;
    }

    /**
     * Redeems a pairing token, returning the user id it belonged to.
     *
     * The DELETE is the claim, and it is a single statement: of two concurrent
     * scans of the same QR exactly one removes a row, so affected_rows decides
     * the winner and the token cannot be redeemed twice however the requests
     * interleave. Checking then deleting would leave a race between the two.
     *
     * Expired rows are excluded by the same statement rather than swept
     * separately, so an expired token is indistinguishable from an absent one.
     *
     * @return int|false the user id, or false when the token is unusable.
     */
    public function claimPairingToken($token)
    {
        if(empty($token))
            return false;

        $row = $this->db->query_one("SELECT uid FROM pairing WHERE token = ? AND expire_date > NOW()", [
            $token
        ]);

        if(!$row)
            return false;

        $this->db->query("DELETE FROM pairing WHERE token = ? AND expire_date > NOW()", [
            $token
        ]);

        return $this->db->affected_rows() > 0 ? (int) $row["uid"] : false;
    }

    /**
     * The Firebase client configuration this site holds, or false when the admin
     * has not uploaded google-services.json.
     *
     * These are the four values the google-services Gradle plugin used to compile
     * into the APK. They are not secrets -- they ship inside every Android app
     * that uses Firebase, and sending is authorised by the service account held
     * server-side, not by these.
     *
     * Returns null, never false, when there is no usable configuration. That
     * distinction is not cosmetic: json_encode turns false into a JSON boolean,
     * and the app deserialises this field into a nullable object. Gson rejects a
     * boolean there with "Expected BEGIN_OBJECT but was BOOLEAN", which Retrofit
     * raises inside the pair call -- so the app reported "could not reach the
     * site" for what is really "push is not configured", the exact misdiagnosis
     * this field exists to prevent. null decodes cleanly as an absent object.
     */
    public function getFirebaseClientConfig()
    {
        $path = "system/storage/temporary/google-services.json";

        if(!file_exists($path))
            return null;

        $json = json_decode(file_get_contents($path), true);

        if(!is_array($json))
            return null;

        $client = isset($json["client"][0]) ? $json["client"][0] : false;

        if(!$client)
            return null;

        $appId    = isset($client["client_info"]["mobilesdk_app_id"]) ? $client["client_info"]["mobilesdk_app_id"] : false;
        $apiKey   = isset($client["api_key"][0]["current_key"]) ? $client["api_key"][0]["current_key"] : false;
        $project  = isset($json["project_info"]["project_id"]) ? $json["project_info"]["project_id"] : false;
        $sender   = isset($json["project_info"]["project_number"]) ? $json["project_info"]["project_number"] : false;

        // A partial configuration cannot initialise Firebase, so it is treated as
        // no configuration at all rather than handed over to fail on the device.
        if(!$appId || !$apiKey || !$project || !$sender)
            return null;

        return [
            "app_id"     => $appId,
            "api_key"    => $apiKey,
            "project_id" => $project,
            "sender_id"  => (string) $sender
        ];
    }
}
