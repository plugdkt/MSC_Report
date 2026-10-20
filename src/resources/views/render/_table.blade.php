<table class="num-table">
    <thead><tr>@foreach ($d['columns'] as $c)<th>{{ $c['label'] }}{{ $c['unit'] ? ' ('.$c['unit'].')' : '' }}</th>@endforeach</tr></thead>
    <tbody>
        @foreach ($d['rows'] as $i => $row)
            <tr @class(['total' => ($d['has_total'] ?? false) && $i === count($d['rows']) - 1])>
                @foreach ($row as $j => $cell)
                    @if ($j === 0 && ($d['columns'][0]['unit'] ?? null) === null)<td>{{ $cell }}</td>
                    @else<td class="n">{{ $cell !== null ? number_format($cell, ($d['columns'][$j]['unit'] ?? null) === '%' ? 1 : 2) : '—' }}</td>@endif
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
