<?php

return [
    'greeting' => 'Hello,',
    'link_fallback' => 'If the button does not work, copy this link into your browser:',
    'footer' => 'This message was sent automatically, please do not reply to it.',

    'verify' => [
        'subject' => 'Verify your email address',
        'title' => 'Verify your email address',
        'line' => 'One last step before you start.',
        'preheader' => 'Confirm your email address to finish creating your account.',
        'intro' => 'Thank you for signing up. Confirm your email address to finish creating your account.',
        'button' => 'Verify my email',
        'validity' => 'This link is valid for 24 hours.',
        'ignore' => 'If you did not create an account, ignore this message.',
    ],

    'reset' => [
        'subject' => 'Reset your password',
        'title' => 'Reset your password',
        'line' => 'Choose a new password safely.',
        'preheader' => 'Choose a new password to get back into your account.',
        'intro' => 'We received a request to reset the password of your account.',
        'button' => 'Choose a new password',
        'validity' => 'This link is valid for 60 minutes and can be used once.',
        'ignore' => 'If you did not make this request, ignore this message: your password stays the same.',
    ],

    'invite' => [
        'subject' => 'Invitation to join an institution',
        'title' => 'Invitation to join an institution',
        'line' => 'Join your institution in a few moments.',
        'preheader' => 'Choose your name and a password to join an institution.',
        'intro' => 'You are invited to join the institution “:institution” as :role. Choose your name and a password to get started.',
        'button' => 'Accept the invitation',
        'validity' => 'This link is valid for 7 days and can be used once.',
        'ignore' => 'If you did not expect this invitation, ignore this message: nothing will be created.',
        'roles' => [
            'owner' => 'an owner',
            'manager' => 'a manager',
        ],
    ],
];
