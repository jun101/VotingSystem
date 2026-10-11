<?php

// Words of the voters export (docs/api/turnout/GET-elections-{election}-voters-export.md).
return [
    'voters' => [
        'sheet' => 'Électeurs',
        'columns' => ['Nom complet', 'Groupe', 'Identifiant', 'Courriel', 'Téléphone'],
        'has_voted' => 'A voté',
        'yes' => 'Oui',
        'no' => 'Non',
    ],
    // The audit log (docs/api/audit/GET-audit-export.md).
    'audit' => [
        'sheet' => 'Journal',
        'columns' => ['Date (UTC)', 'Action', 'Personne', 'Élection', 'Objet', 'Détails', 'Adresse IP'],
    ],
];
