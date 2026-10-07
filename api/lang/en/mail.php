<?php

return [
    'greeting' => 'Hello :name,',
    'link_fallback' => 'If the button does not work, copy this link into your browser:',
    'footer' => 'This message was sent automatically, please do not reply to it.',

    'verify' => [
        'subject' => 'Verify your email address',
        'intro' => 'Thank you for signing up. Confirm your email address to finish creating your account.',
        'button' => 'Verify my email',
        'validity' => 'This link is valid for 24 hours.',
        'ignore' => 'If you did not create an account, ignore this message.',
    ],

    'reset' => [
        'subject' => 'Reset your password',
        'intro' => 'We received a request to reset the password of your account.',
        'button' => 'Choose a new password',
        'validity' => 'This link is valid for 60 minutes and can be used once.',
        'ignore' => 'If you did not make this request, ignore this message: your password stays the same.',
    ],
];
