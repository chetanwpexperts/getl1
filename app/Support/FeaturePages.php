<?php

namespace App\Support;

/**
 * Content of the public feature pages (/features and /features/{slug}). Written for buyers in
 * Indian manufacturing and SMEs; each page targets the words people search for.
 * Images are real GetL1 screens with example data (public/images/features).
 */
final class FeaturePages
{
    public const PAGES = [
        'purchase-requests-and-approvals' => [
            'nav' => 'Purchase requests & approvals',
            'title' => 'Purchase requisition and approval workflow software',
            'description' => 'Staff raise purchase requests from the shop floor, managers approve in order by amount, and approved requests become an RFQ in one click. Built for Indian SMEs.',
            'h1' => 'Purchase requests and approvals, without the paper indent',
            'intro' => 'Store and plant staff ask for material on GetL1 instead of a paper indent or a WhatsApp message. The right people approve it, in the right order, and the purchase team turns it into an RFQ in one click. Everyone can see where their request is, right up to delivery.',
            'sections' => [
                [
                    'h2' => 'A purchase request anyone can raise',
                    'text' => 'Requesters list what they need, how much and by when, with an optional rough rate. They see only their own requests: no supplier names, no prices paid, no purchase orders. Requesters are free on every plan, so the whole shop floor can use it.',
                    'bullets' => ['Items, quantity, needed-by date and department', 'Progress from approval to quotes, order and delivery', 'Alerts when it is approved, ordered and received'],
                    'image' => 'request-progress.webp', 'alt' => 'A purchase request in GetL1 showing its items and progress from approval to delivery',
                ],
                [
                    'h2' => 'Approval rules that match your company',
                    'text' => 'Set approval levels once: for example Purchase manager for every award, Plant head from ₹5 lakh, Director from ₹25 lakh or whenever the order does not go to L1. Each level approves in order, and the purchase order goes out on its own after the last one.',
                    'bullets' => ['Levels by amount, or when L1 is not chosen, only one quote came in, or it is a new supplier', 'Named approvers or anyone with approval rights', 'Nobody approves their own award or two levels of it'],
                    'image' => 'approval-rules.webp', 'alt' => 'Approval rules page with approval levels by amount and conditions',
                ],
                [
                    'h2' => 'From approved requests to one RFQ',
                    'text' => 'Tick the approved requests to buy together. The same items are combined into one line with the total quantity and the earliest needed-by date. Suppliers see a clean RFQ, never your internal notes or request numbers.',
                    'bullets' => ['Combine requests from several departments', 'Last purchase price filled in automatically', 'Requests go back to the purchase team if the RFQ is dropped'],
                    'image' => 'requests.webp', 'alt' => 'List of purchase requests waiting for approval',
                ],
            ],
            'faq' => [
                ['Is a purchase request the same as an indent?', 'Yes. A purchase request, purchase requisition or indent is the internal ask for material before buying. In GetL1 it is approved first, then becomes an RFQ for suppliers.'],
                ['Do requesters need a paid seat?', 'No. Requesters are free on every plan, up to 100 per company. They can only raise and follow their own requests.'],
                ['Can we have more than one approval level?', 'Yes. Add as many levels as you need, each with its own amount, conditions and approver. They approve one after another, and the first rejection stops the award.'],
                ['Can an approver approve their own purchase?', 'No. Nobody can approve an award they made, and one person cannot approve two levels of the same award.'],
            ],
        ],

        'msme-payment-tracker' => [
            'nav' => 'MSME 45-day payments',
            'title' => 'MSME 45-day payment tracker for Section 43B(h)',
            'description' => 'Track MSME supplier invoices against the 45-day rule of the MSMED Act and Section 43B(h). Due dates are worked out for you, with reminders before they are due.',
            'h1' => 'Pay MSME suppliers on time, every time',
            'intro' => 'The MSMED Act asks buyers to pay micro and small suppliers within the agreed credit period and never more than 45 days from accepting the goods. Under Section 43B(h) of the Income Tax Act, a late payment can also cost you the deduction for that year. GetL1 works out every due date and reminds you before it slips.',
            'sections' => [
                [
                    'h2' => 'Due dates worked out from the law and your terms',
                    'text' => 'Suppliers upload their invoice against your purchase order. GetL1 knows which suppliers are MSME from their Udyam number or your supplier list, and sets the due date: the agreed credit period capped at 45 days from accepting the goods, or 15 days if no period was agreed.',
                    'bullets' => ['Acceptance date taken from your goods receipt', 'Non-MSME suppliers follow your agreed credit period', 'Due dates recalculated if a supplier becomes MSME later'],
                    'image' => 'payments.webp', 'alt' => 'Payments page showing MSME invoices with due dates and days left',
                ],
                [
                    'h2' => 'Every invoice checked before you pay',
                    'text' => 'Each invoice is matched against the purchase order, the goods actually accepted, the GST rate and the supplier GSTIN. Approve it when everything matches, raise a query when it does not, and record the payment with the UTR when you pay.',
                    'bullets' => ['Three-way match: PO, goods receipt and invoice', 'Disputed invoices go back to the supplier with your reason', 'Payments recorded with date, amount and reference'],
                    'image' => 'invoice-checks.webp', 'alt' => 'Supplier invoice checked against PO value, goods received, GST and GSTIN',
                ],
            ],
            'faq' => [
                ['What is the 45-day rule for MSME payments?', 'Under Section 15 of the MSMED Act, a buyer must pay a micro or small enterprise within the agreed period, which cannot be more than 45 days from the day the goods or services are accepted. Without an agreement, the limit is 15 days.'],
                ['What does Section 43B(h) change?', 'Since FY 2023-24, an amount owed to a micro or small enterprise is allowed as an expense only in the year it is actually paid if it is paid after the MSMED Act time limit. Paying on time keeps the deduction in the same year. Please confirm details with your CA.'],
                ['How does GetL1 know a supplier is MSME?', 'From the Udyam registration number on the supplier\'s profile, or when you mark the supplier as MSME in your supplier list.'],
                ['Will we get reminders?', 'Yes. Admins get one daily summary of MSME invoices due within 7 days or overdue, and the Payments menu shows a count until they are paid.'],
            ],
        ],

        'supplier-management' => [
            'nav' => 'Supplier scorecard & rate contracts',
            'title' => 'Supplier evaluation, rate contracts and price history',
            'description' => 'Rate suppliers on delivery, quality and response from your own orders, check GSTIN and PAN, lock in rate contracts and see what you paid for every item over time.',
            'h1' => 'Know your suppliers and what you really pay',
            'intro' => 'Price is only half the decision. GetL1 builds a scorecard for each supplier from your own orders, checks their GST details, keeps the rates you agreed in rate contracts, and shows what you have paid for every item over time. All of it appears where you decide: next to each quote.',
            'sections' => [
                [
                    'h2' => 'A supplier scorecard from your own orders',
                    'text' => 'Every supplier gets a score out of 100 from the last 12 months of your orders with them: on-time delivery, quality from rejected goods, how often they quote, how fast they accept purchase orders and how many invoices were disputed. Only your company sees it.',
                    'bullets' => ['Score shown beside each quote and on the award form', 'GSTIN check digit, GST state and PAN checked', 'Udyam (MSME) number and document verification shown'],
                    'image' => 'scorecard.webp', 'alt' => 'Supplier scorecard with delivery, quality, quote response and registration checks',
                ],
                [
                    'h2' => 'Price history for every item you buy',
                    'text' => 'Every purchase order adds its item rates to your price history. See the last rate, the lowest you paid, the trend and which supplier gave which price. When you create the next RFQ, the last price is filled in for you, so the savings report works without extra effort.',
                    'bullets' => ['Matched by item, specification and unit', 'Rate chart over time', 'Last price shown as you type a new RFQ'],
                    'image' => 'price-history.webp', 'alt' => 'Price history of one item with a rate chart and past purchase orders',
                ],
                [
                    'h2' => 'Rate contracts in one click',
                    'text' => 'Turn any purchase order into a rate contract: the agreed rates with that supplier for the months ahead. The supplier confirms it online, your team sees the contract rate whenever the item comes up, and you are reminded 30 and 7 days before it ends.',
                    'bullets' => ['Made from a PO or by hand', 'Supplier confirmation recorded', 'End early with a reason the supplier sees'],
                    'image' => 'contracts.webp', 'alt' => 'Rate contract with agreed rates, validity and supplier confirmation',
                ],
            ],
            'faq' => [
                ['How is the supplier score calculated?', 'From the last 12 months of your orders: on-time delivery (35%), quality from rejected goods (30%), quote response (15%), PO acceptance speed (10%) and invoice accuracy (10%). It appears once a delivery has been recorded.'],
                ['Can other buyers see our scores?', 'No. Each buyer\'s scores come only from its own orders and are never shared.'],
                ['Does GetL1 check if a GSTIN is real?', 'It checks the format and the check digit, that the state matches the address and that the PAN matches the GSTIN. For large orders we still suggest confirming the GSTIN is active on the GST portal.'],
                ['What is a rate contract?', 'An agreement with a supplier to supply items at fixed rates for a period, usually a year. Repeat items are then bought at the agreed rate without negotiating every time.'],
            ],
        ],

        'purchase-orders-grn-invoices' => [
            'nav' => 'Purchase orders, GRN & invoices',
            'title' => 'Purchase order, GRN and invoice matching software',
            'description' => 'GST-ready purchase orders sent automatically, goods receipt notes with rejections, supplier invoices matched to PO and GRN, and export to Tally.',
            'h1' => 'From award to payment, without retyping anything',
            'intro' => 'Award an RFQ and the GST-ready purchase order goes to the supplier on its own. Record what arrived in a goods receipt note, let the supplier upload the invoice, and GetL1 checks it against the PO and the goods you accepted. Export everything to Tally when you are done.',
            'sections' => [
                [
                    'h2' => 'Goods receipt notes with rejections',
                    'text' => 'Record each delivery as it arrives, part deliveries included. Note rejected quantities with the reason; the supplier is told straight away. Purchase orders show what has been received and what is still pending.',
                    'bullets' => ['GRN numbers created for you', 'Part deliveries and rejections', 'The supplier and the requester are alerted'],
                    'image' => 'goods-receipt.webp', 'alt' => 'Purchase order showing quantities ordered, accepted, rejected and pending',
                ],
                [
                    'h2' => 'Live alerts for every step',
                    'text' => 'Everyone involved hears about what matters to them the moment it happens: a new quote, an auction going live, an award to approve, a PO accepted, goods received, an invoice to review. In the app, as a pop-up, and on their phone when GetL1 is closed.',
                    'bullets' => ['Bell with every update', 'Device alerts on desktop and mobile', 'Choose which alerts reach your phone'],
                    'image' => 'alerts.webp', 'alt' => 'Notification bell with live updates on quotes, orders and invoices',
                ],
            ],
            'faq' => [
                ['Does the purchase order include GST correctly?', 'Yes. CGST and SGST or IGST is chosen from the buyer and supplier GSTINs,, amount in words and your standard terms.'],
                ['Can we export to Tally?', 'Yes. Purchase orders and the supplier and item masters export as Tally XML for TallyPrime and Tally.ERP 9, plus an Excel (CSV) register.'],
                ['What is three-way matching?', 'Checking a supplier invoice against the purchase order and the goods receipt before paying. GetL1 also checks the GST amount and the supplier GSTIN.'],
            ],
        ],
    ];

    public static function get(string $slug): ?array
    {
        return self::PAGES[$slug] ?? null;
    }
}
