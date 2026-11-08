## 利用言語

PHPを利用する。
`webapp` 以下にはPHP以外の実装も存在するが利用しないため、参照しない。

## ディレクトリ構成

以下に記載しないディレクトリについては利用しない。

```
.
├── manual.md       競技マニュアル(詳細はここを参照)
├── docs/           開発用ドキュメント
└── webapp/
    ├── php/        PHPアプリケーション
    ├── sql/        DB SQL
    └── public/     静的ファイル
```

## 実装規約

- テストの変更はしない
- ソースコードにコメントは書かない
- 実装に関係しない変更はしない
- ベンチマークはポータルサイトから手動で実行するため、エージェントは実行しない
- `manual.md` は書き換えない

## アプリケーション概要

ISURealは写真共有SNS。投稿期間(posting window)の開始通知を受け、写真を投稿し、友達の写真を閲覧する。コメントとRealMoji(画像リアクション)を送れる。

- リージョン: `asia` / `europe` / `america`
- 通知: `GET /api/notifications` をSSEで購読
- 時間内投稿: 投稿期間の `onTimeDeadline` までの投稿。期限後も期間終了までは投稿でき、`lateSeconds`(秒未満切り捨て、時間内は0)を返す
- ログイン: 初期ユーザー `yuma_kobayashi_186` (region: asia)。管理者 `admin` / `password1234` は変更・削除禁止

## 競技環境

| サーバ | リージョン | 初期状態の役割 |
|---|---|---|
| asia1 | asia | nginx、アプリ |
| europe1 | europe | nginx、アプリ |
| america1 | america | nginx、アプリ |
| asia2 | asia | 共有PostgreSQL 16 |
| ルーター | - | 国別転送、遅延・帯域制限(ログイン・設定変更禁止) |

- 通信はルーター経由。`X-Country-Code` でリージョンが決まる(省略時 `JP`)
  - asia: `JP` `KR` `CN` `AU` / europe: `GB` `DE` `FR` / america: `US` `CA` `MX`
- クライアントIPは `X-Client-IP` の値を使う
- DB接続情報は各サーバの `/home/isucon/env.sh` (`ISUREAL_DB_HOST` `ISUREAL_DB_PORT` `ISUREAL_DB_USER` `ISUREAL_DB_PASSWORD` `ISUREAL_DB_NAME`)
- DB初期化: `~/webapp/sql/init.sh`

## 初期化 (`POST /api/initialize`)

- DB、独自に追加したキャッシュ・複製先を含め、全リージョンで整合した初期状態へ戻す
- 初期データの履歴と画像を取得できる状態を保つ
- 成功時は `language` を空でない値にして返す(`lang` ではない)

```json
{"language":"php","agent":"<エージェント名>","harness":"<実行ツール名>","model":"<モデル名>"}
```

## 変更してよい範囲

- 変更可: サーバ内ソフトウェア、DBスキーマ、インデックス、保存方式、アプリ実装、キャッシュ追加、他言語での再実装
- 維持する: URI・HTTPメソッド・ステータス・JSON/HTML構造、静的ファイルの内容、認証・閲覧制限・公開範囲・削除・画像署名の検証結果、初期データ・初期化処理、データの永続化と再起動後の動作
- 変更禁止:
  - `isucon-register.service` 関連、`isuadmin` ユーザー関連、運営用アカウントとSSH公開鍵
  - envcheck関連: `/usr/local/bin/envcheck` `/usr/local/bin/isucon-contest-envcheck` `/etc/systemd/system/envcheck.service` `/etc/systemd/system/envcheck-baseline.service` `/etc/isucon/envcheck.json` `/var/lib/isucon-envcheck/`
  - SSH(22)・HTTPS(443)・ルーターHTTP(8080)のセキュリティグループ
  - サーバのリージョン、通信遅延、帯域制限、ルーター経由の通信
  - インスタンスタイプなどCloudFormationテンプレート内容に反する構成変更
- 禁止: ベンチマーカーだけを識別して挙動を変える実装、固定応答による整合性検証の回避、外部サーバへの処理委譲

## 仕様上の重要事項

- 画像URLの署名・期限の形式と検証結果は変えない。不正署名・期限切れ・無効画像は `403`
- 投稿削除後5秒を過ぎたら、期限内の署名付きURLでも画像を取得できてはいけない
- 書き込み成功後の読み取りには、リージョンをまたいでも結果を反映する(公開範囲・友達関係・ブロックは猶予なし)
- ベンチマーカーはリダイレクトを追わない。`503` は再試行されるが計測からは除外されない
- 閲覧権限のない投稿の返却・操作の受理、履歴・画像の不一致はクリティカルエラーで負荷走行がFAILになる

## スコア

スコア = Σ(採点件数 × 配点 × リージョン倍率)。倍率は asia 1 / europe 1.5 / america 1.5。FAILは0点。

- 高配点: エクスポート 20、最後の投稿期間の友達フィード検証通過 20、通常の投稿期間の同検証 10、RealMoji送信 5、退会 5、コメント投稿 3、プロフィール画像変更・削除 3、Memories月単位 3
- 画像取得・過去の投稿の画像取得・ユーザー検索・友達申請の送信/承認・Memories日付単位は 2、その他の閲覧・変更系は主に 1
- 友達フィード検証は、投稿受理後かつその投稿期間の終了前に成功した場合のみ、ユーザーごと投稿期間ごとに1回加点

## 追試で失格となる条件

- 競技終了時に4台(asia1/europe1/america1/asia2)のいずれかが停止している
- `sudo /usr/local/bin/envcheck` に失敗する、または複数スタックのサーバ情報がポータルに混在している
- 再起動後にベンチマークで書き込んだデータが取得できない
- 再実行したベンチマークがFAIL、または最終スコアの75%以下
- フロントエンドの構造がリファレンス実装と異なる
