-- 画像の id から created_at などを引くための表（image_objects から本体 data を除いた写し）。
-- image_objects は created_at でパーティションを分けているので、id だけで探すと全パーティションを見てしまう。
-- この表で created_at を引いてから image_objects を id と created_at で読めば、1 つのパーティションだけで済む。
CREATE TABLE IF NOT EXISTS image_metas (
  id varchar(36) NOT NULL,
  owner_user_id varchar(36) NOT NULL,
  media_type text NOT NULL,
  created_at timestamptz NOT NULL,
  PRIMARY KEY (id, created_at)
);

-- image_objects の追加・削除に合わせる。image_objects の行は書き換えられないので UPDATE は扱わない
CREATE OR REPLACE FUNCTION image_metas_sync() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    INSERT INTO image_metas (id, owner_user_id, media_type, created_at)
    VALUES (NEW.id, NEW.owner_user_id, NEW.media_type, NEW.created_at);
    RETURN NEW;
  END IF;
  DELETE FROM image_metas WHERE id = OLD.id AND created_at = OLD.created_at;
  RETURN OLD;
END
$$;

CREATE OR REPLACE TRIGGER image_metas_sync AFTER INSERT OR DELETE ON image_objects
  FOR EACH ROW EXECUTE FUNCTION image_metas_sync();

-- image_objects と同じ中身にそろえる。initialize は 1 分以内に終わらせる必要があるので、
-- 全部を写す（約 180 万行）のは表が空のとき（最初の 1 回）だけにする。
-- 2 回目からは reset.sql と同じく初期データより新しい行だけを消す。
-- reset.sql はトリガーを止めて（session_replication_role = replica）消すので、ここで合わせる。
-- 走行中の追加・削除はトリガーが写しているので、これで image_objects と同じになる
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM image_metas) THEN
    -- 索引を外して写し、あとから作るほうが速い。本体は TOAST に置かれるので、ここでは読まない
    ALTER TABLE image_metas DROP CONSTRAINT IF EXISTS image_metas_pkey;
    DROP INDEX IF EXISTS image_metas_created_at;
    INSERT INTO image_metas (id, owner_user_id, media_type, created_at)
    SELECT id, owner_user_id, media_type, created_at FROM image_objects;
    ALTER TABLE image_metas ADD PRIMARY KEY (id, created_at);
    CREATE INDEX image_metas_created_at ON image_metas (created_at);
  ELSE
    DELETE FROM image_metas WHERE created_at > (SELECT created_at FROM initial_data_boundary);
  END IF;
END
$$;
CREATE INDEX IF NOT EXISTS image_metas_created_at ON image_metas (created_at);
-- 統計は標本だけ見るので、毎回でもすぐ終わる
ANALYZE image_metas;
