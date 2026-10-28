SELECT B.id, B.reference, B.customer_name, B.starts_at, B.deleted_at, CL.user_name AS changed_by, CU.avatar AS changed_by_avatar
FROM {TABLE} AS B
LEFT JOIN {PREFIX}changelog AS CL ON CL.id = (SELECT MAX(id) FROM {PREFIX}changelog WHERE record_type = 'reservation' AND record_id = B.id)
LEFT JOIN {PREFIX}users AS CU ON CU.id = CL.user_id
WHERE B.deleted_at IS NOT NULL
ORDER BY B.deleted_at DESC
