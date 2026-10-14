<?php

// Words of the voters export (docs/api/turnout/GET-elections-{election}-voters-export.md).
return [
    'voters' => [
        'sheet' => 'Voters',
        'columns' => ['Full name', 'Group', 'Identifier', 'Email', 'Phone'],
        'has_voted' => 'Has voted',
        'yes' => 'Yes',
        'no' => 'No',
    ],
    // The audit log (docs/api/audit/GET-audit-export.md).
    'audit' => [
        'sheet' => 'Audit log',
        'columns' => ['When (UTC)', 'Action', 'Person', 'Election', 'Subject', 'Details', 'IP address'],
    ],
];
