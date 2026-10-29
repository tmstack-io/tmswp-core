<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

/** UserQuery::search() で検索する列(WP_User_Query の search_columns が受け付ける列) */
enum UserSearchColumn: string
{
    case Id = 'ID';
    case UserLogin = 'user_login';
    case UserEmail = 'user_email';
    case UserUrl = 'user_url';
    case UserNicename = 'user_nicename';
    case DisplayName = 'display_name';
}
