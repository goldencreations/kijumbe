<?php

return [
    'contribution_reminder' => [
        'name' => 'Contribution reminder',
        'description' => 'Sent once per day to members who still have an outstanding contribution.',
        'body' => <<<'TEXT'
Ukumbusho wa mchango wa {group_name}.
Unadaiwa TZS {contribution_due}{penalty_notice}
{deadline_notice}
Tuma LIPA, chagua mchezo huu, thibitisha kiasi, kisha tuma namba ya simu ya kulipia.
TEXT,
        'variables' => ['group_name', 'contribution_due', 'penalty_notice', 'deadline_notice'],
    ],
    'payment_debt_summary' => [
        'name' => 'Payment debt summary',
        'description' => 'Shown after a member chooses a group in the LIPA flow.',
        'body' => <<<'TEXT'
Deni la {group_name} kwa mizunguko:
{debt_breakdown}
Jumla ya deni: TZS {total_debt}
Kiasi utakacholipa kitatumika kwanza kwenye mzunguko wa zamani zaidi; kikizidi, salio litatumika kwenye mizunguko inayofuata.
TEXT,
        'variables' => ['group_name', 'debt_breakdown', 'total_debt'],
    ],
    'payout_completed' => [
        'name' => 'Payout completed',
        'description' => 'Sent to the other group members after a recipient payout succeeds.',
        'body' => <<<'TEXT'
MAREJESHO YAMELIPWA
Mchezo: {group_name}
Mpokeaji: {recipient_name}
Jumla ya mchezo: TZS {gross_amount}
Ada ya Kijumbe: TZS {kijumbe_fee_amount}
Kiasi alichopokea: TZS {amount}
Mzunguko: {round_number}
Muda: {paid_at} EAT

Tuma TAARIFA kuona hali ya mchezo wako.
TEXT,
        'variables' => ['group_name', 'recipient_name', 'gross_amount', 'kijumbe_fee_amount', 'amount', 'round_number', 'paid_at'],
    ],
];
