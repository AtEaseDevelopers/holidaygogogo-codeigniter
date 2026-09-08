<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Ghl_Messages_Model extends CI_Model
{
    public function upsert_message($data)
    {
        $messageId = isset($data['message_id']) ? trim((string) $data['message_id']) : '';
        $now = $this->get_code_datetime();

        if ($messageId === '') {
            return false;
        }

        $existing = $this->db
            ->select('id')
            ->from('ghl_messages')
            ->where('message_id', $messageId)
            ->limit(1)
            ->get()
            ->row_array();

        $columns = array(
            'message_id',
            'location_id',
            'conversation_id',
            'contact_id',
            'user_id',
            'alt_id',
            'direction',
            'status',
            'message_type_code',
            'message_type',
            'content_type',
            'body',
            'from_number',
            'to_number',
            'date_added',
            'date_updated',
            'attachments_json',
            'meta_json',
            'raw_json',
        );

        $insert = array();
        foreach ($columns as $column) {
            $insert[$column] = array_key_exists($column, $data) ? $data[$column] : null;
        }

        if (!empty($existing['id'])) {
            $updated = $this->db
                ->where('id', (int) $existing['id'])
                ->update('ghl_messages', array(
                    'location_id' => $insert['location_id'],
                    'conversation_id' => $insert['conversation_id'],
                    'contact_id' => $insert['contact_id'],
                    'user_id' => $insert['user_id'],
                    'alt_id' => $insert['alt_id'],
                    'direction' => $insert['direction'],
                    'status' => $insert['status'],
                    'message_type_code' => $insert['message_type_code'],
                    'message_type' => $insert['message_type'],
                    'content_type' => $insert['content_type'],
                    'body' => $insert['body'],
                    'from_number' => $insert['from_number'],
                    'to_number' => $insert['to_number'],
                    'date_added' => $insert['date_added'],
                    'date_updated' => $insert['date_updated'],
                    'attachments_json' => $insert['attachments_json'],
                    'meta_json' => $insert['meta_json'],
                    'raw_json' => $insert['raw_json'],
                    'updated_at' => $now,
                ));

            return $updated ? 'updated' : false;
        }

        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        $inserted = $this->db->insert('ghl_messages', $insert);
        return $inserted ? 'inserted' : false;
    }

    /**
     * Given a page's phone numbers, return the set of those that have at least
     * one stored WhatsApp message — so the listing only shows a "message log"
     * icon when a conversation actually exists. Keyed by normalized digits.
     *
     * One query, matched via index-friendly exact IN on from_number/to_number
     * (see ghl_message_log_helper.php).
     *
     * @param string[] $page_phones Listing rows' phones (any format).
     * @return array<string,bool> [normalized_digits => true] for phones with a log.
     */
    public function Phones_With_Messages($page_phones)
    {
        $this->load->helper('ghl_message_log');

        $candidates = array();
        foreach ((array) $page_phones as $phone) {
            foreach (ghl_message_log_phone_candidates($phone) as $c) {
                $candidates[$c] = true;
            }
        }
        if (empty($candidates)) {
            return array();
        }
        $candidates = array_keys($candidates);
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));

        $rows = $this->db->query("
            SELECT DISTINCT from_number, to_number
            FROM ghl_messages
            WHERE from_number IN ($placeholders)
               OR to_number IN ($placeholders)
        ", array_merge($candidates, $candidates))->result_array();

        $out = array();
        foreach (ghl_message_log_match_phones($rows, $page_phones) as $digits) {
            $out[$digits] = true;
        }
        return $out;
    }

    /**
     * Convenience wrapper for the Guest List / GHL Leads listing: derive each
     * guest row's wa-digits (CallingCode + ContactNum) and return the set that
     * has a stored conversation. Keyed by normalized digits.
     *
     * @param array $guests Guest rows (objects with CallingCode, ContactNum).
     * @return array<string,bool>
     */
    public function Phones_With_Messages_For_Guests($guests)
    {
        if (empty($guests)) {
            return array();
        }
        $this->load->helper('guest_contact');
        $phones = array();
        foreach ($guests as $g) {
            $digits = guest_contact_wa_digits(
                isset($g->CallingCode) ? (string) $g->CallingCode : '',
                (string) $g->ContactNum
            );
            if ($digits !== '') {
                $phones[] = $digits;
            }
        }
        return $this->Phones_With_Messages($phones);
    }

    /**
     * Companion to Phones_With_Messages_For_Guests for GHL contacts that have
     * NO stored phone (ghl_contacts.phone empty → the listing shows "—", so the
     * phone-based icon can never appear). Their WhatsApp history is still owned
     * by the GHL contact id, so we look messages up by contact id instead.
     *
     * Only phone-less GHL rows are checked (phone rows already get the phone
     * icon), keyed by the row's dedup_key so the view can flag them.
     *
     * @param array $guests Guest rows (objects with Type, ContactNum, dedup_key).
     * @return array<string,bool> dedup_key => true for rows with messages.
     */
    public function Contacts_With_Messages_For_Guests($guests)
    {
        if (empty($guests)) {
            return array();
        }
        $this->load->helper('guest_contact');
        $keys = array();
        foreach ($guests as $g) {
            // Phone-less GHL rows only: phone rows already get the phone icon,
            // Manual leads / booking guests have no history by contact id.
            $dk = guest_contact_phoneless_ghl_key($g);
            if ($dk !== '') {
                $keys[$dk] = true;
            }
        }
        return $this->Dedup_Keys_With_Messages(array_keys($keys));
    }

    /**
     * Given GHL listing dedup_keys, return the subset that has at least one
     * stored WhatsApp message, looked up by contact id. A row's dedup_key maps
     * to its GHL contact via COALESCE(ghl_contacts.dedup_key, 'ghl:'||id).
     *
     * @param array $dedup_keys
     * @return array<string,bool> dedup_key => true.
     */
    public function Dedup_Keys_With_Messages($dedup_keys)
    {
        $dedup_keys = array_values(array_unique(array_filter(
            (array) $dedup_keys,
            function ($k) { return trim((string) $k) !== ''; }
        )));
        if (empty($dedup_keys)) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($dedup_keys), '?'));
        $rows = $this->db->query("
            SELECT COALESCE(gc.dedup_key, CONCAT('ghl:', gc.id)) AS dk
            FROM ghl_contacts gc
            JOIN ghl_messages gm ON gm.contact_id = gc.contact_id
            WHERE COALESCE(gc.dedup_key, CONCAT('ghl:', gc.id)) IN ($placeholders)
            GROUP BY dk
        ", $dedup_keys)->result_array();

        $out = array();
        foreach ($rows as $row) {
            $out[$row['dk']] = true;
        }
        return $out;
    }

    /**
     * The full WhatsApp conversation for a GHL contact identified by its listing
     * dedup_key (used when the contact has no stored phone). Shaped like
     * Conversation_By_Phone for the same chat modal.
     *
     * @param string $dedup_key The listing row's dedup_key (e.g. "ghl:21").
     * @param int    $limit     Max messages returned.
     * @return array<int,array{side:string,author:string,body:string,time:string,type:string}>
     */
    public function Conversation_By_Dedup_Key($dedup_key, $limit = 500, $since = null)
    {
        $this->load->helper('ghl_message_log');

        $dedup_key = trim((string) $dedup_key);
        if ($dedup_key === '') {
            return array();
        }
        $limit = max(1, (int) $limit);

        // Optional delta: only messages newer than a prior analysis watermark.
        $since_sql = ''; $bind = array($dedup_key);
        $since = trim((string) $since);
        if ($since !== '') {
            $since_sql = ' AND gm.date_added > ?';
            $bind[] = $since;
        }

        $rows = $this->db->query("
            SELECT gm.direction, gm.body, gm.message_type, gm.date_added,
                   gu.Name AS agent_name, cv.contact_name
            FROM ghl_contacts gc
            JOIN ghl_messages gm ON gm.contact_id = gc.contact_id
            LEFT JOIN ghl_users gu ON gu.UserID = gm.user_id
            LEFT JOIN ghl_conversations cv ON cv.conversation_id = gm.conversation_id
            WHERE COALESCE(gc.dedup_key, CONCAT('ghl:', gc.id)) = ?{$since_sql}
            ORDER BY gm.date_added ASC, gm.id ASC
            LIMIT {$limit}
        ", $bind)->result_array();

        $out = array();
        foreach ($rows as $row) {
            $out[] = ghl_message_log_shape_message($row);
        }
        return $out;
    }

    /**
     * The full WhatsApp conversation for a single phone, oldest first, shaped
     * for the chat modal. Outbound messages carry the agent's GHL user name.
     *
     * @param string $phone A phone in any format.
     * @param int    $limit Max messages returned.
     * @return array<int,array{side:string,author:string,body:string,time:string,type:string}>
     */
    public function Conversation_By_Phone($phone, $limit = 500, $since = null)
    {
        $this->load->helper('ghl_message_log');

        $candidates = ghl_message_log_phone_candidates($phone);
        if (empty($candidates)) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $limit = max(1, (int) $limit);

        // Optional delta: only messages newer than a prior analysis watermark.
        $bind = array_merge($candidates, $candidates);
        $since_sql = '';
        $since = trim((string) $since);
        if ($since !== '') {
            $since_sql = ' AND gm.date_added > ?';
            $bind[] = $since;
        }

        $rows = $this->db->query("
            SELECT gm.direction, gm.body, gm.message_type, gm.date_added,
                   gu.Name AS agent_name, gc.contact_name
            FROM ghl_messages gm
            LEFT JOIN ghl_users gu ON gu.UserID = gm.user_id
            LEFT JOIN ghl_conversations gc ON gc.conversation_id = gm.conversation_id
            WHERE (gm.from_number IN ($placeholders)
               OR gm.to_number IN ($placeholders)){$since_sql}
            ORDER BY gm.date_added ASC, gm.id ASC
            LIMIT {$limit}
        ", $bind)->result_array();

        $out = array();
        foreach ($rows as $row) {
            $out[] = ghl_message_log_shape_message($row);
        }
        return $out;
    }

    /**
     * Cheap stats for a conversation without pulling every row: total message
     * count + the newest date_added. Used by the AI-analysis "memory" flow to
     * detect new activity (skip / incremental). Returns ['count'=>int,'latest'=>?string].
     */
    public function Conversation_Meta_By_Dedup_Key($dedup_key)
    {
        $dedup_key = trim((string) $dedup_key);
        if ($dedup_key === '') {
            return array('count' => 0, 'latest' => null);
        }
        $row = $this->db->query("
            SELECT COUNT(*) AS c, MAX(gm.date_added) AS m
            FROM ghl_contacts gc
            JOIN ghl_messages gm ON gm.contact_id = gc.contact_id
            WHERE COALESCE(gc.dedup_key, CONCAT('ghl:', gc.id)) = ?
        ", array($dedup_key))->row();
        return array('count' => (int) $row->c, 'latest' => $row->m);
    }

    public function Conversation_Meta_By_Phone($phone)
    {
        $this->load->helper('ghl_message_log');
        $candidates = ghl_message_log_phone_candidates($phone);
        if (empty($candidates)) {
            return array('count' => 0, 'latest' => null);
        }
        $ph = implode(',', array_fill(0, count($candidates), '?'));
        $row = $this->db->query("
            SELECT COUNT(*) AS c, MAX(gm.date_added) AS m
            FROM ghl_messages gm
            WHERE gm.from_number IN ($ph) OR gm.to_number IN ($ph)
        ", array_merge($candidates, $candidates))->row();
        return array('count' => (int) $row->c, 'latest' => $row->m);
    }

    private function get_code_datetime()
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))
            ->format('Y-m-d H:i:s');
    }
}
