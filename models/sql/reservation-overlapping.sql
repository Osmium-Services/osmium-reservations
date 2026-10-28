SELECT A.id, A.reference, A.customer_name, A.starts_at, A.ends_at
FROM {TABLE} AS A
WHERE A.status IN ('confirmed', 'seated') AND A.deleted_at IS NULL AND A.id <> :id
  AND A.table_id = :table_id
  AND A.starts_at < :ends_at AND A.ends_at > :starts_at
ORDER BY A.starts_at
LIMIT 1
