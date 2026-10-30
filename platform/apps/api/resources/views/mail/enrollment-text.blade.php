@if ($mail->greeting){!! $mail->greeting !!}

@endif
{!! $mail->heading !!}

@foreach ($mail->paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@if ($mail->callout)
{!! $mail->callout['title'] !!}: {!! $mail->callout['text'] !!}

@endif
@foreach ($mail->facts as $fact)
{!! $fact['label'] !!}: {!! $fact['value'] !!}
@if (! empty($fact['hint']))
  {!! $fact['hint'] !!}
@endif
@endforeach
@if ($mail->facts !== [])

@endif
@if ($mail->quote)
{!! $mail->quote['label'] !!}: {!! $mail->quote['text'] !!}

@endif
@if ($mail->button)
{!! $mail->button['label'] !!}: {!! $mail->button['url'] !!}

@endif
@foreach ($mail->after as $line)
{!! $line !!}
@endforeach

--
{!! $mail->footer !!}
