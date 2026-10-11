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

    'credentials' => [
        'subject' => 'Your access code to vote',
        'title' => 'Your access code to vote',
        'line' => 'Your personal link and code are below.',
        'preheader' => 'Your code and personal link to vote in the election “:election”.',
        'intro' => ':name, you are invited to vote in the election “:election” of “:institution”. Use the button below, or enter your code on the voting page.',
        'code_label' => 'Your access code',
        'election' => 'Election',
        'institution' => 'Institution',
        'period_label' => 'Voting period',
        'period' => 'From :from to :to (:zone)',
        'button' => 'Vote now',
        'validity' => 'You can vote only once, during the period shown.',
        'ignore' => 'Keep this message to yourself: anyone who has your code can vote in your place. If you did not expect it, ignore it.',
    ],
];
