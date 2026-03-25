<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class LogViewerController extends Controller
{
    public function index()
    {
        if (session('log_viewer_authenticated')) {
            return redirect()->route('logs.show');
        }
        return view('logs_login');
    }

    public function login(Request $request)
    {
        $password = env('LOG_VIEWER_PASSWORD', 'admin123');
        
        if ($request->password === $password) {
            session(['log_viewer_authenticated' => true]);
            return redirect()->route('logs.show');
        }

        return back()->with('error', 'Invalid password.');
    }

    public function logout()
    {
        session()->forget('log_viewer_authenticated');
        return redirect()->route('logs.index');
    }

    public function show(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            return view('logs', ['logs' => [], 'error' => 'Log file not found.']);
        }

        $search = $request->get('search');
        $level = $request->get('level');

        // To keep it lightweight, we read last 1000 lines
        $lines = $this->tailCustom($logPath, 1000);
        
        $entries = [];
        $currentEntry = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)/', $line, $matches)) {
                if ($currentEntry) {
                    if ($this->shouldInclude($currentEntry, $search, $level)) {
                        $entries[] = $currentEntry;
                    }
                }
                $currentEntry = [
                    'date' => $matches[1],
                    'env' => $matches[2],
                    'level' => $matches[3],
                    'message' => $matches[4],
                    'stack' => ''
                ];
            } else {
                if ($currentEntry) {
                    $currentEntry['stack'] .= $line . "\n";
                }
            }
        }

        if ($currentEntry && $this->shouldInclude($currentEntry, $search, $level)) {
            $entries[] = $currentEntry;
        }

        // Reverse to show newest first
        $entries = array_reverse($entries);

        return view('logs', [
            'logs' => $entries,
            'search' => $search,
            'level' => $level
        ]);
    }

    protected function shouldInclude($entry, $search, $level)
    {
        if ($level && strtolower($entry['level']) !== strtolower($level)) {
            return false;
        }

        if ($search) {
            $search = strtolower($search);
            if (strpos(strtolower($entry['message']), $search) === false && 
                strpos(strtolower($entry['stack']), $search) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Efficiently read the last N lines of a file.
     */
    protected function tailCustom($filepath, $lines = 100)
    {
        $f = fopen($filepath, "rb");
        if ($f === false) return [];

        // Jump to ten characters before the end of the file
        fseek($f, -1, SEEK_END);

        $lineCount = 0;
        $output = '';
        $chunk = '';

        // Read backwards
        while (ftell($f) > 0 && $lineCount < $lines) {
            $char = fread($f, 1);
            if ($char === "\n") {
                $lineCount++;
            }
            $output = $char . $output;
            fseek($f, -2, SEEK_CUR);
        }

        fclose($f);
        return explode("\n", trim($output));
    }
}
