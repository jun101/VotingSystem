<?php

return [
    'greeting' => 'Bonjour,',
    'link_fallback' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
    'footer' => 'Ce message a été envoyé automatiquement, merci de ne pas y répondre.',

    'verify' => [
        'subject' => 'Vérifiez votre adresse courriel',
        'title' => 'Vérifiez votre adresse courriel',
        'line' => 'Un dernier pas avant de commencer.',
        'preheader' => 'Confirmez votre adresse courriel pour terminer la création de votre compte.',
        'intro' => 'Merci de vous être inscrit. Confirmez votre adresse courriel pour terminer la création de votre compte.',
        'button' => 'Vérifier mon courriel',
        'validity' => 'Ce lien est valable 24 heures.',
        'ignore' => 'Si vous n’avez pas créé de compte, ignorez ce message.',
    ],

    'reset' => [
        'subject' => 'Réinitialisez votre mot de passe',
        'title' => 'Réinitialisez votre mot de passe',
        'line' => 'Choisissez un nouveau mot de passe en toute sécurité.',
        'preheader' => 'Choisissez un nouveau mot de passe pour retrouver l’accès à votre compte.',
        'intro' => 'Nous avons reçu une demande de réinitialisation du mot de passe de votre compte.',
        'button' => 'Choisir un nouveau mot de passe',
        'validity' => 'Ce lien est valable 60 minutes et ne peut servir qu’une fois.',
        'ignore' => 'Si vous n’avez pas fait cette demande, ignorez ce message : votre mot de passe ne change pas.',
    ],

    'invite' => [
        'subject' => 'Invitation à rejoindre un établissement',
        'title' => 'Invitation à rejoindre un établissement',
        'line' => 'Rejoignez votre établissement en quelques instants.',
        'preheader' => 'Choisissez votre nom et votre mot de passe pour rejoindre un établissement.',
        'intro' => 'Vous êtes invité à rejoindre l’établissement « :institution » en tant que :role. Choisissez votre nom et votre mot de passe pour commencer.',
        'button' => 'Accepter l’invitation',
        'validity' => 'Ce lien est valable 7 jours et ne peut servir qu’une fois.',
        'ignore' => 'Si vous ne vous attendiez pas à cette invitation, ignorez ce message : rien ne sera créé.',
        'roles' => [
            'owner' => 'propriétaire',
            'manager' => 'gestionnaire',
        ],
    ],

    'credentials' => [
        'subject' => 'Votre code d’accès pour voter',
        'title' => 'Votre code d’accès pour voter',
        'line' => 'Votre lien personnel et votre code sont ci-dessous.',
        'preheader' => 'Votre code et votre lien personnel pour voter à l’élection « :election ».',
        'intro' => ':name, vous êtes invité(e) à voter à l’élection « :election » de « :institution ». Utilisez le bouton ci-dessous ou saisissez votre code sur la page de vote.',
        'code_label' => 'Votre code d’accès',
        'election' => 'Élection',
        'institution' => 'Établissement',
        'period_label' => 'Période de vote',
        'period' => 'Du :from au :to (:zone)',
        'button' => 'Voter maintenant',
        'validity' => 'Vous ne pouvez voter qu’une fois, pendant la période indiquée.',
        'ignore' => 'Gardez ce message pour vous : toute personne qui a votre code peut voter à votre place. Si vous ne l’attendiez pas, ignorez-le.',
    ],

    'suspended' => [
        'subject' => 'Votre établissement a été suspendu',
        'title' => 'Votre établissement a été suspendu',
        'line' => 'La connexion et le vote sont suspendus.',
        'preheader' => 'Votre établissement a été suspendu par la plateforme.',
        'intro' => 'Votre établissement a été suspendu par la plateforme. Personne ne peut se connecter et ses élections ouvertes sont en pause jusqu’à la réactivation ; rien n’a été supprimé.',
        'reason' => 'Motif indiqué',
        'button' => 'Aller à la page de connexion',
        'validity' => 'Les élections reprennent à la levée de la suspension ; leur heure de fin ne change pas.',
        'ignore' => 'Si vous pensez qu’il s’agit d’une erreur, contactez l’opérateur de la plateforme.',
    ],

    'reactivated' => [
        'subject' => 'Votre établissement a été réactivé',
        'title' => 'Votre établissement a été réactivé',
        'line' => 'Vous pouvez de nouveau vous connecter.',
        'preheader' => 'La suspension de votre établissement a été levée.',
        'intro' => 'La suspension de votre établissement a été levée. Votre équipe peut de nouveau se connecter et les élections ouvertes reprennent les votes.',
        'button' => 'Aller à la page de connexion',
        'validity' => 'L’heure de fin d’une élection n’a pas bougé pendant la pause : vérifiez-la.',
        'ignore' => 'Pour toute question, contactez l’opérateur de la plateforme.',
    ],
];
