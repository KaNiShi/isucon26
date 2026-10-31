-- 001-schema.sql のあとで追加したインデックス。init.sh（POST /api/initialize）が毎回流す。
-- reset.sql はテーブルを作り直さないので、既存の DB にはここで作る。
-- すでにあれば何もしないので、2 回目以降の初期化はすぐ終わる。
--
-- CONCURRENTLY は使わない。初期化の途中なのでほかの書き込みは来ず、
-- 失敗しても INVALID なインデックスが残らないようにトランザクションで作る。
-- 負荷走行中に手で流すと、作り終わるまで対象テーブルへの書き込みが待たされる。
-- インデックスを足すときは 001-schema.sql にも同じものを書く。

BEGIN;

CREATE INDEX IF NOT EXISTS users_username ON users(username);
CREATE INDEX IF NOT EXISTS login_sessions_token_hash ON login_sessions(token_hash);
CREATE INDEX IF NOT EXISTS login_sessions_user_id ON login_sessions(user_id) WHERE active;
CREATE INDEX IF NOT EXISTS friendships_user_high_id ON friendships(user_high_id);
CREATE INDEX IF NOT EXISTS follows_followee_id ON follows(followee_id);
CREATE INDEX IF NOT EXISTS block_relations_blocked_id ON block_relations(blocked_id);
CREATE INDEX IF NOT EXISTS posts_image_id ON posts(image_id);
CREATE INDEX IF NOT EXISTS friend_requests_recipient_id_status ON friend_requests(recipient_id, status);
CREATE INDEX IF NOT EXISTS friend_requests_requester_id_status ON friend_requests(requester_id, status);
CREATE INDEX IF NOT EXISTS realmojis_image_reactor_active ON realmojis(image_id, reactor_id) WHERE replaced_at IS NULL AND deleted_at IS NULL;

COMMIT;
