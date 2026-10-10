<?php

// Words of the voter file template and of the error file (docs/api/voter-imports/GET-elections-{election}-voter-imports-template.md).
return [
    'template' => [
        'sheet' => 'Voters',
        'instructions_sheet' => 'Instructions',
        'column' => 'Column',
        'description' => 'Description',
        'columns' => [
            'nom_complet' => 'Required. The voter’s full name, at most 150 characters.',
            'groupe' => 'Optional. The voter’s class or group, at most 100 characters. A new group is created at import.',
            'identifiant' => 'Optional. A student number or any number, unique in the election, at most 50 characters.',
            'courriel' => 'Optional. A valid email address, unique in the election.',
            'telephone' => 'Optional. Digits, spaces and the signs + - ( ) . only, at most 30 characters.',
        ],
        'notes' => 'An .xlsx or .csv file, 5 MB and 10,000 rows at most. Keep the first row as it is and delete the example rows.',
    ],
    'reasons' => [
        'full_name_required' => 'The full name is required.',
        'full_name_max' => 'The full name is longer than 150 characters.',
        'group_max' => 'The group is longer than 100 characters.',
        'identifier_max' => 'The identifier is longer than 50 characters.',
        'email_invalid' => 'The email is not a valid address.',
        'email_max' => 'The email is longer than 255 characters.',
        'phone_invalid' => 'The phone holds characters that are not allowed.',
        'phone_max' => 'The phone is longer than 30 characters.',
        'group_limit_reached' => 'The election already has 100 groups: this group cannot be created.',
        'duplicate_identifier_in_file' => 'The identifier is already used higher in the file.',
        'duplicate_email_in_file' => 'The email is already used higher in the file.',
        'identifier_exists' => 'A voter of the election already has this identifier.',
        'email_exists' => 'A voter of the election already has this email.',
        'unknown' => 'The row is not valid.',
    ],
];
