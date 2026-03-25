<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --accent-color: #38bdf8;
            --text-color: #f8fafc;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background-image: radial-gradient(circle at top right, #1e293b, transparent), 
                              radial-gradient(circle at bottom left, #0f172a, transparent);
        }

        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            padding: 2.5rem;
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        h2 { margin-top: 0; color: var(--accent-color); font-weight: 600; }
        
        input {
            width: 100%;
            padding: 0.75rem;
            margin: 1rem 0;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            color: white;
            box-sizing: border-box;
            outline: none;
        }

        input:focus { border-color: var(--accent-color); }

        button {
            width: 100%;
            padding: 0.75rem;
            background: var(--accent-color);
            border: none;
            border-radius: 0.5rem;
            color: #0f172a;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        button:hover { opacity: 0.9; transform: translateY(-1px); }

        .error { color: #f87171; font-size: 0.875rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Log Viewer</h2>
        <p>Enter password to access system logs.</p>
        
        @if(session('error'))
            <div class="error">{{ session('error') }}</div>
        @endif

        <form action="{{ route('logs.login') }}" method="POST">
            @csrf
            <input type="password" name="password" placeholder="Password" required autofocus>
            <button type="submit">Unlock Logs</button>
        </form>
    </div>
</body>
</html>
