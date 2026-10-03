# WhatsApp templates for GetL1

GetL1 sends five WhatsApp messages. Meta must approve each template before it can be used.
Create them in **WhatsApp Manager → Message templates → Create template**:

- Category: **Utility**
- Language: **English**
- Each template has one button: **Visit website**, type **Dynamic**, URL `https://getl1.com/{{1}}`
  (use your staging domain for the staging number). GetL1 fills in the page.
- Body text: copy exactly, including the `{{1}}`, `{{2}}`, `{{3}}` variables.

| Name | Body | Button |
|---|---|---|
| `getl1_rfq_invite` | `{{1}} has invited you to quote for "{{2}}" on GetL1. Quotes close {{3}}. Tap below to view the RFQ and send your quote.` | View RFQ |
| `getl1_auction_starting` | `The live auction for "{{1}}" starts at {{2}}. Tap below to open the auction room and be ready to bid.` | Open auction |
| `getl1_po_issued` | `{{1}} has issued purchase order {{2}} for {{3}} on GetL1. Tap below to view and accept it.` | View PO |
| `getl1_approval_needed` | `An award for "{{1}}" ({{2}}) made by {{3}} is waiting for your approval on GetL1.` | Review |
| `getl1_payment_recorded` | `{{1}} has recorded payment for your invoice {{2}}: {{3}}. Tap below for details.` | View |

Sample values Meta asks for (any realistic text works):
`Acme Industries`, `Corrugated boxes for November`, `05 Oct, 05:00 PM IST`, `PO-2026-0012`, `₹1,18,000.00`, `Rohit Mehta`, `INV/26-27/045`.

## Server settings (.env), once approved

```
WHATSAPP_PHONE_NUMBER_ID=   # WhatsApp Manager → Phone numbers
WHATSAPP_TOKEN=             # a permanent System User token with whatsapp_business_messaging
WHATSAPP_APP_SECRET=        # Meta app → Settings → Basic → App secret
WHATSAPP_VERIFY_TOKEN=      # any long random text; type the same in the webhook setup
```

Webhook (Meta app → WhatsApp → Configuration): callback URL `https://getl1.com/webhooks/whatsapp`,
verify token as above, subscribe to **messages**. Delivery receipts and STOP replies then show up
in GetL1 automatically. Then run `php artisan config:cache` and `php artisan queue:restart`.

Until these are set, WhatsApp is simply off; everything else works as before.
