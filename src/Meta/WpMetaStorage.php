<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;

/**
 * MetaStorageInterface の WP 標準の実装。
 *
 * 公開の読み取り(has・get・getAll・getMultiple)を単独で呼んだときのデータベースのエラーは、エラー方針に従い、
 * $kernel の debug では例外、本番では $kernel のロガーに記録して「行が無い」と同じ結果を返す(WpDatabaseGuard)。
 * Container から解決したインスタンスは、その Container の Kernel を受け取る。
 */
final class WpMetaStorage implements MetaStorageInterface
{
    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    /**
     * 行の有無を、get_metadata_raw() の全行の読み込みが行を返したか(rows())で判定する。metadata_exists() は
     * get_{$meta_type}_metadata フィルタの結果を (bool) にするため、プレビューのフィルタ(#[WithMetaRevisions] の
     * _wp_preview_meta_filter)が '0'・'' を返すと、行があっても false になる。
     */
    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return $this->readAlone(static fn(): bool => self::rows($type, $objectId, $key) !== null, false, $type, $objectId, $key);
    }

    /** 先頭の行を返す。行が無ければ null(行の有無と行の扱いは has() と同じ。rows())。 */
    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        $rows = $this->readAlone(static fn(): ?array => self::rows($type, $objectId, $key), null, $type, $objectId, $key);

        return $rows === null ? null : $rows[0];
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        return $this->readAlone(static fn(): array => self::allRows($type, $objectId, $key), [], $type, $objectId, $key);
    }

    /**
     * キーごとに get_metadata_raw() で読む(get_{$meta_type}_metadata フィルタがキーごとに効き、プレビューの値も返す)。
     * 最初の読み込みが対象のメタをすべてメタのキャッシュに載せるため、データベースへの問い合わせは1回のまま。
     * データベースのエラーの判定もキーごとに行う(次のクエリが last_error を空にするため)。本番でエラーになったキーは
     * 結果に含めない。
     */
    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $rows = $this->readAlone(static fn(): ?array => self::rows($type, $objectId, $key), null, $type, $objectId, $key);

            if ($rows !== null) {
                $values[$key] = $rows[0];
            }
        }

        return $values;
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        self::assertStorable($key, $value);
        $metaType = $type->metaType();

        // update_metadata() はキーと値を wp_unslash() するため、slash して渡して元の値を保存させる。
        // false(同じ値・フィルタによる中止)とデータベースのエラーの区別は WpDatabaseGuard に従う
        return WpDatabaseGuard::write(
            static fn(): mixed => update_metadata($metaType, $objectId, wp_slash($key), self::slash($value)),
            "failed to update {$metaType} meta '{$key}' for object {$objectId}",
        );
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        foreach ($values as $value) {
            self::assertStorable($key, $value);
        }

        $metaType = $type->metaType();
        $before = self::storedForms(WpDatabaseGuard::forgettingFailedMetaLoads($type, static fn(callable $forgetCache): array => WpDatabaseGuard::read(
            static fn(): array => self::allRows($type, $objectId, $key),
            "failed to read {$metaType} meta '{$key}' for object {$objectId} before replacing it",
            $forgetCache,
        )));

        // データベースのエラーは途中でも例外にする。wpdb にトランザクションは無いため巻き戻さない。
        // フィルタが削除を止めた場合は、行を足すと置き換えにならないため、何も変えずに終える
        if ($before !== [] && !WpDatabaseGuard::write(
            static fn(): mixed => delete_metadata($metaType, $objectId, wp_slash($key)),
            "failed to delete {$metaType} meta '{$key}' for object {$objectId} before replacing it",
        )) {
            return false;
        }

        foreach ($values as $value) {
            WpDatabaseGuard::write(
                static fn(): mixed => add_metadata($metaType, $objectId, wp_slash($key), self::slash($value)),
                "failed to add {$metaType} meta '{$key}' for object {$objectId}",
            );
        }

        $after = WpDatabaseGuard::forgettingFailedMetaLoads($type, static fn(callable $forgetCache): array => WpDatabaseGuard::read(
            static fn(): array => self::allRows($type, $objectId, $key),
            "failed to read {$metaType} meta '{$key}' for object {$objectId} after replacing it",
            $forgetCache,
        ));

        return self::storedForms($after) !== $before;
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $metaType = $type->metaType();
        $exists = WpDatabaseGuard::forgettingFailedMetaLoads($type, static fn(callable $forgetCache): bool => WpDatabaseGuard::read(
            static fn(): bool => self::rows($type, $objectId, $key) !== null,
            "failed to read {$metaType} meta '{$key}' for object {$objectId} before deleting it",
            $forgetCache,
        ));

        if (!$exists) {
            return false;
        }

        return WpDatabaseGuard::write(
            static fn(): mixed => delete_metadata($metaType, $objectId, wp_slash($key)),
            "failed to delete {$metaType} meta '{$key}' for object {$objectId}",
        );
    }

    /**
     * null と object を拒否する。WP のメタは null を保存できず(空文字になる)、object は wp_slash() の対象外のまま
     * update_metadata() の wp_unslash() でプロパティの文字列が書き換えられるため、元の値として往復しない。
     */
    private static function assertStorable(string $key, mixed $value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException("meta '{$key}' cannot store null; use delete() to remove it");
        }

        self::assertNoObject($key, $value);
    }

    private static function assertNoObject(string $key, mixed $value): void
    {
        if (\is_object($value)) {
            throw new InvalidArgumentException("meta '{$key}' cannot store an object (" . $value::class . ')');
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertNoObject($key, $item);
            }
        }
    }

    /**
     * wp_insert_post()・wp_insert_user() の meta_input に渡す配列を作る。WordPress は meta_input のキーと値を
     * update_post_meta()・add_user_meta() で保存し、そこで wp_unslash() するため、set() と同じ規則で値を検査し、
     * キーと値を slash して返す。
     *
     * @internal エンティティの create() が使う
     *
     * @param array<string, mixed> $metaInput
     *
     * @throws InvalidArgumentException null、または object を含む値がある場合
     *
     * @return array<string, mixed>
     */
    public static function slashMetaInput(array $metaInput): array
    {
        $slashed = [];

        foreach ($metaInput as $key => $value) {
            self::assertStorable($key, $value);
            $slashed[wp_slash($key)] = self::slash($value);
        }

        return $slashed;
    }

    /**
     * 公開の読み取りを単独で呼ぶ。データベースのエラーの扱いは WpDatabaseGuard::readOrDefault() に従う。
     *
     * @template T
     *
     * @param callable(): T $read
     * @param T $default 本番でデータベースのエラーが起きたときに返す値
     *
     * @return T
     */
    private function readAlone(callable $read, mixed $default, ObjectType $type, int $objectId, string $key): mixed
    {
        $kernel = $this->kernel;

        return WpDatabaseGuard::forgettingFailedMetaLoads($type, static fn(callable $forgetCache): mixed => WpDatabaseGuard::readOrDefault(
            $kernel,
            $read,
            $default,
            "failed to read {$type->metaType()} meta '{$key}' for object {$objectId}",
            $forgetCache,
        ));
    }

    /** @return list<mixed> 全行(maybe_unserialize 済み)。行が無ければ空の配列 */
    private static function allRows(ObjectType $type, int $objectId, string $key): array
    {
        $values = get_metadata_raw($type->metaType(), $objectId, $key, false);

        if (!\is_array($values)) {
            return [];
        }

        return array_values($values);
    }

    /**
     * 全行(maybe_unserialize 済み)を返す。行が無ければ null。
     *
     * get_metadata_raw(..., false) は、行が無ければ null、対象の ID が不正なら false、行があれば行の配列を返す。
     * get_{$meta_type}_metadata フィルタが null 以外を返した場合はその値をそのまま返す。そのため、null・false・空の配列と
     * スカラーの空文字は行なし、空でない配列は各行('' や '0' の行も含む)、それ以外の値は1行として扱う。false は
     * metadata_exists() と同じ扱い。WordPress のサムネイルのプレビューのフィルタ(_wp_preview_post_thumbnail_filter)は、
     * single が false でもサムネイルの ID を文字列で返し、アイキャッチを外すプレビューでは '' を返す(WP 7.0 の
     * wp-includes/revision.php)。WordPress もこの '' を行なしと扱う(metadata_exists()・has_post_thumbnail() が false)。
     *
     * @return non-empty-list<mixed>|null
     */
    private static function rows(ObjectType $type, int $objectId, string $key): ?array
    {
        $rows = get_metadata_raw($type->metaType(), $objectId, $key, false);

        if ($rows === null || $rows === false || $rows === '' || $rows === []) {
            return null;
        }

        return \is_array($rows) ? array_values($rows) : [$rows];
    }

    private static function slash(mixed $value): mixed
    {
        return \is_string($value) || \is_array($value) ? wp_slash($value) : $value;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function storedForms(array $values): array
    {
        return array_map(self::storedForm(...), $values);
    }

    /** DB の meta_value 列に書かれる文字列の形(配列は serialize、真偽は '1' と '')。 */
    private static function storedForm(mixed $value): string
    {
        return ValueCaster::toString(maybe_serialize($value));
    }
}
