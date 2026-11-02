<?php

return [
    'navigation_group' => 'Reports',
    'other' => 'Other',
    'generated' => 'Generated :time',
    'filters' => ['from' => 'From', 'to' => 'To', 'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'last_week' => 'Last week', 'this_month' => 'This month', 'last_month' => 'Last month'],
    'export' => ['excel' => 'Export to Excel', 'pdf' => 'Export to PDF'],
    'empty' => 'No sales in this period.',
    'total' => 'TOTAL',
    'sales' => [
        'title' => 'Sales report',
        'summary' => 'Summary',
        'by_day' => 'Sales by day',
        'by_hour' => 'Sales by hour',
        'by_type' => 'Sales by order type',
        'by_payment' => 'Payments by method',
        'by_staff' => 'Sales by staff',
        'kpi' => [
            'gross_sales' => 'Gross sales', 'net_sales' => 'Net sales (before tax)', 'tax_total' => 'Tax', 'orders' => 'Paid orders', 'average_order' => 'Average order',
            'average_per_day' => 'Average per day', 'covers' => 'Guests', 'discount_total' => 'Discounts', 'service_charge_total' => 'Service charge', 'delivery_fee_total' => 'Delivery fees',
            'tip_total' => 'Tips', 'refund_total' => 'Refunds', 'orders_void' => 'Cancelled orders', 'void_value' => 'Cancelled value', 'orders_refunded' => 'Refunded orders', 'days' => 'Days',
        ],
        'columns' => [
            'date' => 'Date', 'hour' => 'Hour', 'orders' => 'Orders', 'gross' => 'Gross', 'net' => 'Net', 'tax' => 'Tax', 'discounts' => 'Discounts',
            'type' => 'Order type', 'average' => 'Average', 'method' => 'Method', 'count' => 'Count', 'total' => 'Total', 'tips' => 'Tips', 'refunded' => 'Refunded',
            'staff' => 'Staff', 'role' => 'Role', 'sales' => 'Sales', 'voids' => 'Cancelled', 'payments' => 'Payments', 'taken' => 'Taken',
        ],
    ],
    'items' => [
        'title' => 'Items & categories',
        'by_item' => 'Sales by item',
        'by_category' => 'Sales by category',
        'columns' => ['item' => 'Item', 'category' => 'Category', 'quantity' => 'Qty', 'gross' => 'Gross', 'net' => 'Net', 'share' => 'Share %'],
    ],
    'voids' => [
        'title' => 'Voids, discounts & refunds',
        'voided_orders' => 'Cancelled orders',
        'voided_lines' => 'Voided lines',
        'removed_lines' => 'Lines removed before sending',
        'discounts' => 'Discounts',
        'refunds' => 'Refunds',
        'columns' => ['when' => 'When', 'order' => 'Order', 'detail' => 'Detail', 'value' => 'Value', 'by' => 'By', 'opened_by' => 'Opened by'],
    ],
    'tax' => [
        'title' => 'Tax report',
        'components' => 'Tax by component',
        'summary' => 'Summary',
        'hint' => 'Taxable net is the sales (before tax) each component was charged on. Exempt sales had no tax (e.g. bottled water).',
        'columns' => ['component' => 'Component', 'rate' => 'Rate', 'taxable_net' => 'Taxable net', 'tax' => 'Tax collected'],
        'kpi' => ['taxable_net' => 'Taxable net sales', 'exempt_net' => 'Exempt sales', 'tax_total' => 'Total tax collected', 'gross' => 'Gross sales'],
    ],
    'dashboard' => [
        'today' => 'Today', 'yesterday' => 'yesterday', 'orders' => 'orders', 'last_14_days' => 'Sales, last 14 days', 'gross' => 'Gross sales',
    ],
];
