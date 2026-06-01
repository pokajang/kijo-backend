<table class="score-table {{ $className ?? '' }}">
    @if($showHeader ?? true)
        <thead>
            <tr>
                <th>Item</th>
                <th>Calculation</th>
                <th class="points">Points</th>
            </tr>
        </thead>
    @endif
    <tbody>
        @foreach($rows as $line)
            @if($line['type'] === 'section')
                <tr class="section-row">
                    <th colspan="2">{{ $line['item'] }}</th>
                    <td class="points">{{ $line['points'] }}</td>
                </tr>
            @elseif($line['type'] === 'total')
                <tr class="total-row">
                    <th colspan="2">{{ $line['item'] }}</th>
                    <td class="points">{{ $line['points'] }}</td>
                </tr>
            @elseif($line['type'] === 'empty')
                <tr>
                    <td colspan="3" class="empty">{{ $line['item'] }}</td>
                </tr>
            @else
                <tr>
                    <td>
                        <div class="score-item">{{ $line['item'] }}</div>
                        @if(($line['detail'] ?? '') !== '')
                            <div class="score-detail">{{ $line['detail'] }}</div>
                        @endif
                    </td>
                    <td class="score-detail">{{ $line['calculation'] ?? '' }}</td>
                    <td class="points"><strong>{{ $line['points'] }}</strong></td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
