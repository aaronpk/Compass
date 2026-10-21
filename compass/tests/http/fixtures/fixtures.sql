-- Rows for the HTTP harness. User 1 logs in through the fixture IndieAuth server.
INSERT INTO users (id, url, created_at, last_login) VALUES
  (1, 'http://127.0.0.1:18280/me/', '2025-11-01 00:00:00', '2025-11-02 00:00:00'),
  (2, 'https://someone-else.example/', '2025-11-01 00:00:00', NULL),
  (3, 'https://friend.example/', '2025-11-01 00:00:00', NULL);

INSERT INTO `databases` (id, name, read_token, write_token, created_by, created_at,
    micropub_endpoint, micropub_token, ping_urls, timezone, metric, last_location, last_location_date, current_trip) VALUES
  (1, 'fixture', 'r-fixture', 'w-fixture', 1, '2025-11-01 00:00:00',
    'http://127.0.0.1:18280/micropub', 'mp-token', 'http://127.0.0.1:18280/ping http://127.0.0.1:18280/ping2', 'America/Los_Angeles', 1,
    '{"type":"Feature","geometry":{"type":"Point","coordinates":[-122.6860,45.5330]},"properties":{"timestamp":"2026-01-16T09:00:00Z","horizontal_accuracy":10}}',
    '2026-01-16 09:00:00', NULL),
  (2, 'emptyline', 'r-emptyline', 'w-emptyline', 1, '2025-11-01 00:00:00', '', '', '', 'Europe/Paris', 1, NULL, NULL, NULL),
  (3, 'nodata', 'r-nodata', 'w-nodata', 1, '2025-11-01 00:00:00', '', '', '', 'UTC', 0, NULL, NULL,
    '{"mode":"bicycle","start":"2026-01-16T08:00:00Z","current_location":{"type":"Feature","geometry":{"type":"Point","coordinates":[-122.68,45.53]}}}'),
  (4, 'other', 'r-other', 'w-other', 2, '2025-11-01 00:00:00', '', '', '', 'UTC', 1, NULL, NULL, NULL);

INSERT INTO database_users (database_id, user_id, created_at) VALUES
  (1, 1, '2025-11-01 00:00:00'), (2, 1, '2025-11-01 00:00:00'), (3, 1, '2025-11-01 00:00:00'),
  (4, 2, '2025-11-01 00:00:00'), (1, 3, '2025-11-01 00:00:00');

INSERT INTO shares (id, database_id, expires_at, token, created_at) VALUES
  (1, 1, '2099-01-01 00:00:00', 'shareactive', '2026-01-15 16:07:00'),
  (2, 1, '2020-01-01 00:00:00', 'shareexpired', '2019-12-31 00:00:00'),
  (3, 3, '2099-01-01 00:00:00', 'sharenodata', '2026-01-01 00:00:00');
