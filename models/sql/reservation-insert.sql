INSERT INTO {TABLE}
    (reference, table_id, party_size, customer_name, customer_email, customer_phone, customer_notes, starts_at, ends_at, status)
VALUES
    (:reference, :table_id, :party_size, :customer_name, :customer_email, :customer_phone, :customer_notes, :starts_at, :ends_at, 'requested')
