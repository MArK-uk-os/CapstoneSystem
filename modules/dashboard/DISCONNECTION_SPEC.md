# Disconnection Rules

## Rule 1: When is an account flagged?
- Due date is: Ang due date na nakalagay sa bill ng customer (galing sa Billing record).
- The counting of days starts on: Ang araw pagkatapos ng due date (day 1).
- After how many days is the account marked "For Disconnection"? 3 araw (day 3).
- Do Saturdays and Sundays count? Yes (calendar days). [CONFIRM].

## Rule 2: Account statuses (in order)
1. Current - means: Hindi pa lampas sa due date ang bill, o bayad na ito.
2. Overdue - means: Lampas na sa due date at hindi pa bayad, pero 1 hanggang 2 araw pa lang na late. Nagpapadala ang system ng warning message sa customer.
3. For Disconnection - means: 3 araw o higit pa na late at hindi pa rin bayad. Lumalabas ang account sa listahan ng staff na dapat putulan, at nagpapadala ang system ng disconnection notice.
4. Disconnected - means: Pinutol na ng staff ang internet ng customer. Ang staff ang nagre-record nito sa system (halimbawa sa button na "Mark as Disconnected"), dahil hindi kontrolado ng system ang router.
5. Reconnected - means: Nagbayad ang customer ng unpaid balance at ₱100 reconnection fee, na-verify ng staff ang bayad, at naibalik na ang internet. Ire-record ito ng staff sa system. Sa susunod na billing cycle, babalik na ito sa Current.

## Rule 3: Reconnection
- Reconnection fee: ₱100
- Does the customer also pay the unpaid balance? Yes. Ang babayaran ay unpaid balance + ₱100.
- Who records the payment? Ang staff. Ire-record niya ang payment at ang ₱100 fee, tapos i-verify bago ma-mark na Reconnected.

## Rule 4: Messages to the customer
## Rule 4: Messages to the customer
- Warning message is sent on day: 1 (via SMS and email)
- Disconnection message is sent on day: 3 (via SMS and email)

Warning message (day 1):
Cloud Interweb: Hi [name], your bill of PHP[amount] is past due. Please pay by [date] to avoid disconnection.

Disconnection message (day 3):
Cloud Interweb: [name], your PHP[amount] bill is now 3 days overdue. Your account will be disconnected unless paid. Reconnection fee: PHP100.

Placeholders (the system fills in the real values):
- [name] = the customer's name
- [amount] = the unpaid balance
- [date] = the date the account will be flagged for disconnection (day 3)

## Client answers
(Date: __october 15 2026____)  
1. Staff ang pumuputol ng internet, taga-record lang ang system - [ASSUMED]
2. Unpaid balance + ₱100 ang babayaran sa reconnection - [ASSUMED]
3. Weekends count (calendar days) - [CONFIRMED]
4. Bayad sa due date mismo = hindi pa late - [CONFIRMED]
5. Flag sa mismong day 3 - [ASSUMED]
6. Walang grace period o promise-to-pay - [ASSUMED]
7. Warning message sa day 1 - [ASSUMED]
8. Wi-Fi subscribers lang ang sakop - [ASSUMED]
9. Pareho ang ₱100 reconnection fee sa lahat ng plan - [CONFIRMED]
10. Warning message sa day 1 (ang araw pagkatapos ng due date) - [CONFIRMED]
11. Nagbayad sa day 2 = balik agad sa Current, walang ₱100 - [CONFIRMED]
12. Wi-Fi monthly subscribers lang ang sakop - [CONFIRMED] 