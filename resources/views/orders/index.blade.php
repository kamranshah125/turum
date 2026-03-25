@extends('layouts.dashboard')

@section('title', 'Orders List - Turum Developer')
@section('page_title', 'Integration Orders')

@section('styles')
<style>
    .order-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 0.5rem;
    }
    .order-row {
        background: rgba(30, 41, 59, 0.4);
        transition: all 0.2s;
        cursor: pointer;
    }
    .order-row:hover {
        background: rgba(30, 41, 59, 0.6);
        transform: translateX(4px);
    }
    .order-row td {
        padding: 1.25rem 1rem;
        font-size: 0.875rem;
    }
    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-reserved { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
    .status-failed, .status-cancelled { background: rgba(239, 68, 68, 0.1); color: var(--error); border: 1px solid rgba(239, 68, 68, 0.2); }
    .status-pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }
    
    .pagination-wrapper {
        margin-top: 2rem;
        display: flex;
        justify-content: center;
    }
    .pagination-wrapper nav { display: flex; gap: 0.5rem; }
    .pagination-wrapper a, .pagination-wrapper span {
        padding: 0.5rem 1rem;
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 0.5rem;
        color: var(--text-main);
        text-decoration: none;
    }
    .pagination-wrapper .active {
        background: var(--accent-color);
        color: #0f172a;
    }
</style>
@endsection

@section('search_bar')
<form action="{{ route('orders.index') }}" method="GET" class="search-bar">
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <span style="font-size: 0.8rem; color: var(--text-dim);">From:</span>
        <input type="date" name="date_from" value="{{ $date_from ?? '' }}">
        <span style="font-size: 0.8rem; color: var(--text-dim);">To:</span>
        <input type="date" name="date_to" value="{{ $date_to ?? '' }}">
    </div>
    <select name="status">
        <option value="">All Statuses</option>
        <option value="RESERVED" {{ ($status ?? '') == 'RESERVED' ? 'selected' : '' }}>Reserved</option>
        <option value="FAILED" {{ ($status ?? '') == 'FAILED' ? 'selected' : '' }}>Failed / Cancelled</option>
        <option value="pending" {{ ($status ?? '') == 'pending' ? 'selected' : '' }}>Pending</option>
    </select>
    <input type="text" name="search" placeholder="Order ID, SKU, Payload..." value="{{ $search ?? '' }}" style="flex-grow: 1;">
    <button type="submit" class="btn-search">Filter Orders</button>
</form>
@endsection

@section('content')
<div class="card">
    <table class="order-table">
        <thead>
            <tr style="text-align: left; color: var(--text-dim); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">
                <th style="padding: 1rem;">Shopify ID</th>
                <th style="padding: 1rem;">Product</th>
                <th style="padding: 1rem;">Price</th>
                <th style="padding: 1rem;">Status</th>
                <th style="padding: 1rem;">Tracking</th>
                <th style="padding: 1rem;">Created At</th>
                <th style="padding: 1rem;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
            <tr class="order-row" onclick="window.location='{{ route('orders.show', $order->id) }}'">
                <td><code style="color: var(--accent-color);">#{{ $order->shopify_order_id }}</code></td>
                <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ $order->product_name }}
                </td>
                <td>€{{ $order->total_price }}</td>
                <td>
                    <span class="status-badge status-{{ $order->status }}">
                        {{ $order->status == 'reserved' ? 'RESERVED' : strtoupper($order->status) }}
                    </span>
                </td>
                <td>{{ $order->tracking_number ?: '-' }}</td>
                <td style="color: var(--text-dim);">{{ $order->created_at->format('Y-m-d H:i') }}</td>
                <td>
                    <a href="{{ route('orders.show', $order->id) }}" style="color: var(--accent-color); text-decoration: none; font-size: 0.8rem;">Details →</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" style="padding: 4rem; text-align: center; color: var(--text-dim);">
                    No orders found matching your criteria.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($orders->hasPages())
<div class="pagination-wrapper">
    {{ $orders->links() }}
</div>
@endif
@endsection
