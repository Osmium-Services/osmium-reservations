SELECT T.*, (SELECT COUNT(*) FROM {PREFIX}reservations AS B WHERE B.table_id = T.id) AS reservation_count
FROM {TABLE} AS T
ORDER BY T.sort_order ASC, T.seats ASC, T.name ASC
