<?php

// Words of the voter file template and of the error file (docs/api/voter-imports/GET-elections-{election}-voter-imports-template.md).
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
    'reasons' => [
        'full_name_required' => 'Le nom complet est obligatoire.',
        'full_name_max' => 'Le nom complet dépasse 150 caractères.',
        'group_max' => 'Le groupe dépasse 100 caractères.',
        'identifier_max' => 'L’identifiant dépasse 50 caractères.',
        'email_invalid' => 'Le courriel n’est pas une adresse valide.',
        'email_max' => 'Le courriel dépasse 255 caractères.',
        'phone_invalid' => 'Le téléphone contient des caractères non permis.',
        'phone_max' => 'Le téléphone dépasse 30 caractères.',
        'group_limit_reached' => 'L’élection a déjà 100 groupes : ce groupe ne peut pas être créé.',
        'duplicate_identifier_in_file' => 'L’identifiant est déjà utilisé plus haut dans le fichier.',
        'duplicate_email_in_file' => 'Le courriel est déjà utilisé plus haut dans le fichier.',
        'identifier_exists' => 'Un électeur de l’élection a déjà cet identifiant.',
        'email_exists' => 'Un électeur de l’élection a déjà ce courriel.',
        'unknown' => 'La ligne n’est pas valide.',
    ],
];
