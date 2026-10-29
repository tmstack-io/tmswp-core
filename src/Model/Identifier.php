<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\SchemaException;

final class Identifier
{
    /**
     * スキーマの識別子(カラム名・インデックス名)に使えない予約語(小文字)。TableDefinitionはクォートせずに
     * CREATE TABLEを生成するため(dbDeltaの要件)、予約語は宣言の時点で拒否する。MySQL(8.0・8.4 LTS・9.7 LTS)の
     * information_schema.KEYWORDSでRESERVED = 1の語と、MariaDB(10.11・12.3)でクォートせずにカラム名に
     * 使えない語の和集合。
     */
    private const array RESERVED_WORDS = [
        'accessible', 'add', 'all', 'alter', 'analyze', 'and', 'as', 'asc', 'asensitive', 'before', 'between',
        'bigint', 'binary', 'blob', 'both', 'by', 'call', 'cascade', 'case', 'change', 'char', 'character', 'check',
        'collate', 'column', 'condition', 'constraint', 'continue', 'conversion', 'convert', 'create', 'cross',
        'cube', 'cume_dist', 'current_date', 'current_path', 'current_role', 'current_time', 'current_timestamp',
        'current_user', 'cursor', 'database', 'databases', 'day_hour', 'day_microsecond', 'day_minute', 'day_second',
        'dec', 'decimal', 'declare', 'default', 'delayed', 'delete', 'delete_domain_id', 'dense_rank', 'desc',
        'describe', 'deterministic', 'distinct', 'distinctrow', 'div', 'do_domain_ids', 'double', 'drop', 'dual',
        'each', 'else', 'elseif', 'empty', 'enclosed', 'escaped', 'except', 'exists', 'exit', 'explain', 'external', 'false',
        'fetch', 'first_value', 'float', 'float4', 'float8', 'for', 'force', 'foreign', 'from', 'fulltext',
        'function', 'generated', 'get', 'grant', 'group', 'grouping', 'groups', 'having', 'high_priority',
        'hour_microsecond', 'hour_minute', 'hour_second', 'if', 'ignore', 'ignore_domain_ids', 'in', 'index',
        'infile', 'inner', 'inout', 'insensitive', 'insert', 'int', 'int1', 'int2', 'int3', 'int4', 'int8',
        'integer', 'intersect', 'interval', 'into', 'io_after_gtids', 'io_before_gtids', 'is', 'iterate', 'join',
        'json_table', 'key', 'keys', 'kill', 'lag', 'last_value', 'lateral', 'lead', 'leading', 'leave', 'left',
        'library', 'like', 'limit', 'linear', 'lines', 'load', 'localtime', 'localtimestamp', 'lock', 'long', 'longblob',
        'longtext', 'loop', 'low_priority', 'master_bind', 'master_demote_to_replica', 'master_demote_to_slave',
        'master_ssl_verify_server_cert', 'match', 'maxvalue', 'mediumblob', 'mediumint', 'mediumtext', 'middleint',
        'minute_microsecond', 'minute_second', 'mod', 'modifies', 'natural', 'no_write_to_binlog', 'not',
        'nth_value', 'ntile', 'null', 'numeric', 'of', 'offset', 'on', 'optimize', 'optimizer_costs', 'option',
        'optionally', 'or', 'order', 'out', 'outer', 'outfile', 'over', 'page_checksum', 'parse_vcol_expr',
        'partition', 'percent_rank', 'portion', 'precision', 'primary', 'procedure', 'purge', 'qualify', 'range', 'rank',
        'read', 'read_write', 'reads', 'real', 'recursive', 'ref_system_id', 'references', 'regexp', 'release',
        'rename', 'repeat', 'replace', 'require', 'resignal', 'restrict', 'return', 'returning', 'revoke', 'right',
        'rlike', 'row', 'row_number', 'rows', 'schema', 'schemas', 'second_microsecond', 'select', 'sensitive',
        'separator', 'set', 'show', 'signal', 'smallint', 'spatial', 'specific', 'sql', 'sql_after_gtids',
        'sql_before_gtids', 'sql_big_result', 'sql_calc_found_rows', 'sql_small_result', 'sqlexception', 'sqlstate',
        'sqlwarning', 'ssl', 'starting', 'stats_auto_recalc', 'stats_persistent', 'stats_sample_pages', 'stored',
        'straight_join', 'system', 'table', 'tablesample', 'terminated', 'then', 'tinyblob', 'tinyint', 'tinytext', 'to', 'to_date',
        'trailing', 'trigger', 'true', 'undo', 'union', 'unique', 'unlock', 'unsigned', 'update', 'usage', 'use',
        'using', 'utc_date', 'utc_time', 'utc_timestamp', 'values', 'varbinary', 'varchar', 'varcharacter',
        'varying', 'vector', 'virtual', 'when', 'where', 'while', 'window', 'with', 'write', 'xor', 'year_month',
        'zerofill',
    ];

    private function __construct() {}

    public static function simple(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidQueryException("invalid SQL identifier: {$value}");
        }

        return $value;
    }

    public static function qualified(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $value) !== 1) {
            throw new InvalidQueryException("invalid SQL identifier: {$value}");
        }

        return $value;
    }

    public static function selectable(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?(\s+(?i:AS)\s+[A-Za-z_][A-Za-z0-9_]*)?$/', $value) !== 1) {
            throw new InvalidQueryException("invalid SELECT identifier: {$value}");
        }

        return $value;
    }

    public static function bareTable(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new InvalidQueryException("invalid bare table name: {$value}");
        }

        return $value;
    }

    public static function table(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidQueryException("invalid table name: {$value}");
        }

        return $value;
    }

    public static function schemaSimple(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new SchemaException("invalid schema identifier: {$value}");
        }

        if (\in_array(strtolower($value), self::RESERVED_WORDS, true)) {
            throw new SchemaException("reserved word cannot be used as schema identifier: {$value}");
        }

        return $value;
    }

    public static function schemaBareTable(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new SchemaException("invalid table name: {$value}");
        }

        return $value;
    }
}
