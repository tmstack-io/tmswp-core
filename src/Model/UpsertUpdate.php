<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

/**
 * upsert(Connection::upsert()・Model::upsert())で、キーが重複したときに列をどう更新するか。
 */
enum UpsertUpdate
{
    /** 列 = 挿入しようとした値 */
    case Replace;

    /** 列 = 列 + 挿入しようとした値 */
    case Increment;
}
