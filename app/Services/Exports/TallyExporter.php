<?php

namespace App\Services\Exports;

use App\Models\Award;
use App\Models\Organization;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Collection;

/**
 * Tally XML for issued purchase orders (TallyPrime and Tally.ERP 9: Gateway of Tally → Import).
 *
 * Two files, imported in this order:
 *  1. masters(): supplier ledgers (under Sundry Creditors, with GSTIN and state), units and stock
 *     items used on the POs. Import with "Combine" or "Ignore duplicates" so existing ones stay.
 *  2. vouchers(): one "Purchase Order" voucher per PO, item-invoice mode, with GST split into
 *     CGST + SGST (same state) or IGST (different states), freight and round-off.
 *
 * Every voucher balances to the paisa: party credit = items + GST + freight + round-off.
 * Ledger names come from the buyer's settings because every company names them differently.
 */
class TallyExporter
{
    public const DEFAULTS = [
        'company' => '',
        'supplier_group' => 'Sundry Creditors',
        'purchase_ledger' => 'Purchase',
        'cgst_ledger' => 'CGST',
        'sgst_ledger' => 'SGST',
        'igst_ledger' => 'IGST',
        'freight_ledger' => 'Freight Inward',
        'round_off_ledger' => 'Round Off',
        'godown' => 'Main Location',
        'voucher_type' => 'Purchase Order',
    ];

    public const LABELS = [
        'company' => ['Tally company name', 'Optional. Leave empty to import into the company open in Tally.'],
        'supplier_group' => ['Group for suppliers', 'Supplier ledgers are created under this group.'],
        'purchase_ledger' => ['Purchase ledger', 'Item values are posted here.'],
        'cgst_ledger' => ['CGST ledger', 'Same-state suppliers.'],
        'sgst_ledger' => ['SGST ledger', 'Same-state suppliers.'],
        'igst_ledger' => ['IGST ledger', 'Other-state suppliers.'],
        'freight_ledger' => ['Freight ledger', 'When the supplier quoted freight separately.'],
        'round_off_ledger' => ['Round-off ledger', 'Paise difference after an auction, if any.'],
        'godown' => ['Godown', 'Where ordered stock is expected.'],
        'voucher_type' => ['Voucher type', 'Usually "Purchase Order".'],
    ];

