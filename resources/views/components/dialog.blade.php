@props(['id', 'title', 'action', 'submit' => 'Save', 'redirect' => null])
<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title">
    <form action="{{ $action }}" method="post" data-api-form @if($redirect) data-redirect="{{ $redirect }}" @endif>
        @csrf
        <div class="dialog-heading"><h2 id="{{ $id }}-title">{{ $title }}</h2><button type="button" data-close-dialog class="icon-button" aria-label="Close"><i data-lucide="x"></i></button></div>
        <div class="dialog-body"><div class="form-error" role="alert" tabindex="-1" hidden></div>{{ $slot }}</div>
        <div class="dialog-footer"><button type="button" class="button secondary" data-close-dialog>Cancel</button><button type="submit" class="button primary">{{ $submit }}</button></div>
    </form>
</dialog>
