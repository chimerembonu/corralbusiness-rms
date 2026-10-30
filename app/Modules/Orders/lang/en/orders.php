<?php

return [
    'type' => ['dine_in' => 'Dine-in', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery'],
    'status' => ['open' => 'Open', 'sent' => 'Sent to kitchen', 'paid' => 'Paid', 'void' => 'Cancelled'],
    'errors' => [
        'table_occupied' => 'Table :table already has an open order.',
        'not_orderable' => ':item is not available right now.',
        'choose_size' => 'Please choose a size for :item.',
        'modifier_sold_out' => ':name is sold out.',
        'order_closed' => 'This order is :status and can no longer be changed.',
        'line_sent' => 'This line was already sent to the kitchen. Ask a manager to void it.',
    ],
];
