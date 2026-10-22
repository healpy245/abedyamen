<div class="stories">
    @foreach($products as $id => $product)
        <section id="product-{{ $id }}" class="story feat-reveal" data-feat-chapter="{{ $id }}">
            <div class="story__top">
                <div class="story__copy">
                    <p class="story__kicker">{{ $product['index'] }} · {{ $product['role'] }}</p>
                    <h2>{{ $product['name'] }}</h2>
                    <p class="story__lead">{{ $product['lead'] }}</p>
                    @if(!empty($product['platforms']))
                        <p class="story__platforms">{{ implode(' · ', $product['platforms']) }}</p>
                    @endif
                </div>
            </div>

            @php
                $editions = array_values(array_filter($product['items'], fn ($item) => ! empty($item['edition'])));
                $features = array_values(array_filter($product['items'], fn ($item) => empty($item['edition'])));
            @endphp

            @if($editions !== [])
                <ul class="story__editions">
                    @foreach($editions as $item)
                        <li @class(['edition', 'is-main' => ($item['edition'] ?? '') === 'main'])>
                            <span class="edition__mark">
                                <x-features.icon :name="$item['icon']" />
                            </span>
                            @if(! empty($item['label']))
                                <p class="edition__label">{{ $item['label'] }}</p>
                            @endif
                            <h3>{{ $item['title'] }}</h3>
                            <p>{{ $item['body'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif

            <ul class="story__list">
                @foreach($features as $item)
                    <li @class(['story__row', 'is-wide' => !empty($item['wide'])])>
                        @if(!empty($item['logo']))
                            <img class="story__logo" src="{{ asset($item['logo']) }}" alt="HAAT">
                        @else
                            <span class="story__mark">
                                <x-features.icon :name="$item['icon']" />
                            </span>
                        @endif
                        <div>
                            <h3>{{ $item['title'] }}</h3>
                            <p>{{ $item['body'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
