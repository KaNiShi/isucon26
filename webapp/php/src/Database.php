<?php

declare(strict_types=1);

namespace Isureal;

use PDO;
use PDOStatement;
use Pdo\Pgsql;

/**
 * PDO の薄いラッパー。プレースホルダは名前付き（:name）で書く。
 */
final class Database
{
    public function __construct(private readonly Pgsql $pdo)
    {
    }

    /**
     * @param bool $persistent php-fpm の worker ごとに接続を使い回すかどうか
     * @param int|null $timeoutSeconds 接続タイムアウト秒数
     */
    public static function connect(Config $config, bool $persistent = true, ?int $timeoutSeconds = null): self
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => $persistent,
            // prepare と実行を分けると DB との往復が 2 回になるので、1 回で済ませる
            Pgsql::ATTR_DISABLE_PREPARES => true,
        ];
        if ($timeoutSeconds !== null) {
            $options[PDO::ATTR_TIMEOUT] = $timeoutSeconds;
        }
        $pdo = new Pgsql($config->databaseDsn(), $config->dbUser, $config->dbPassword, $options);
        // 前のリクエストが途中で終わってトランザクションが残っていれば捨てる
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return new self($pdo);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * 先頭の行の先頭の列を返す。行がなければ null
     *
     * @param array<string, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        // fetchColumn() は列の値の false と行がないことを区別できないので、行で受け取る
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /**
     * 各行の先頭の列を返す
     *
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    public function fetchColumn(string $sql, array $params = []): array
    {
        /** @var list<mixed> */
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array<string, mixed> $params
     * @return int 変更した行数
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * NOTIFY を受け取れるようにする。チャンネル名は定数で渡すこと
     */
    public function listen(string $channel): void
    {
        $this->pdo->exec('LISTEN ' . $channel);
    }

    /**
     * NOTIFY が届くまで最大 $timeoutMilliseconds ミリ秒待つ。届かなければ null
     *
     * @return array{message: string, pid: int, payload?: string}|null
     */
    public function waitNotify(int $timeoutMilliseconds): ?array
    {
        /** @var array{message: string, pid: int, payload?: string}|false $notify */
        $notify = $this->pdo->getNotify(PDO::FETCH_ASSOC, $timeoutMilliseconds);
        return $notify === false ? null : $notify;
    }

    /**
     * 複数の文をまとめて実行する（スキーマの作成など）
     */
    public function executeScript(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * 通知サーバーが投稿期間開始の通知を購読する
     */
    public function listenForPostingWindowNotifications(): void
    {
        $this->pdo->exec('LISTEN posting_window_opened');
    }

    /**
     * LISTEN 済み接続に届いた通知を読み取る。payload は利用せず、通知があったかだけ返す。
     */
    public function consumePostingWindowNotifications(): bool
    {
        $received = false;
        // 通知が集中しても1ループを占有しないよう、1回の呼び出しで読む数を制限する。
        for ($i = 0; $i < 256; $i++) {
            if ($this->pdo->getNotify(PDO::FETCH_ASSOC, 0) === false) {
                break;
            }
            $received = true;
        }
        return $received;
    }

    /**
     * $callback をトランザクションの中で実行する。例外が出たらロールバックする
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * IN 句のプレースホルダと値を作る。
     * 例: inClause('id', ['a', 'b']) は [':id0, :id1', ['id0' => 'a', 'id1' => 'b']]
     *
     * @param list<string> $values
     * @return array{string, array<string, string>}
     */
    public static function inClause(string $prefix, array $values): array
    {
        $params = [];
        foreach ($values as $i => $value) {
            $params["{$prefix}{$i}"] = $value;
        }
        $placeholders = implode(', ', array_map(static fn (string $name): string => ":{$name}", array_keys($params)));
        return [$placeholders, $params];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue($name, ...match (true) {
                $value instanceof Bytea => [$value->data, PDO::PARAM_LOB],
                $value === null => [null, PDO::PARAM_NULL],
                is_int($value) => [$value, PDO::PARAM_INT],
                is_bool($value) => [$value, PDO::PARAM_BOOL],
                default => [(string) $value, PDO::PARAM_STR],
            });
        }
        $statement->execute();
        return $statement;
    }
}
