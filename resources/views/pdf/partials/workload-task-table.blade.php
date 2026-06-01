<table class="list-table {{ $className ?? '' }}">
    @foreach($tasks as $task)
        <tr>
            <td class="list-text">
                <div>{{ $task['title'] }}</div>
                @if(($task['workTypeLabel'] ?? '') !== '')
                    <div class="muted">{{ $task['workTypeLabel'] }}</div>
                @endif
                @if(($task['meta'] ?? '') !== '')
                    <div class="muted">{{ $task['meta'] }}</div>
                @endif
            </td>
            <td class="list-badge-cell">
                <span class="badge {{ $task['statusTone'] }}">{{ $task['statusText'] }}</span>
            </td>
        </tr>
    @endforeach
</table>
