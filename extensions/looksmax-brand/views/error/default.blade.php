{{--
  Everything that is not a 404: 500, 503, 403. Core prints the raw exception
  message, which on a 500 is whatever PHP said and is not something to show a
  visitor.
--}}
@extends('flarum.forum::layouts.basic')

@section('title', app('translator')->trans('local-looksmax-brand.forum.error.default.title'))

@section('content')
  <div class="art">
    <svg viewBox="0 0 160 80" fill="none" aria-hidden="true"><g opacity=".7"><path fill-rule="evenodd" fill="currentColor" transform="translate(8 4) scale(2.1)" d="M4 28V8l4-4h4v18h12l4 4v2zM14 22h2v4h-2zM18 22h2v2h-2zM22 22h2v4h-2z"/></g><path d="M84 57h68" stroke="currentColor" stroke-width="5" stroke-dasharray="5 13" stroke-linecap="butt" opacity=".45"/></svg>
  </div>

  <h1>{{ app('translator')->trans('local-looksmax-brand.forum.error.default.heading') }}</h1>
  {{-- The address is a link, not a word to translate, so it goes in as an ICU
       parameter and the sentence stays whole in both languages — splitting a
       sentence around a tag is how translated text ends up in English word
       order. Unescaped output because the parameter IS markup; both the string
       and the parameter are ours and no user input reaches this line. --}}
  <p>
    {!! app('translator')->trans('local-looksmax-brand.forum.error.default.body', ['email' => '<a href="mailto:admin@looksmax.lat">admin@looksmax.lat</a>']) !!}
  </p>
  <p>
    <a class="button" href="{{ $url->to('forum')->base() }}">{{ app('translator')->trans('local-looksmax-brand.forum.error.go_home') }}</a>
  </p>
@endsection
