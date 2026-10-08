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
];
