<?php

return [
    'main_menu' => [
        'category' => 'Command menu',
        'name' => 'Main menu',
        'description' => 'Shown when a member starts WhatsApp or returns to the command menu.',
        'body' => <<<'TEXT'
Karibu Kijumbe. Chagua huduma kwa kutuma moja ya maneno haya:

UNDA KIKUNDI - Unda mchezo mpya wa michango
BADILI TAARIFA - Badili mchezo unaoratibu
LIPA - Lipa mchango wa mchezo mmoja au zaidi
POKEA - Pokea marejesho yako siku yako ikifika
NAMBA YA SIRI - Weka au badili namba ya siri ya kupokea fedha
STATUS - Angalia mchango unaodaiwa
TAARIFA - Pata ripoti ya waliolipa na waliopokea kwenye michezo yako
KATISHA - Futa mchakato unaoendelea

Kwa kujiunga, kulipa, au kuona taarifa za mchezo, tuma msimbo wa mchezo.

Kwa kujiunga au kuona taarifa za mchezo, tuma msimbo wa mchezo uliopokea.
TEXT,
        'variables' => [],
    ],
    'flow_cancelled' => [
        'category' => 'Command menu', 'name' => 'Cancelled flow',
        'description' => 'Shown after a member cancels an active WhatsApp journey.',
        'body' => "Mchakato umefutwa.\n\n{main_menu}", 'variables' => ['main_menu'],
    ],
    'group_creation_start' => [
        'category' => 'Command menu', 'name' => 'Create group start',
        'description' => 'Shown when a member starts UNDA KIKUNDI.',
        'body' => 'Karibu Kijumbe. Tutaunda kikundi hatua kwa hatua kwa mazungumzo ya Kiswahili. Jibu kila swali; unaweza kurudi baadaye kuendelea.', 'variables' => [],
    ],
    'group_edit_start' => [
        'category' => 'Command menu', 'name' => 'Edit group start',
        'description' => 'Shown when a coordinator starts BADILI TAARIFA.',
        'body' => "Mchezo: {group_name}\n\n{edit_menu}", 'variables' => ['group_name', 'edit_menu'],
    ],
    'group_edit_select' => [
        'category' => 'Command menu', 'name' => 'Choose group to edit',
        'description' => 'Shown when a coordinator can edit more than one group.',
        'body' => "Chagua mchezo unaotaka kubadili:\n{groups}\nTuma namba ya mchezo.", 'variables' => ['groups'],
    ],
    'payment_start' => [
        'category' => 'Command menu', 'name' => 'Payment start',
        'description' => 'Shown when a member starts LIPA.',
        'body' => "Unalipia nani?\n1. Mimi\n2. Mtu mwingine\n\nTuma 1 au 2.", 'variables' => [],
    ],
    'payout_unavailable' => [
        'category' => 'Command menu', 'name' => 'Payout unavailable',
        'description' => 'Shown when no payout is ready after POKEA.',
        'body' => 'Hakuna malipo ya kupokea yaliyo tayari kwa sasa. Tutakutumia ujumbe siku yako ya kupokea ikifika.', 'variables' => [],
    ],
    'payout_phone_prompt' => [
        'category' => 'Command menu', 'name' => 'Payout phone prompt',
        'description' => 'Shown when a recipient starts the secure payout flow.',
        'body' => 'Tuma namba ya simu utakayotumia kupokea {payout_label} ya TZS {amount}. Mfano 0748281701. Tuma KATISHA ukitaka kuacha.', 'variables' => ['payout_label', 'amount'],
    ],
    'pin_existing_start' => [
        'category' => 'Command menu', 'name' => 'Change existing PIN start',
        'description' => 'Shown when a member changes an existing PIN.',
        'body' => 'Tuma namba yako ya siri ya sasa ili kuibadilisha.', 'variables' => [],
    ],
    'pin_new_start' => [
        'category' => 'Command menu', 'name' => 'Create PIN start',
        'description' => 'Shown when a member creates a PIN for the first time.',
        'body' => 'Hujaweka namba ya siri. Tuma namba mpya ya tarakimu 4 hadi 6.', 'variables' => [],
    ],
    'status_clear' => [
        'category' => 'Command menu', 'name' => 'Status with no debt',
        'description' => 'Shown when STATUS finds no contribution waiting for payment.',
        'body' => 'Huna mchango unaosubiri kulipiwa.', 'variables' => [],
    ],
    'status_due' => [
        'category' => 'Command menu', 'name' => 'Status with debt',
        'description' => 'Shown when STATUS finds an outstanding contribution.',
        'body' => 'Mchango unaosubiri: TZS {amount}. Mwisho wa kulipa ni {deadline}.', 'variables' => ['amount', 'deadline'],
    ],
    'report_start' => [
        'category' => 'Command menu', 'name' => 'Report start',
        'description' => 'Shown when a member starts TAARIFA.',
        'body' => "Chagua mchezo unaotaka kuona taarifa:\n{groups}\n\nTuma namba ya mchezo.", 'variables' => ['groups'],
    ],
    'contribution_reminder' => [
        'category' => 'Automatic financial messages', 'name' => 'Contribution reminder',
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
        'category' => 'Automatic financial messages', 'name' => 'Payment debt summary',
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
        'category' => 'Automatic financial messages', 'name' => 'Payout completed',
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
