UPDATE {TABLE}
SET table_id = :table_id, party_size = :party_size, starts_at = :starts_at, ends_at = :ends_at,
    admin_notes = :admin_notes, ics_sequence = :ics_sequence
WHERE id = :id
