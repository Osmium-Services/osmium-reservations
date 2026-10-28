SELECT B.*, T.name AS table_name, T.seats AS table_seats
FROM {TABLE} AS B
LEFT JOIN {PREFIX}reservation_tables AS T ON T.id = B.table_id
WHERE B.id = :id
