<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Fira+Code:wght@400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f1a;
            --sidebar-bg: #111827;
            --card-bg: #1e293b;
            --accent-color: #38bdf8;
            --text-main: #f1f5f9;
            --text-dim: #94a3b8;
            --error: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --debug: #8b5cf6;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        header {
            background: #111827;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            z-index: 10;
        }

        .logo { font-weight: 600; font-size: 1.25rem; color: var(--accent-color); }

        .search-bar {
            display: flex;
            gap: 1rem;
            flex-grow: 1;
            max-width: 600px;
            margin: 0 2rem;
        }

        .search-bar input, .search-bar select {
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.1);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            outline: none;
        }
        
        .search-bar input { flex-grow: 1; }

        .btn-search {
            background: var(--accent-color);
            color: #0f172a;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
        }

        .main-content {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1rem 2rem;
            background-image: linear-gradient(to bottom, transparent, rgba(15, 23, 42, 0.5));
        }

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
        
        .actions { display: flex; gap: 1rem; align-items: center; }
        .btn-logout { color: var(--text-dim); text-decoration: none; font-size: 0.875rem; }
        .btn-logout:hover { color: var(--error); }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }

        @media (max-width: 768px) {
            header { flex-direction: column; gap: 1rem; height: auto; padding: 1rem; }
            .search-bar { margin: 0; width: 100%; }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">LogCenter v1.0</div>
        
        <form action="{{ route('logs.show') }}" method="GET" class="search-bar">
            <input type="text" name="search" placeholder="Search keywords..." value="{{ $search ?? '' }}">
            <select name="level">
                <option value="">All Levels</option>
                <option value="ERROR" {{ ($level ?? '') == 'ERROR' ? 'selected' : '' }}>Error</option>
                <option value="WARNING" {{ ($level ?? '') == 'WARNING' ? 'selected' : '' }}>Warning</option>
                <option value="INFO" {{ ($level ?? '') == 'INFO' ? 'selected' : '' }}>Info</option>
                <option value="DEBUG" {{ ($level ?? '') == 'DEBUG' ? 'selected' : '' }}>Debug</option>
            </select>
            <button type="submit" class="btn-search">Search</button>
        </form>

        <div class="actions">
            <a href="{{ route('logs.show') }}" style="color: var(--accent-color); text-decoration: none;">Refresh</a>
            <a href="{{ route('logs.logout') }}" class="btn-logout">Logout</a>
        </div>
    </header>

    <div class="main-content">
        @if(isset($error))
            <div style="padding: 2rem; text-align: center; color: var(--error);">{{ $error }}</div>
        @elseif(empty($logs))
            <div style="padding: 2rem; text-align: center; color: var(--text-dim);">No logs found matching your criteria.</div>
        @else
            <table class="log-table">
                @foreach($logs as $index => $log)
                    <tr class="log-entry" onclick="toggleStack('stack-{{ $index }}')">
                        <td width="1%">
                            <span class="level-badge level-{{ strtoupper($log['level']) }}">{{ $log['level'] }}</span>
                        </td>
                        <td width="150" class="date">{{ $log['date'] }}</td>
                        <td class="message">
                            <div class="entry-header">
                                <span>{{ Illuminate\Support\Str::limit($log['message'], 150) }}</span>
                                @if(!empty($log['stack']))
                                    <span style="font-size: 0.7rem; color: var(--accent-color);">[+ JSON/Stack]</span>
                                @endif
                            </div>
                            @if(!empty($log['stack']))
                                <div id="stack-{{ $index }}" class="stack-trace">{{ $log['stack'] }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <script>
        function toggleStack(id) {
            const el = document.getElementById(id);
            if (el) {
                el.style.display = el.style.display === 'block' ? 'none' : 'block';
            }
        }
    </script>
</body>
</html>
