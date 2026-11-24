<?php

return [
    'fields' => [
        'office' => ['Province', 'select', true, 'province'],
        'reference' => ['PO number', 'text', true, 'P\.?\h*O\.?\h*(?:number|no\.?|#)|purchase order\h*(?:number|no\.?|#)'],
        'caterer' => ['Caterer', 'text', true, 'caterer|supplier(?: name)?'],
        'po_amount' => ['PO Amount (₱)', 'number', true, '(?:total\h+)?P\.?\h*O\.?\h*amount|purchase order amount'],
        'activity' => ['Activity', 'textarea', true, '(?:title of |name of )?activity(?!\h+date\b)(?: title)?'],
        'end_user' => ['End User', 'text', false, 'end[ -]user'],
        'procurement_mode' => ['Mode of Procurement', 'text', false, 'mode of procurement|procurement mode'],
        'delivery_term' => ['Delivery term', 'text', false, 'delivery terms?'],
        'date_conformed' => ['Date conformed', 'date', false, 'date conformed|date of conformity'],
        'activity_date' => ['Date of activity based on PO', 'date', false, 'date of activity(?: based on P\.?\h*O\.?)?|activity date'],
        'fund_source' => ['Fund Source', 'text', false, 'fund source|source of funds?'],
        'ors_number' => ['ORS number', 'text', false, 'O\.?\h*R\.?\h*S\.?\h*(?:number|no\.?|#)|obligation request(?: and status)?\h*(?:number|no\.?|#)'],
    ],
];
