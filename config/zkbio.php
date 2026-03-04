<?php

return [
    'base_url'     => env('ZKBIO_BASE_URL', 'http://51.20.165.179:8098'),
    'access_token' => env('ZKBIO_ACCESS_TOKEN', 'CE7AE4C4238651A06366DDA68ECDCF657330C0BF49BB09252CE6F0C0EE825532'),
    'device_sn'    => env('ZKBIO_DEVICE_SN', 'COPP232460043'),

    // Your org/dept defaults for auto-created employees
    'default_organization_id' => env('ZKBIO_DEFAULT_ORG_ID'),
    'default_department_id'   => env('ZKBIO_DEFAULT_DEPT_ID'),
    'default_shift_id'        => env('ZKBIO_DEFAULT_SHIFT_ID'),
];
