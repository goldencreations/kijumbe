@extends('admin.layout')
@section('content')
<a class="back-link" href="{{ route('admin.list', 'whatsapp') }}"><i data-lucide="arrow-left"></i>Message history</a>
<div class="page-heading">
    <div><span class="eyebrow">WHATSAPP AUTOMATION</span><h1>Message templates</h1><p>Edit automatic financial notifications while keeping their live data placeholders intact.</p></div>
</div>
<div class="template-list">
    @foreach($templates->groupBy('category') as $category => $categoryTemplates)
        <div class="template-section-heading"><span>{{ $category }}</span><small>{{ $categoryTemplates->count() }} templates</small></div>
        @foreach($categoryTemplates as $template)
        <article class="template-item">
            <div class="template-heading">
                <div><h2>{{ $template['name'] }}</h2><p>{{ $template['description'] }}</p></div>
                <div class="heading-actions"><span class="badge {{ $template['is_custom'] ? 'badge-active' : '' }}"><span></span>{{ $template['is_custom'] ? 'Customized' : 'Default' }}</span><button class="button secondary" data-open-dialog="edit-template-{{ $template['key'] }}"><i data-lucide="settings-2"></i>Edit</button>@if($template['is_custom'])<button class="icon-button" data-open-dialog="reset-template-{{ $template['key'] }}" title="Restore default" aria-label="Restore {{ $template['name'] }} default"><i data-lucide="rotate-ccw"></i></button>@endif</div>
            </div>
            <pre class="template-body">{{ $template['body'] }}</pre>
            <div class="template-meta"><span>Live variables</span><div>@forelse($template['variables'] as $variable)<code>{<span>{{ $variable }}</span>}</code>@empty<small>None</small>@endforelse</div>@if($template['updated_at'])<small>Last changed {{ $template['updated_at']->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i') }} EAT{{ $template['updated_by'] ? ' by '.$template['updated_by'] : '' }}</small>@endif</div>
        </article>

        @push('dialogs')
        <x-dialog :id="'edit-template-'.$template['key']" :title="'Edit '.$template['name']" :action="url('/admin/actions/whatsapp/message-templates/'.$template['key'])" submit="Save template">
            @method('PUT')
            @php($parts = preg_split('/(\{[a-z0-9_]+\})/i', $template['body'], -1, PREG_SPLIT_DELIM_CAPTURE))
            <label class="field">Message body
                <div class="template-editor" data-template-editor contenteditable="false" role="textbox" aria-multiline="true" aria-label="{{ $template['name'] }} message body">
                    @foreach($parts as $part)
                        @if(preg_match('/^\{[a-z0-9_]+\}$/i', $part))
                            <code class="template-token" data-template-token="{{ $part }}" contenteditable="false">{{ $part }}</code>
                        @else
                            <span class="template-editor-text" data-template-text contenteditable="true">{{ $part }}</span>
                        @endif
                    @endforeach
                </div>
                <textarea name="body" data-template-input hidden maxlength="4000" required>{{ $template['body'] }}</textarea>
            </label>
            <p class="field-hint">Values in code blocks are filled automatically when the message is sent.</p>
        </x-dialog>
        @if($template['is_custom'])
        <x-dialog :id="'reset-template-'.$template['key']" :title="'Restore '.$template['name']" :action="url('/admin/actions/whatsapp/message-templates/'.$template['key'])" submit="Restore default">
            @method('DELETE')
            <p class="form-context">This removes the customized text and immediately restores the built-in message.</p>
        </x-dialog>
        @endif
        @endpush
        @endforeach
    @endforeach
</div>
<section class="template-boundary"><i data-lucide="shield-check"></i><div><h2>Interactive replies stay protected</h2><p>Menu prompts and replies such as NDIO, HAPANA, LIPA, and KATISHA remain managed by the bot so editing a notification cannot break a member journey.</p></div></section>
@endsection
