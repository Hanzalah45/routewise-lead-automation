@props(['status'])
@php($labels = ['received' => 'Received', 'routed' => 'Waiting for CRM', 'synced' => 'In CRM', 'retrying' => 'Retrying', 'dead' => 'Needs attention', 'duplicate' => 'Duplicate', 'spam' => 'Spam', 'held' => 'Held: no consent'])
<span class="status {{ $status }}">{{ $labels[$status] ?? ucfirst($status) }}</span>
