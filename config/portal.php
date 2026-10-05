<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super administrators
    |--------------------------------------------------------------------------
    |
    | Emails that receive the `super_admin` role at each Microsoft sign-in
    | (and lose it when removed from this list). Comma-separated in the
    | environment; compared lowercase.
    |
    */

    'super_admin_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('PORTAL_SUPER_ADMIN_EMAILS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Portal administrators group
    |--------------------------------------------------------------------------
    |
    | Members of this Microsoft 365 group (in any company tenant) get the
    | `portal_admin` role at sign-in and lose it when they leave the group.
    |
    */

    'admin_group' => 'APP-PortalAdmin',

];
