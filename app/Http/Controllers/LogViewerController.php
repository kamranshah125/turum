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
        $dateFrom = $request->get('date_from'); // YYYY-MM-DD
        $dateTo = $request->get('date_to'); // YYYY-MM-DD
        $offset = $request->get('offset');

        if ($offset === null) {
            $offset = filesize($logPath);
        } else {
            $offset = (int)$offset;
        }

        $limit = 100;
        $results = $this->readBackwards($logPath, $offset, $limit, $search, $level, $dateFrom, $dateTo);
        
        $entries = $results['entries'];
        $nextOffset = $results['next_offset'];

        if ($request->ajax()) {
            return response()->json([
                'html' => view('partials.log_entries', ['logs' => $entries, 'start_index' => $request->get('count', 0)])->render(),
                'next_offset' => $nextOffset,
                'has_more' => $nextOffset > 0,
                'count' => count($entries)
            ]);
        }

        return view('logs', [
            'logs' => $entries,
            'search' => $search,
            'level' => $level,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'next_offset' => $nextOffset
        ]);
    }

    protected function readBackwards($filepath, $startOffset, $limit, $search, $level, $dateFrom, $dateTo)
    {
        $f = fopen($filepath, "rb");
        if ($f === false) return ['entries' => [], 'next_offset' => 0];

        fseek($f, $startOffset);

        $entries = [];
        $buffer = "";
        $pos = $startOffset;
        $stackBuffer = [];

        while ($pos > 0 && count($entries) < $limit) {
            $readSize = min(32768, $pos);
            $pos -= $readSize;
            fseek($f, $pos);
            $chunk = fread($f, $readSize);
            
            $combined = $chunk . $buffer;
            $lines = explode("\n", $combined);
            $buffer = array_shift($lines);
            
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = $lines[$i];
                if (empty(trim($line)) && empty($stackBuffer)) continue;

                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)/', $line, $matches)) {
                    $entryDateOnly = $matches[1];
                    $entryFullDate = $entryDateOnly . ' ' . $matches[2];

                    // Early Exit Optimization: Since we read backwards, if entryDate is BEFORE dateFrom,
                    // we can stop reading the whole file.
                    if ($dateFrom && $entryDateOnly < $dateFrom) {
                        $pos = 0; // Stop outer while loop
                        break 2; // Break inner for loop
                    }

                    $tempEntry = [
                        'date' => $entryFullDate,
                        'env' => $matches[3],
                        'level' => $matches[4],
                        'message' => $matches[5],
                        'stack' => implode("\n", array_reverse($stackBuffer))
                    ];

                    $stackBuffer = [];

                    if ($this->shouldInclude($tempEntry, $search, $level, $dateFrom, $dateTo)) {
                        $entries[] = $tempEntry;
                        if (count($entries) >= $limit) break 2;
                    }
                } else {
                    $stackBuffer[] = $line;
                }
            }
        }

        fclose($f);

        return [
            'entries' => $entries,
            'next_offset' => $pos
        ];
    }

    protected function shouldInclude($entry, $search, $level, $dateFrom, $dateTo)
    {
        $entryDateOnly = substr($entry['date'], 0, 10);

        if ($dateFrom && $entryDateOnly < $dateFrom) {
            return false;
        }

        if ($dateTo && $entryDateOnly > $dateTo) {
            return false;
        }

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
}
