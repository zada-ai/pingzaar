<?php

class Ai_Model extends MVC_Model
{
    public function checkAction($id)
    {
        $this->db->query("SELECT id FROM actions WHERE id = ? LIMIT 1", [
            $id
        ]);

        return $this->db->num_rows();
    }

    public function getAction($id)
    {
        return $this->db->query_one("SELECT id, ai_key, ai_plugins FROM actions WHERE id = ?", [
            $id
        ]);
    }

    public function getActionPlugins($id)
    {
        $query = <<<SQL
SELECT p.id AS id, p.name AS name, p.schema AS `schema`, p.endpoint AS endpoint
FROM ai_plugins p
WHERE FIND_IN_SET(p.id, (SELECT ai_plugins FROM actions WHERE id = ? LIMIT 1)) > 0
SQL;

        $this->db->query($query, [$id]);

        if ($this->db->num_rows() > 0):
            while ($row = $this->db->next())
                $rows[] = $row;

            return $rows;
        else:
            return [];
        endif;
    }

    public function getAiKey($id)
    {
        return $this->db->query_one("SELECT id, `uid`, `provider` FROM ai_keys WHERE id = ?", [
            $id
        ]);
    }
}