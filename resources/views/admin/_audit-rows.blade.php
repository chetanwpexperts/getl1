<div class="mt-2 overflow-x-auto">
    <table class="w-full text-sm">
        <tbody class="divide-y divide-slate-100">
            @forelse ($logs as $l)
                <tr class="align-top">
                    <td class="whitespace-nowrap py-2 pr-4 text-slate-500">{{ $l->created_at->ist()->format('d M, h:i:s A') }}</td>
                    <td class="py-2 pr-4 font-medium">{{ str_replace('_', ' ', $l->action) }}</td>
                    <td class="py-2 pr-4 text-slate-600">{{ $l->user?->email ?? 'system' }}</td>
                    <td class="py-2 pr-4 text-xs text-slate-500">{{ $l->auditable_type ? class_basename($l->auditable_type).' #'.$l->auditable_id : '' }}</td>
                    <td class="max-w-md break-all py-2 font-mono text-xs text-slate-500">{{ $l->after ? \Illuminate\Support\Str::limit(json_encode($l->after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 160) : '' }}</td>
                </tr>
            @empty
                <tr><td class="py-4 text-slate-500">Nothing yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
