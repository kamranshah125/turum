<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Turum Developer')</title>
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
            --success: #10b981;
            --info: #3b82f6;
            --border: rgba(255,255,255,0.05);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 2rem;
            font-weight: 600;
            font-size: 1.25rem;
            color: var(--accent-color);
            border-bottom: 1px solid var(--border);
        }

        .nav-links {
            padding: 1.5rem 1rem;
            flex-grow: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--text-dim);
            text-decoration: none;
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s;
        }

        .nav-item:hover, .nav-item.active {
            background: rgba(56, 189, 248, 0.1);
            color: var(--accent-color);
        }

        .main-wrapper {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        header {
            background: #111827;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            z-index: 10;
        }

        .content {
            flex-grow: 1;
            overflow-y: auto;
            padding: 2rem;
        }

        .btn-logout {
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .btn-logout:hover { color: var(--error); }

        /* Shared Dashboard Components */
        .card {
            background: var(--card-bg);
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .search-bar {
            display: flex;
            gap: 1rem;
            background: #111827;
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--border);
            align-items: center;
        }

        .search-bar input, .search-bar select {
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.1);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            outline: none;
        }

        .btn-search {
            background: var(--accent-color);
            color: #0f172a;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
        }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    </style>
    @yield('styles')
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">Turum Developer</div>
        <nav class="nav-links">
            <a href="{{ route('logs.show') }}" class="nav-item {{ request()->routeIs('logs.*') ? 'active' : '' }}">
                System Logs
            </a>
            <a href="{{ route('orders.index') }}" class="nav-item {{ request()->routeIs('orders.*') ? 'active' : '' }}">
                Orders List
            </a>
        </nav>
        <div style="padding: 1rem; border-top: 1px solid var(--border);">
            <a href="{{ route('logs.logout') }}" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="main-wrapper">
        <header>
            <div style="font-weight: 500;">@yield('page_title')</div>
            <div class="actions">
                @yield('header_actions')
            </div>
        </header>

        @yield('search_bar')

        <div class="content">
            @yield('content')
        </div>
    </div>

    @yield('scripts')
</body>
</html>
