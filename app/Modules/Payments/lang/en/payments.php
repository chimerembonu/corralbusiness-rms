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
    'navigation_group' => 'Cash & day close',
    'cash' => [
        'session' => 'Cashier session',
        'sessions' => 'Cashier sessions',
        'no_till' => 'No till',
        'status' => ['open' => 'Open', 'closed' => 'Closed'],
        'movement' => ['pay_in' => 'Pay in', 'pay_out' => 'Pay out'],
        'fields' => [
            'number' => 'No.', 'date' => 'Date', 'till' => 'Till', 'cashier' => 'Cashier', 'status' => 'Status',
            'opened_at' => 'Opened', 'closed_at' => 'Closed', 'closed_by' => 'Closed by', 'approved_by' => 'Variance accepted by',
            'opening_float' => 'Opening float', 'cash_sales' => 'Cash sales', 'cash_tips' => 'Cash tips', 'cash_refunds' => 'Cash refunds',
            'pay_ins' => 'Pay-ins', 'pay_outs' => 'Pay-outs', 'expected' => 'Expected in drawer', 'counted' => 'Counted', 'variance' => 'Over / short',
            'notes' => 'Notes', 'movements' => 'Pay-ins and pay-outs', 'reason' => 'Reason', 'reference' => 'Reference', 'amount' => 'Amount', 'by' => 'By', 'time' => 'Time',
            'by_method' => 'Takings by payment method', 'count_sheet' => 'Count sheet',
        ],
        'errors' => [
            'float_invalid' => 'The float cannot be negative.',
            'count_invalid' => 'The counted amount cannot be negative.',
            'till_already_open' => ':till is already open. Cash it up before opening it again.',
            'already_open' => 'You already have an open till. Cash it up first.',
            'session_closed' => 'This till was already cashed up.',
            'not_enough_cash' => 'There is only :cash in the drawer.',
            'no_session' => 'Open the till (count the float) before taking payments.',
        ],
    ],
    'day' => [
        'close' => 'Day close (Z report)',
        'closes' => 'Day closes (Z reports)',
        'status' => ['closed' => 'Closed', 'reopened' => 'Reopened'],
        'x_report' => 'X report',
        'z_report' => 'Z report',
        'fields' => [
            'number' => 'Z no.', 'date' => 'Business day', 'status' => 'Status', 'closed_at' => 'Closed at', 'closed_by' => 'Closed by',
            'approved_by' => 'Approved by', 'reopened_at' => 'Reopened at', 'reopened_by' => 'Reopened by', 'reopen_reason' => 'Reopen reason',
            'gross_sales' => 'Gross sales', 'orders' => 'Orders', 'notes' => 'Notes',
        ],
        'blockers' => [
            'open_orders' => '{1} :count order is still open: pay or cancel it first.|[2,*] :count orders are still open: pay or cancel them first.',
            'open_tills' => 'These tills are still open: :tills. Cash them up first.',
        ],
        'errors' => [
            'closed' => 'The day :date is closed (Z report printed). A manager can reopen it from the Cash screen.',
            'not_closed' => 'This day is not closed.',
        ],
        'reopen' => 'Reopen day',
        'reopened' => 'The day was reopened.',
    ],
];
