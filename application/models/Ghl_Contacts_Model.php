<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Ghl_Contacts_Model extends CI_Model
{
    public function upsert_contact($data)
    {
        $contactId = isset($data['contact_id']) ? trim((string) $data['contact_id']) : '';
        $now = $this->get_code_datetime();
        $customFieldValues = isset($data['custom_field_values']) && is_array($data['custom_field_values'])
            ? $data['custom_field_values']
            : array();

        if ($contactId === '') {
            return false;
        }

        $existing = $this->db
            ->select('id')
            ->from('ghl_contacts')
            ->where('contact_id', $contactId)
            ->limit(1)
            ->get()
            ->row_array();

        $columns = array(
            'contact_id',
            'location_id',
            'contact_name',
            'first_name',
            'last_name',
            'first_name_raw',
            'last_name_raw',
            'company_name',
            'email',
            'phone',
            'assigned_to',
            'business_id',
            'contact_type',
            'source',
            'dnd',
            'dnd_settings_json',
            'city',
            'state',
            'postal_code',
            'address1',
            'country',
            'website',
            'timezone',
            'profile_photo',
            'date_of_birth',
            'date_added',
            'date_updated',
            'tags_json',
            'additional_emails_json',
            'followers_json',
            'attributions_json',
            'custom_fields_json',
        );

        $insert = array();
        foreach ($columns as $column) {
            $insert[$column] = array_key_exists($column, $data) ? $data[$column] : null;
        }

        if (!empty($existing['id'])) {
            $update = $insert;
            unset($update['contact_id']);
            $update['updated_at'] = $now;

            $updated = $this->db
                ->where('id', (int) $existing['id'])
                ->update('ghl_contacts', $update);

            if (!$updated) {
                return false;
            }

            $this->replace_custom_field_values($contactId, $customFieldValues);
            return 'updated';
        }

        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;
        $inserted = $this->db->insert('ghl_contacts', $insert);
        if (!$inserted) {
            return false;
        }

        $this->replace_custom_field_values($contactId, $customFieldValues);
        return 'inserted';
    }

    public function replace_custom_field_values($contactId, $values)
    {
        $contactId = trim((string) $contactId);
        if ($contactId === '') {
            return false;
        }

        $this->db->where('contact_id', $contactId)->delete('ghl_contact_custom_field_values');

        if (empty($values) || !is_array($values)) {
            return true;
        }

        $now = $this->get_code_datetime();
        $rows = array();

        foreach ($values as $value) {
            if (!is_array($value) || empty($value['field_identity'])) {
                continue;
            }

            $rows[] = array(
                'contact_id' => $contactId,
                'field_identity' => (string) $value['field_identity'],
                'field_id' => isset($value['field_id']) ? $value['field_id'] : null,
                'field_key' => isset($value['field_key']) ? $value['field_key'] : null,
                'field_name' => isset($value['field_name']) ? $value['field_name'] : null,
                'value_text' => isset($value['value_text']) ? $value['value_text'] : null,
                'value_json' => isset($value['value_json']) ? $value['value_json'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            );
        }

        if (empty($rows)) {
            return true;
        }

        return $this->db->insert_batch('ghl_contact_custom_field_values', $rows) !== false;
    }

    /**
     * Insert a hand-entered ("Manual") lead. $row is the whitelisted array from
     * ghl_manual_lead_prepare() (synthetic contact_id, lead_source = 'manual',
     * the visible-column fields + tags_json). Never updates: a manual lead's
     * synthetic contact_id is unique, so this is always a fresh insert. Returns
     * the new row id, or false on failure.
     */
    public function create_manual_lead($row)
    {
        if (empty($row['contact_id'])) {
            return false;
        }

        $now = $this->get_code_datetime();

        $allowed = array(
            'contact_id', 'first_name', 'last_name', 'company_name', 'email', 'phone',
            'assigned_to', 'lead_source', 'created_by', 'gender', 'race', 'nationality',
            'address', 'country', 'source', 'notes', 'customer_type', 'lead_intro',
            'nature_of_business', 'number_of_pax', 'client_type', 'state',
            'travel_date', 'travel_end_date', 'sales_person_id', 'op_id', 'destination', 'potential_sales_amount',
            'chat_language', 'date_of_birth', 'tags_json', 'date_added', 'high_chance',
        );

        $insert = array();
        foreach ($allowed as $column) {
            if (array_key_exists($column, $row)) {
                $insert[$column] = $row[$column];
            }
        }

        $insert['created_at'] = $now;
        $insert['updated_at'] = $now;

        if (!$this->db->insert('ghl_contacts', $insert)) {
            return false;
        }

        return (int) $this->db->insert_id();
    }

    /**
     * Update an existing hand-entered ("Manual") lead's editable columns. $row is
     * the whitelisted array from ghl_manual_lead_prepare(); only the visible-column
     * fields are applied — the identity/ownership columns (contact_id, lead_source,
     * created_by, date_added) are deliberately NOT in the whitelist, so an edit can
     * never re-key the lead or steal its creator. The WHERE is pinned to
     * lead_source = 'manual', so a GHL-synced row can never be touched by this path.
     * Returns true on success (0 changed rows still counts), false otherwise.
     */
    public function update_manual_lead($id, $row)
    {
        $id = (int) $id;
        if ($id < 1) {
            return false;
        }

        // Only the columns the Edit form actually exposes. last_name and
        // date_of_birth are intentionally excluded: the shared Create/Edit form has
        // no input for them, so an absent POST value must NOT wipe a stored value
        // (e.g. a last_name/DOB brought in by bulk import).
        $allowed = array(
            'first_name', 'company_name', 'email', 'phone', 'gender',
            'race', 'nationality', 'address', 'country', 'source', 'notes',
            'lead_intro', 'chat_language',
            'nature_of_business', 'number_of_pax', 'client_type', 'state',
            'travel_date', 'travel_end_date', 'sales_person_id', 'op_id', 'destination', 'potential_sales_amount',
            'tags_json', 'high_chance',
        );

        $update = array();
        foreach ($allowed as $column) {
            if (array_key_exists($column, $row)) {
                $update[$column] = $row[$column];
            }
        }
        if (empty($update)) {
            return false;
        }

        $update['updated_at'] = $this->get_code_datetime();

        $this->db->where('id', $id)->where('lead_source', 'manual');
        return $this->db->update('ghl_contacts', $update);
    }

    /**
     * The dedup_key the Guest/Manual listing shows for a contact row — exactly the
     * COALESCE(gc.dedup_key, CONCAT('ghl:', gc.id)) the SELECT uses — so a Lead
     * Status log entry seeded at create time keys to the SAME value the listing's
     * Action menu later passes back. Returns null if the id is unknown.
     */
    public function effective_dedup_key($id)
    {
        $row = $this->db->select('dedup_key, id')->from('ghl_contacts')->where('id', (int) $id)->get()->row();
        if (!$row) {
            return null;
        }
        return ($row->dedup_key !== null) ? (string) $row->dedup_key : ('ghl:' . (int) $row->id);
    }

    public function get_last_contact_cursor()
    {
        $row = $this->db
            ->select('contact_id')
            ->from('ghl_contacts')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

       return $row['contact_id'] ?? null;
    }

    private function get_code_datetime()
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))
            ->format('Y-m-d H:i:s');
    }
}
