<?php

namespace App\Utils;

class ApiConstants
{
    public const SUCCESS_CODE = 1000;
    public const UNAUTHORIZED_CODE = 1003;
    public const FORBIDDEN_CODE = 1005;


    // User types
    public const USER_TYPE_USER = 'USER';
    public const USER_TYPE_SYSTEM = 'SYSTEM';

    // USER ACTION TYPES
    public const USER_ACTION_LOGIN = 'LOGIN';
    public const USER_ACTION_LOGOUT = 'LOGOUT';
    public const USER_MALICIOUS_ACTIVITY = 'MALICIOUS_ACTIVITY';

    // System action types
    public const SYSTEM_AUTO_REPORT_GENERATION = 'AUTO_REPORT_GENERATION';
}