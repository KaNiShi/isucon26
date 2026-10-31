-- 既存の DB に 001-schema.sql で追加したインデックスを作る。
-- reset.sql（POST /api/initialize）はテーブルを作り直さないので、一度だけ手で流す。
--
-- CONCURRENTLY は書き込みを止めずに作るが、トランザクションの中では実行できない。
-- psql -f で流す（各文が自動でコミットされる）。--single-transaction は付けない。
-- 途中で失敗すると INVALID なインデックスが残ることがあるので、その場合は
-- \d <テーブル名> で確かめてから作り直す。

CREATE INDEX CONCURRENTLY IF NOT EXISTS users_username ON users(username);
CREATE INDEX CONCURRENTLY IF NOT EXISTS login_sessions_token_hash ON login_sessions(token_hash);
CREATE INDEX CONCURRENTLY IF NOT EXISTS login_sessions_user_id ON login_sessions(user_id) WHERE active;
CREATE INDEX CONCURRENTLY IF NOT EXISTS friendships_user_high_id ON friendships(user_high_id);
CREATE INDEX CONCURRENTLY IF NOT EXISTS follows_followee_id ON follows(followee_id);
CREATE INDEX CONCURRENTLY IF NOT EXISTS block_relations_blocked_id ON block_relations(blocked_id);
CREATE INDEX CONCURRENTLY IF NOT EXISTS posts_image_id ON posts(image_id);
CREATE INDEX CONCURRENTLY IF NOT EXISTS friend_requests_recipient_id_status ON friend_requests(recipient_id, status);
CREATE INDEX CONCURRENTLY IF NOT EXISTS friend_requests_requester_id_status ON friend_requests(requester_id, status);
CREATE INDEX CONCURRENTLY IF NOT EXISTS realmojis_image_reactor_active ON realmojis(image_id, reactor_id) WHERE replaced_at IS NULL AND deleted_at IS NULL;

ANALYZE users, login_sessions, friendships, follows, block_relations, posts, friend_requests, realmojis;
