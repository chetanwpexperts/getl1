<?php

namespace App\Support;

use App\Models\AuditLog;

/**
 * Plain-English line for an audit entry on the RFQ activity timeline.
 * Quote entries never show amounts: the timeline is visible while quotes are still sealed.
 */
final class ActivityText
{
    /** @param array<string, string> $names e.g. ['quote:12' => 'Sharma Cartons', 'rfq_invite:5' => 'Punjab Box Works'] */
    public static function describe(AuditLog $log, array $names = []): string
    {
        $a = $log->after ?? [];
        $who = $names[$log->auditable_type.':'.$log->auditable_id] ?? null;
        $inr = fn ($v) => Money::inr($v);

        return match ($log->action) {
            'rfq_created' => 'Created the RFQ as a draft',
            'rfq_updated' => 'Edited the draft',
            'rfq_published' => 'Published the RFQ and sent invitations',
            'rfq_suppliers_invited' => 'Invited '.(isset($a['count']) ? $a['count'].' supplier(s)' : 'suppliers'),
            'rfq_invite_removed' => 'Removed an invited supplier'.($who ? " ({$who})" : ''),
            'rfq_invite_claimed' => ($who ?? 'A supplier').' opened the invitation',
            'rfq_invite_accepted' => ($who ?? 'A supplier').' accepted the RFQ terms',
            'rfq_invite_declined' => ($who ?? 'A supplier').' declined'.(! empty($a['reason']) ? ': '.$a['reason'] : ''),
            'rfq_deadline_extended' => 'Extended the quote deadline',
            'rfq_cancelled' => 'Cancelled the RFQ'.(! empty($a['reason']) ? ': '.$a['reason'] : ''),
            'rfq_attachment_added' => 'Added an attachment',
            'rfq_attachment_removed' => 'Removed an attachment',
            'rfq_question_asked' => ($who ?? 'A supplier').' asked a question',
            'rfq_question_answered' => 'Answered '.($who ? "{$who}'s" : 'a').' question '.(($a['visibility'] ?? 'all') === 'private' ? '(only to them)' : '(to every supplier)'),
            'rfq_clarification_posted' => 'Sent a clarification to every supplier',
            'quote_submitted' => ($who ?? 'A supplier').' submitted a sealed quote',
            'quote_revised' => ($who ?? 'A supplier').' revised their sealed quote',
            'auction_scheduled' => 'Scheduled a live auction'.(isset($a['participants']) ? " with {$a['participants']} suppliers" : ''),
            'auction_cancelled' => 'Cancelled the live auction'.(! empty($a['reason']) ? ': '.$a['reason'] : ''),
            'auction_opened' => 'Live auction started',
            'auction_closed' => 'Live auction closed'.(isset($a['final_l1']) ? ' at '.$inr($a['final_l1']).' after '.($a['bids'] ?? 0).' bids' : ''),
            'bid_log_downloaded' => 'Downloaded the auction bid log',
            'awarded' => 'Awarded to '.($a['supplier'] ?? 'a supplier').(isset($a['rank']) ? " (L{$a['rank']})" : '').(isset($a['total']) ? ' for '.$inr($a['total']) : '')
                .(! empty($a['reason']) ? '. Reason: '.$a['reason'] : ''),
            'award_approved' => (($a['by'] ?? null) === 'automatic (no approval required)' ? 'Approved automatically (no approval required)' : 'Approved the award')
                .(! empty($a['note']) ? ': '.$a['note'] : ''),
            'award_rejected' => 'Rejected the award'.(! empty($a['note']) ? ': '.$a['note'] : ''),
            'po_issued' => 'Purchase order '.($a['po_number'] ?? '').' issued and emailed to the supplier',
            'po_accepted' => ($who ?? 'The supplier').' accepted purchase order '.($a['po_number'] ?? ''),
            default => ucfirst(str_replace('_', ' ', $log->action)),
        };
    }
}
