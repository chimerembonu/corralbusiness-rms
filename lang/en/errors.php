<?php

// What staff see when something is refused or goes wrong. Plain words, always with a way back.
return [
    'pages' => [
        '403' => ['title' => 'You do not have access to this', 'body' => 'Your role does not include this screen. Ask a manager if you need it.'],
        '404' => ['title' => 'This page is not here', 'body' => 'It may have been closed or removed, or the address is wrong.'],
        '419' => ['title' => 'You were signed out', 'body' => 'The till was left for a long time, so you were signed out to keep it safe. Sign in again to carry on.'],
        '429' => ['title' => 'Too many tries', 'body' => 'Wait a minute, then try again.'],
        '500' => ['title' => 'Something went wrong', 'body' => 'Nothing you did caused this. Try again; if it keeps happening, tell a manager.'],
        '503' => ['title' => 'The system is being updated', 'body' => 'This takes a minute or two. The page will be back shortly.'],
    ],
    'actions' => [
        'till' => 'Back to the till',
        'back_office' => 'Back to the back office',
        'sign_in' => 'Go to sign-in',
        'sign_in_again' => 'Sign in again',
        'switch_user' => 'Sign in as someone else',
        'retry' => 'Try again',
    ],
    'action' => [
        'forbidden' => 'You do not have access to that. Ask a manager if you need it.',
        'gone' => 'That is no longer here: it may have just been changed on another till. The screen has been refreshed.',
        'failed' => 'That did not work. Try again; if it keeps happening, tell a manager.',
        'amount' => 'Type an amount as a number, for example 10 or 12.50.',
    ],
];
