<x-mail::message>
{{ $inviterName }} invited you to see website analytics for **{{ $organizationName }}** on RYVR.

<x-mail::button :url="$link">
Accept invitation
</x-mail::button>

The link works once and expires in 7 days. If you were not expecting this, you can ignore it.
</x-mail::message>
