<?php

// Words of the voter file template (docs/api/voter-imports/GET-elections-{election}-voter-imports-template.md).
return [
    'template' => [
        'sheet' => 'Électeurs',
        'instructions_sheet' => 'Instructions',
        'column' => 'Colonne',
        'description' => 'Description',
        'columns' => [
            'nom_complet' => 'Obligatoire. Le nom et le prénom de l’électeur, 150 caractères au plus.',
            'groupe' => 'Facultatif. La classe ou le groupe de l’électeur, 100 caractères au plus. Un groupe nouveau est créé à l’import.',
            'identifiant' => 'Facultatif. Un matricule ou un numéro, unique dans l’élection, 50 caractères au plus.',
            'courriel' => 'Facultatif. Une adresse de courriel valide, unique dans l’élection.',
            'telephone' => 'Facultatif. Chiffres, espaces et les signes + - ( ) . seulement, 30 caractères au plus.',
        ],
        'notes' => 'Fichier .xlsx ou .csv, 5 Mo et 10 000 lignes au plus. Gardez la première ligne telle quelle et supprimez les lignes d’exemple.',
    ],
];
