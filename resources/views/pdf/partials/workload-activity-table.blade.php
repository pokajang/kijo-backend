<table class="list-table {{ $className ?? '' }}">
    @foreach($activities as $activity)
        <tr>
            <td class="list-text">
                {{ $activity['text'] }}
                <span class="muted">{{ $activity['lapsed'] }} lapsed</span>
            </td>
            <td class="list-badge-cell">
                @if($activity['badgeText'] !== '')
                    <span class="badge {{ $activity['badgeTone'] }}">{{ $activity['badgeText'] }}</span>
                @endif
            </td>
        </tr>
    @endforeach
</table>
