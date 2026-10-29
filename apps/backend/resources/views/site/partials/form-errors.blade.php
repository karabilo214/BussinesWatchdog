@if ($errors->any())
    <div class="site-errors" role="alert" tabindex="-1" autofocus id="form-errors" aria-labelledby="form-errors-title">
        <p id="form-errors-title">{{ __('site.forms.fix_errors') }}</p>
        <ul>
            @foreach ($errors->messages() as $field => $messages)
                @foreach ($messages as $message)
                    <li>@if (isset($errorFields[$field]))<a href="#{{ $errorFields[$field] }}">{!! nl2br(e($message)) !!}</a>@else{!! nl2br(e($message)) !!}@endif</li>
                @endforeach
            @endforeach
        </ul>
        @if (($attachmentAgain ?? false) && old('name') !== null)
            <p>{{ __('site.contact.attachment_again') }}</p>
        @endif
    </div>
@endif
