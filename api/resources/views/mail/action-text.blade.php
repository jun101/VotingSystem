{!! $title !!}
{!! $line !!}

{!! __('mail.greeting') !!}

{!! $intro !!}

@isset($code)
{!! $codeLabel !!} {!! $code !!}

@endisset
@isset($details)
@foreach ($details as $detail)
{!! $detail[0] !!} {!! $detail[1] !!}
@endforeach

@endisset
{!! $button !!}
{!! $url !!}

{!! $validity !!}
{!! $ignore !!}

{!! __('mail.footer') !!}
