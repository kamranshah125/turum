@extends('layouts.dashboard')

@section('title', 'Order Details - Turum Developer')
@section('page_title', 'Order #' . $order->shopify_order_id)

@section('header_actions')
<a href="{{ route('orders.index') }}" style="color: var(--text-dim); text-decoration: none; font-size: 0.875rem;">← Back to List</a>
@endsection

@section('styles')
<style>
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    .info-group {
        padding: 1.5rem;
    }
    .info-label {
        color: var(--text-dim);
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
    }
    .info-value {
        font-weight: 500;
        font-size: 1rem;
    }
    .payload-container {
        margin-top: 2rem;
    }
    .json-block {
        background: #0f172a;
        padding: 1.5rem;
        border-radius: 0.75rem;
        font-family: 'Fira Code', monospace;
        font-size: 0.85rem;
        overflow-x: auto;
        color: #94a3b8;
        line-height: 1.6;
        border: 1px solid var(--border);
    }
</style>
@endsection

@section('content')
<div class="detail-grid">
    <div class="card info-group">
        <div class="info-label">Shopify Order ID</div>
        <div class="info-value"><code style="color: var(--accent-color);">#{{ $order->shopify_order_id }}</code></div>
    </div>
    <div class="card info-group">
        <div class="info-label">Turum Reservation ID</div>
        <div class="info-value">{{ $order->turum_reservation_id ?: 'Not Reserved' }}</div>
    </div>
    <div class="card info-group">
        <div class="info-label">Current Status</div>
        <div class="info-value">
            <span class="status-badge status-{{ $order->status }}">
                {{ $order->status }}
            </span>
        </div>
    </div>
    <div class="card info-group">
        <div class="info-label">Tracking Number</div>
        <div class="info-value">{{ $order->tracking_number ?: 'N/A' }}</div>
    </div>
    <div class="card info-group">
        <div class="info-label">Carrier</div>
        <div class="info-value">{{ $order->carrier ?: 'N/A' }}</div>
    </div>
    <div class="card info-group">
        <div class="info-label">Created At</div>
        <div class="info-value">{{ $order->created_at->format('Y-m-d H:i:s') }}</div>
    </div>
</div>

@if($order->error_message)
<div class="card" style="border-color: var(--error); margin-bottom: 2rem; background: rgba(239, 68, 68, 0.05);">
    <div class="info-group">
        <div class="info-label" style="color: var(--error);">Error Message</div>
        <div class="info-value text-error">{{ $order->error_message }}</div>
    </div>
</div>
@endif

<div class="payload-container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 500;">Raw Payload Data</h3>
        <span style="font-size: 0.75rem; color: var(--text-dim);">Order metadata from webhook/Turum API</span>
    </div>
    <div class="json-block">
        <pre>{{ json_encode($order->payload, JSON_PRETTY_PRINT) }}</pre>
    </div>
</div>
@endsection
