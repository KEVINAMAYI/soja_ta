<?php

namespace App\Utils;

class ApiConstants
{
    public const SUCCESS_CODE = 1000;
    public const UNAUTHORIZED_CODE = 1003;
    public const NOT_FOUND_CODE = 1004;
    public const FORBIDDEN_CODE = 1005;


    // User types
    public const USER_TYPE_USER = 'USER';
    public const USER_TYPE_SYSTEM = 'SYSTEM';

    // USER ACTION TYPES
    public const USER_ACTION_LOGIN = 'LOGIN';
    public const USER_ACTION_LOGOUT = 'LOGOUT';
    public const USER_MALICIOUS_ACTIVITY = 'MALICIOUS_ACTIVITY';
    public const USER_ACTION_IMPERSONATION_REQUESTED = 'IMPERSONATION_REQUESTED';
    public const USER_ACTION_IMPERSONATION_START = 'IMPERSONATION_START';
    public const USER_ACTION_IMPERSONATION_END = 'IMPERSONATION_END';

    // System action types
    public const SYSTEM_AUTO_REPORT_GENERATION = 'AUTO_REPORT_GENERATION';
}