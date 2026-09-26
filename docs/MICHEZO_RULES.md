# Michezo Business Rules

## Payout timing

- `rotating` is the normal mode: each jina receives its pooled principal on its assigned turn.
- `end_of_game` pays every jina its own accumulated principal contributions on the final contribution date.
- In `end_of_game`, no jina can receive early. Every principal obligation for every jina and round must be funded before any payout becomes available.
- Once the whole mchezo is funded, all majina become eligible to receive their individual payouts.
- Fines and Kijumbe fees are accounted for separately and are not included in or deducted from the principal returned to a member at the end.
- A goods-group creator is the Kijumbe/owner but is not automatically assigned a jina. They may join later with the group code if they want to contribute.

This document is the source of truth for Kijumbe contribution groups.

## Group rules

- A Michezo group has a contribution interval and a separate payout interval.
- Contribution interval answers: “Members contribute every how many days?”
- Payout interval answers: “After how many days does the next member receive the principal?”
- Both intervals are configurable per group and may be different. For example, members can contribute every day while the next member receives the pooled principal every 3 days.
- `contribution_amount` is the fixed amount owed by one jina for one contribution interval. The interval changes the due date, not the amount. For example, TZS 10,000 with an interval of 1 is paid every day; TZS 10,000 with an interval of 5 is paid every five days.
- A member may own more than one jina. The member's contribution, Kijumbe fee, and penalty are multiplied by the number of active majina they own.
- Each jina is an independent payout slot. Payout slots are randomly ordered and persisted, so a member with multiple majina receives each payout in separate rounds.
- The payout pool for one interval is the contribution amount multiplied by the total active majina in the group. Kijumbe fees and penalties are not part of the principal payout.
- A payout recipient receives principal contributions only. Kijumbe fees and penalties are excluded from the payout pool.
- The group stores the Kijumbe fee amount, penalty amount, and penalty timing.
- The leader phone number is taken from the WhatsApp sender and cannot be entered manually.
- The group code is generated from the group name and random digits, for example `KIKUNDI-1232`.

## Business-controlled splits

- Fee and penalty split percentages are global Kijumbe business settings, not group settings.
- The business controls the percentage paid to the Kijumbe and the percentage retained by the system.
- Every fee and penalty allocation must snapshot the percentages used at collection time so historical records do not change when settings change.

## Payment records

- A member may belong to multiple groups.
- Every payment is tied to one group, payout round, member obligation, and provider reference.
- Partial payments are accumulated against the member’s obligation before the payout deadline.
- The system must record the principal, Kijumbe fee, penalty, Kijumbe share, and system share separately.

## Policies still requiring a business decision

- Whether an underfunded round blocks payout or permits a partial payout.
- Whether a penalty is applied once per overdue obligation or repeatedly.
- How a partial payment is allocated between principal, fee, and penalty.
