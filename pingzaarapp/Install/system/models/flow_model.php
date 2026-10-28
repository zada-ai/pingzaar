<?php

class Flow_Model extends MVC_Model
{
    public function checkFlow($id, $uid)
    {
        $this->db->query("SELECT id FROM flows WHERE id = ? AND uid = ?", [
            $id,
            $uid
        ]);

        return $this->db->num_rows();
    }

    public function checkContactNumber($uid, $phone)
    {
        $this->db->query("SELECT id FROM contacts WHERE uid = ? AND phone = ?", [
            $uid,
            $phone
        ]);

        return $this->db->num_rows();
    }

    public function getContact($uid, $phone)
    {
        return $this->db->query_one("SELECT id, uid, name, groups, phone FROM contacts WHERE uid = ? AND phone = ?", [
            $uid,
            $phone
        ]);
    }

    public function getContactGroups($uid)
    {
        $query = <<<SQL
SELECT id, uid, name
FROM `groups`
WHERE uid = ?
SQL;

        $this->db->query($query, [
            $uid
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[] = $row;

            return $rows; 
        else:
            return [];
        endif;
    }

    public function getAiKeys($uid)
    {
        $query = <<<SQL
SELECT id, uid, name, prompt, post_prompt, model, history, max_tokens, vision, transcription, apikey, `provider`, create_date
FROM ai_keys
WHERE uid = ?
SQL;

        $this->db->query($query, [
            $uid
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[$row["id"]] = $row;

            return $rows; 
        else:
            return [];
        endif;
    }

    public function getAiPlugins($uid)
    {
        $query = <<<SQL
SELECT id, `uid`, `name`, `endpoint`, create_date
FROM ai_plugins
WHERE uid = ?
SQL;

        $this->db->query($query, [
            $uid
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[$row["id"]] = $row;

            return $rows; 
        else:
            return [];
        endif;
    }

    public function getWaAccounts($uid)
    {
        $query = <<<SQL
SELECT id, uid, wsid, wid, `unique`, receive_chats, random_send, random_min, random_max, create_date
FROM wa_accounts
WHERE uid = ?
SQL;

        $this->db->query($query, [
            $uid
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next()):
                $waNumber = explode(":", $row["wid"])[0];

                $rows[$row["unique"]] = [
                    "id" => $row["unique"],
                    "phone" => "+{$waNumber}"
                ];
            endwhile;

            return $rows; 
        else:
            return [];
        endif;
    }

    public function getDevices($uid)
    {
        $query = <<<SQL
SELECT id, uid, did, name, version, manufacturer, fcm_token, create_date
FROM devices
WHERE uid = ?
SQL;

        $this->db->query($query, [
            $uid
        ]); 

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[$row["did"]] = $row;

            return $rows; 
        else:
            return [];
        endif;
    }

    public function getFlow($id, $uid)
    {
        return $this->db->query_one("SELECT id, uid, name, source, flow_data, create_date FROM flows WHERE id = ? AND uid = ?", [
            $id,
            $uid
        ]);
    }

    public function getFlows($uid, $source)
    {
        $query = <<<SQL
SELECT id, uid, name, source, flow_data, create_date
FROM flows
WHERE uid = ? AND source = ?
SQL;

        $this->db->query($query, [
            $uid,
            $source
        ]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[$row["id"]] = $row;

            return $rows; 
        else:
            return [];
        endif;
    }
}
