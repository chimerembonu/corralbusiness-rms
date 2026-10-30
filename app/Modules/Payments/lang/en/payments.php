<?php

return [
    'method' => ['cash' => 'Cash', 'momo' => 'Mobile Money', 'card' => 'Card', 'account' => 'On account'],
    'errors' => [
        'amount_required' => 'Enter the amount received.',
        'over_balance' => 'That is more than the balance due.',
        'order_not_payable' => 'This order cannot be paid (it is cancelled or already paid).',
        'nothing_to_pay' => 'Add something to the order before taking payment.',
    ],
];
