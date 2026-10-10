<?php

// Words of the voter file template (docs/api/voter-imports/GET-elections-{election}-voter-imports-template.md).
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
];