    /** Saved settings over the defaults; blanks fall back to defaults. */
    public static function settings(Organization $buyer): array
    {
        $saved = array_filter((array) ($buyer->tally_settings ?? []), fn ($v) => is_string($v) && trim($v) !== '');

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    /** @param Collection<int, Award> $awards issued POs (with supplier loaded) */
    public function masters(Collection $awards, Organization $buyer): string
    {
        $s = self::settings($buyer);
        $out = [];

        foreach ($awards->pluck('supplier')->filter()->unique('id') as $supplier) {
            $name = self::ledgerName($supplier);
            $address = collect([$supplier->address, $supplier->city])->filter()->map(fn ($l) => '<ADDRESS>'.self::x($l).'</ADDRESS>')->implode('');
            $out[] = '<TALLYMESSAGE xmlns:UDF="TallyUDF"><LEDGER NAME="'.self::x($name).'" ACTION="Create">'
                .'<NAME.LIST TYPE="String"><NAME>'.self::x($name).'</NAME></NAME.LIST>'
                .'<PARENT>'.self::x($s['supplier_group']).'</PARENT>'
                .'<ISBILLWISEON>Yes</ISBILLWISEON>'
                .'<MAILINGNAME.LIST TYPE="String"><MAILINGNAME>'.self::x($supplier->name).'</MAILINGNAME></MAILINGNAME.LIST>'
                .($address ? '<ADDRESS.LIST TYPE="String">'.$address.'</ADDRESS.LIST>' : '')
                .($supplier->state ? '<LEDSTATENAME>'.self::x($supplier->state).'</LEDSTATENAME>' : '')
                .'<COUNTRYNAME>India</COUNTRYNAME>'
                .($supplier->pincode ? '<PINCODE>'.self::x($supplier->pincode).'</PINCODE>' : '')
                .($supplier->email ? '<EMAIL>'.self::x($supplier->email).'</EMAIL>' : '')
                .($supplier->gstin
                    ? '<GSTREGISTRATIONTYPE>Regular</GSTREGISTRATIONTYPE><PARTYGSTIN>'.self::x($supplier->gstin).'</PARTYGSTIN>'
                    : '<GSTREGISTRATIONTYPE>Unregistered</GSTREGISTRATIONTYPE>')
                .($supplier->pan ? '<INCOMETAXNUMBER>'.self::x($supplier->pan).'</INCOMETAXNUMBER>' : '')
                .'</LEDGER></TALLYMESSAGE>';
        }

        $lines = $awards->flatMap(fn (Award $a) => $a->lines['items'] ?? []);
        foreach ($lines->pluck('unit')->filter()->unique() as $unit) {
            $out[] = '<TALLYMESSAGE xmlns:UDF="TallyUDF"><UNIT NAME="'.self::x($unit).'" ACTION="Create">'
                .'<NAME>'.self::x($unit).'</NAME><ISSIMPLEUNIT>Yes</ISSIMPLEUNIT><DECIMALPLACES>3</DECIMALPLACES>'
                .'</UNIT></TALLYMESSAGE>';
        }
        foreach ($lines->unique(fn ($l) => mb_strtolower(self::itemName($l))) as $line) {
            $item = self::itemName($line);
            $out[] = '<TALLYMESSAGE xmlns:UDF="TallyUDF"><STOCKITEM NAME="'.self::x($item).'" ACTION="Create">'
                .'<NAME.LIST TYPE="String"><NAME>'.self::x($item).'</NAME></NAME.LIST>'
                .'<BASEUNITS>'.self::x($line['unit']).'</BASEUNITS>'
                .'</STOCKITEM></TALLYMESSAGE>';
        }

        return $this->envelope('All Masters', $out, $s['company']);
    }

    /** @param Collection<int, Award> $awards issued POs (with supplier and rfq loaded) */
    public function vouchers(Collection $awards, Organization $buyer): string
    {
        $s = self::settings($buyer);
        $out = [];

        foreach ($awards as $award) {
            $supplier = $award->supplier;
            $party = self::ledgerName($supplier);
            $date = ($award->po_sent_at ?? $award->created_at)->ist()->format('Ymd');
            $intra = PurchaseOrderService::intraState($buyer, $supplier);
            $items = $award->lines['items'] ?? [];
            $roundOff = (float) ($award->lines['round_off'] ?? 0);
            $gst = round((float) $award->gst_total, 2);
            $freight = round((float) $award->freight_total, 2);
            $grand = round((float) $award->grand_total, 2);

            $ledgers = [self::entry($party, $grand, credit: true, party: true)];
            if ($gst > 0) {
                if ($intra) {
                    $cgst = round($gst / 2, 2);
                    $ledgers[] = self::entry($s['cgst_ledger'], $cgst);
                    $ledgers[] = self::entry($s['sgst_ledger'], round($gst - $cgst, 2));
                } else {
                    $ledgers[] = self::entry($s['igst_ledger'], $gst);
                }
            }
            if ($freight > 0) {
                $ledgers[] = self::entry($s['freight_ledger'], $freight);
            }
            if (abs($roundOff) >= 0.005) {
                $ledgers[] = self::entry($s['round_off_ledger'], $roundOff);
            }

            $inventory = [];
            foreach ($items as $line) {
                $amount = round((float) $line['amount'], 2);
                $qty = self::qty((float) $line['qty'], $line['unit']);
                $inventory[] = '<ALLINVENTORYENTRIES.LIST>'
                    .'<STOCKITEMNAME>'.self::x(self::itemName($line)).'</STOCKITEMNAME>'
                    .'<ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>'
                    .'<RATE>'.self::num((float) $line['unit_price'], 4).'/'.self::x($line['unit']).'</RATE>'
                    .'<AMOUNT>'.self::num(-$amount).'</AMOUNT>'
                    .'<ACTUALQTY>'.$qty.'</ACTUALQTY><BILLEDQTY>'.$qty.'</BILLEDQTY>'
                    .'<BATCHALLOCATIONS.LIST><GODOWNNAME>'.self::x($s['godown']).'</GODOWNNAME>'
                    .'<ORDERNO>'.self::x($award->po_number).'</ORDERNO>'
                    .'<AMOUNT>'.self::num(-$amount).'</AMOUNT><ACTUALQTY>'.$qty.'</ACTUALQTY><BILLEDQTY>'.$qty.'</BILLEDQTY></BATCHALLOCATIONS.LIST>'
                    .'<ACCOUNTINGALLOCATIONS.LIST><LEDGERNAME>'.self::x($s['purchase_ledger']).'</LEDGERNAME>'
                    .'<ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE><AMOUNT>'.self::num(-$amount).'</AMOUNT></ACCOUNTINGALLOCATIONS.LIST>'
                    .'</ALLINVENTORYENTRIES.LIST>';
            }

            $narration = trim("{$award->rfq?->ref_no} · {$award->rfq?->title} · via GetL1".($award->source === 'auction' ? ' live auction' : ''));
            $out[] = '<TALLYMESSAGE xmlns:UDF="TallyUDF"><VOUCHER VCHTYPE="'.self::x($s['voucher_type']).'" ACTION="Create" OBJVIEW="Invoice Voucher View">'
                .'<DATE>'.$date.'</DATE><EFFECTIVEDATE>'.$date.'</EFFECTIVEDATE>'
                .'<VOUCHERTYPENAME>'.self::x($s['voucher_type']).'</VOUCHERTYPENAME>'
                .'<VOUCHERNUMBER>'.self::x($award->po_number).'</VOUCHERNUMBER>'
                .'<REFERENCE>'.self::x($award->rfq?->ref_no ?? '').'</REFERENCE>'
                .'<PARTYLEDGERNAME>'.self::x($party).'</PARTYLEDGERNAME><PARTYNAME>'.self::x($party).'</PARTYNAME>'
                .($supplier->gstin ? '<PARTYGSTIN>'.self::x($supplier->gstin).'</PARTYGSTIN>' : '')
                .($supplier->state ? '<STATENAME>'.self::x($supplier->state).'</STATENAME>' : '')
                .'<PERSISTEDVIEW>Invoice Voucher View</PERSISTEDVIEW><ISINVOICE>Yes</ISINVOICE>'
                .'<NARRATION>'.self::x(mb_substr($narration, 0, 250)).'</NARRATION>'
                .implode('', $ledgers).implode('', $inventory)
                .'</VOUCHER></TALLYMESSAGE>';
        }

        return $this->envelope('Vouchers', $out, $s['company']);
    }

    /** Party ledger name in Tally: the supplier's registered name (Tally's limit is well above 90 characters). */
    public static function ledgerName(Organization $supplier): string
    {
        return mb_substr(trim($supplier->name), 0, 90);
    }

    private static function itemName(array $line): string
    {
        return mb_substr(trim((string) $line['name']), 0, 90);
    }

    /** Debit by default (ISDEEMEDPOSITIVE Yes, negative amount); credit for the party. */
    private static function entry(string $ledger, float $amount, bool $credit = false, bool $party = false): string
    {
        return '<LEDGERENTRIES.LIST><LEDGERNAME>'.self::x($ledger).'</LEDGERNAME>'
            .'<ISDEEMEDPOSITIVE>'.($credit ? 'No' : 'Yes').'</ISDEEMEDPOSITIVE>'
            .'<ISPARTYLEDGER>'.($party ? 'Yes' : 'No').'</ISPARTYLEDGER>'
            .'<AMOUNT>'.self::num($credit ? $amount : -$amount).'</AMOUNT></LEDGERENTRIES.LIST>';
    }

    private function envelope(string $report, array $messages, string $company): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><IMPORTDATA>'
            .'<REQUESTDESC><REPORTNAME>'.$report.'</REPORTNAME>'
            .($company !== '' ? '<STATICVARIABLES><SVCURRENTCOMPANY>'.self::x($company).'</SVCURRENTCOMPANY></STATICVARIABLES>' : '')
            .'</REQUESTDESC><REQUESTDATA>'."\n".implode("\n", $messages)."\n"
            .'</REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>'."\n";
    }

    private static function qty(float $qty, string $unit): string
    {
        return ' '.rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.').' '.self::x($unit);
    }

    private static function num(float $v, int $dp = 2): string
    {
        $s = number_format($v, $dp, '.', '');

        return $dp > 2 ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** XML-safe text; control characters Tally can't read are dropped. */
    private static function x(?string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v);

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
