@extends('layouts.dashboard')

@section('title', 'System Logs - Turum Developer')
@section('page_title', 'System Logs')

@section('styles')
<style>
    .log-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 0.5rem;
    }
    .log-entry {
        background: rgba(30, 41, 59, 0.4);
        transition: background 0.2s;
        border-radius: 0.5rem;
    }
    .log-entry:hover { background: rgba(30, 41, 59, 0.6); }
    .log-entry td {
        padding: 1rem;
        vertical-align: top;
        font-size: 0.875rem;
    }
    .level-badge {
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
    }
    .level-ERROR { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
    .level-WARNING { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
    .level-INFO { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
    .level-DEBUG { background: rgba(139, 92, 246, 0.2); color: #a78bfa; border: 1px solid rgba(139, 92, 246, 0.3); }

    .date { color: var(--text-dim); white-space: nowrap; font-family: 'Fira Code', monospace; }
    .message { word-break: break-all; line-height: 1.5; }
    
    .stack-trace {
        display: none;
        padding: 1rem;
        margin-top: 0.5rem;
        background: #0f172a;
        border-radius: 0.5rem;
        font-family: 'Fira Code', monospace;
        font-size: 0.75rem;
        color: #94a3b8;
        white-space: pre-wrap;
        border-left: 2px solid var(--accent-color);
    }
    .entry-header { cursor: pointer; display: flex; align-items: center; justify-content: space-between; }
</style>
@endsection

@section('header_actions')
<a href="{{ route('logs.show') }}" style="color: var(--accent-color); text-decoration: none; font-size: 0.875rem;">Refresh</a>
@endsection

@section('search_bar')
<form action="{{ route('logs.show') }}" method="GET" class="search-bar">
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <span style="font-size: 0.8rem; color: var(--text-dim);">From:</span>
        <input type="date" name="date_from" value="{{ $date_from ?? '' }}" class="date-picker">
        <span style="font-size: 0.8rem; color: var(--text-dim);">To:</span>
        <input type="date" name="date_to" value="{{ $date_to ?? '' }}" class="date-picker">
    </div>
    <input type="text" name="search" placeholder="Search keywords..." value="{{ $search ?? '' }}" style="flex-grow: 1;">
    <select name="level">
        <option value="">All Levels</option>
        <option value="ERROR" {{ ($level ?? '') == 'ERROR' ? 'selected' : '' }}>Error</option>
        <option value="WARNING" {{ ($level ?? '') == 'WARNING' ? 'selected' : '' }}>Warning</option>
        <option value="INFO" {{ ($level ?? '') == 'INFO' ? 'selected' : '' }}>Info</option>
        <option value="DEBUG" {{ ($level ?? '') == 'DEBUG' ? 'selected' : '' }}>Debug</option>
    </select>
    <button type="submit" class="btn-search">Search</button>
</form>
@endsection

@section('content')
<div id="log-container">
    @if(isset($error))
        <div style="padding: 2rem; text-align: center; color: var(--error);">{{ $error }}</div>
    @elseif(empty($logs))
        <div style="padding: 2rem; text-align: center; color: var(--text-dim);">No logs found matching your criteria.</div>
    @else
        <table class="log-table" id="log-table">
            <tbody id="log-body">
                @include('partials.log_entries', ['logs' => $logs, 'start_index' => 0])
            </tbody>
        </table>
        
        <div id="load-more-container" style="text-align: center; padding: 2rem;">
            @if($next_offset > 0)
                <button id="btn-load-more" data-offset="{{ $next_offset }}" class="btn-search" style="background: rgba(56, 189, 248, 0.1); color: var(--accent-color); border: 1px solid var(--accent-color);">
                    Load Older Logs
                </button>
                <div id="loading-spinner" style="display: none; color: var(--text-dim);">Loading...</div>
            @else
                <span style="color: var(--text-dim); font-size: 0.8rem;">End of logs reaching start of file.</span>
            @endif
        </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
    function toggleStack(id) {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = el.style.display === 'block' ? 'none' : 'block';
        }
    }

    let currentOffset = {{ $next_offset ?? 0 }};
    let logCount = {{ count($logs ?? []) }};
    const btnLoadMore = document.getElementById('btn-load-more');
    const loadingSpinner = document.getElementById('loading-spinner');
    const logBody = document.getElementById('log-body');

    if (btnLoadMore) {
        btnLoadMore.addEventListener('click', function() {
            loadMore();
        });
    }

    function loadMore() {
        if (currentOffset <= 0) return;

        btnLoadMore.style.display = 'none';
        loadingSpinner.style.display = 'block';

        const url = new URL(window.location.href);
        url.searchParams.set('offset', currentOffset);
        url.searchParams.set('count', logCount);
        
        if ("{{ $date_from ?? '' }}") url.searchParams.set('date_from', "{{ $date_from ?? '' }}");
        if ("{{ $date_to ?? '' }}") url.searchParams.set('date_to', "{{ $date_to ?? '' }}");
        if ("{{ $search ?? '' }}") url.searchParams.set('search', "{{ $search ?? '' }}");
        if ("{{ $level ?? '' }}") url.searchParams.set('level', "{{ $level ?? '' }}");

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.html) {
                logBody.insertAdjacentHTML('beforeend', data.html);
                currentOffset = data.next_offset;
                logCount += data.count;
                
                if (currentOffset > 0 && data.count > 0) {
                    btnLoadMore.style.display = 'inline-block';
                } else {
                    document.getElementById('load-more-container').innerHTML = '<span style="color: var(--text-dim); font-size: 0.8rem;">Reached start of file.</span>';
                }
            }
            loadingSpinner.style.display = 'none';
        })
        .catch(error => {
            console.error('Error loading logs:', error);
            loadingSpinner.style.display = 'none';
            btnLoadMore.style.display = 'inline-block';
        });
    }

    const container = document.querySelector('.content');
    if (container) {
        container.addEventListener('scroll', () => {
            if (container.scrollTop + container.clientHeight >= container.scrollHeight - 100) {
                if (btnLoadMore && btnLoadMore.style.display !== 'none') {
                    loadMore();
                }
            }
        });
    }
</script>
@endsection
