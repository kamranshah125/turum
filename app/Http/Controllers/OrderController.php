<?php

namespace App\Http\Controllers;

use App\Models\IntegrationOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = IntegrationOrder::query();

        // Filters
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('shopify_order_id', 'like', "%$search%")
                  ->orWhere('turum_reservation_id', 'like', "%$search%")
                  ->orWhere('tracking_number', 'like', "%$search%")
                  ->orWhere('payload', 'like', "%$search%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->get('status');
            if ($status === 'RESERVED') {
                $query->where('status', 'reserved');
            } elseif ($status === 'FAILED') {
                $query->whereIn('status', ['failed', 'cancelled']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->get('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->get('date_to'));
        }

        $orders = $query->latest()->paginate(20)->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'search' => $request->get('search'),
            'status' => $request->get('status'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
        ]);
    }

    public function show($id)
    {
        $order = IntegrationOrder::findOrFail($id);
        
        return view('orders.show', [
            'order' => $order
        ]);
    }
}
