SELECT T.*
FROM {PREFIX}reservation_tables AS T
WHERE T.enabled = 1 AND T.seats >= :party_size
  AND NOT EXISTS (
    SELECT 1 FROM {PREFIX}reservations AS A
    WHERE A.table_id = T.id AND A.status IN ('requested', 'confirmed', 'seated') AND A.deleted_at IS NULL AND A.id <> :id
      AND A.starts_at < :ends_at AND A.ends_at > :starts_at
  )
ORDER BY T.seats ASC, T.sort_order ASC, T.id ASC
LIMIT 1
