@foreach($logs as $index => $log)
    @php $actualIndex = ($start_index ?? 0) + $loop->index; @endphp
    <tr class="log-entry" onclick="toggleStack('stack-{{ $actualIndex }}')">
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
                <div id="stack-{{ $actualIndex }}" class="stack-trace">{{ $log['stack'] }}</div>
            @endif
        </td>
    </tr>
@endforeach
