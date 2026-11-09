@props(['tone' => null, 'soft' => false, 'plain' => false, 'dot' => true])
{{--
    Status text with a small dot. Colour is only for states that need attention:
    red fills the badge; green/amber/blue only colour the dot unless soft is set.
--}}
<span {{ $attributes->class(['ds-badge', 'ds-badge--soft' => $soft, 'ds-badge--plain' => $plain, 'ds-badge--nodot' => ! $dot]) }}
      @if($tone) data-tone="{{ $tone }}" @endif>{{ $slot }}</span>
