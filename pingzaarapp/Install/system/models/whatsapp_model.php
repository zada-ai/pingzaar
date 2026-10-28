<?php

class Whatsapp_Model extends MVC_Model
{
	public function checkWid($uid, $wid)
	{
		$this->db->query("SELECT id FROM wa_accounts WHERE uid = ? AND wid LIKE ?", [
			$uid,
			"{$wid}%"
		]);

		return $this->db->num_rows();
	}

	public function checkAccount($uid, $unique)
	{
		$this->db->query("SELECT id FROM wa_accounts WHERE uid = ? AND `unique` = ?", [
			$uid,
			$unique
		]);

		return $this->db->num_rows();
	}

    public function getChat($id)
    {
        return $this->db->query_one("SELECT id, uid, wid, phone, message, status, api, create_date FROM wa_sent WHERE id = ?", [
            $id
        ]);
    }

	public function getUserEmail($id)
    {
        $fetch = $this->db->query_one("SELECT email FROM users WHERE id = ?", [
            $id
        ]);

        return $fetch ? $fetch["email"] : false;
    }

    public function getUserLanguage($id)
    {
        $fetch = $this->db->query_one("SELECT language FROM users WHERE id = ?", [
            $id
        ]);

        return $fetch ? $fetch["language"] : 1;
    }

	public function getUserTimezone($id)
    {
        $fetch = $this->db->query_one("SELECT timezone FROM users WHERE id = ?", [
            $id
        ]);

        return $fetch ? $fetch["timezone"] : "UTC";
    }

	public function getPendingMessages($uid, $unique, $diff)
	{
		$query = <<<SQL
SELECT s.id AS id, IF(c.id, c.id, 0) AS cid, IF(c.status, c.status, 1) AS cstatus, s.uid AS uid, s.wid AS wid, s.unique AS `unique`, s.phone AS phone, s.message AS message
FROM wa_sent s
LEFT JOIN wa_campaigns c ON s.cid = c.id
WHERE s.uid = ? AND s.unique = ? AND s.status < 2 AND s.priority > 1
LIMIT {$diff}
SQL;

        $this->db->query($query, [
            $uid,
            $unique
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
SELECT a.id, a.type, a.source, a.`event`, a.priority, a.`match`, a.ai_key, a.group_trigger, k.id AS ai_key_id, k.prompt AS ai_prompt, k.provider AS `provider`, k.post_prompt AS ai_post_prompt, k.model AS ai_model, k.history AS ai_history, k.max_tokens AS ai_max_tokens, k.vision AS ai_vision, k.transcription AS ai_transcription, k.apikey AS ai_apikey, a.ai_plugins, a.account, a.keywords, a.link, a.message
FROM actions a
LEFT JOIN ai_keys k ON a.ai_key = k.id
WHERE a.uid = ? AND a.type = ? AND a.source > 1
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


    public function incrementProcessed($cid)
    {
        $query = <<<SQL
UPDATE wa_campaigns SET processed = processed + 1 WHERE id = ? LIMIT 1
SQL;

        return $this->db->query($query, [
            $cid
        ]);
    }
}