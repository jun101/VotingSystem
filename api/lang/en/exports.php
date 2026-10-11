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
];
