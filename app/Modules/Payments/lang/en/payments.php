<?php

return [
    'method' => ['cash' => 'Cash', 'momo' => 'Mobile Money', 'card' => 'Card', 'account' => 'On account'],
    'provider' => ['mtn' => 'MTN MoMo', 'telecel' => 'Telecel Cash', 'at' => 'AT Money'],
    'status' => ['completed' => 'Completed', 'refunded' => 'Refunded'],
    'errors' => [
        'reference_required' => 'Enter the transaction reference.',
        'provider_required' => 'Choose the Mobile Money network.',
        'already_refunded' => 'This payment was already refunded.',
        'refund_needs_manager' => 'Refunds need a manager PIN.',
        'amount_required' => 'Enter the amount received.',
        'over_balance' => 'That is more than the balance due.',
        'order_not_payable' => 'This order cannot be paid (it is cancelled or already paid).',
        'nothing_to_pay' => 'Add something to the order before taking payment.',
    ],
];
