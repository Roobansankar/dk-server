{{-- One gender column inside the brochure's Men | Women split. Needs: $block (groups, optional label), $inr. --}}
@if (! empty($block['label']))
<h3 class="cat" style="margin-top: 14px;">{{ $block['label'] }}</h3>
@endif
@foreach ($block['groups'] as $group)
    <p class="group-name">{{ $group['category'] }}</p>
    <table class="items">
        <thead>
            <tr><th>Service</th><th class="num">Price</th></tr>
        </thead>
        <tbody>
            @foreach ($group['services'] as $i => $service)
                <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                    <td class="item-name">{{ $service['name'] }}</td>
                    <td class="num">{!! $inr($service['price']) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach
