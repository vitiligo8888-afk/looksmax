{{--
  404. Core's version prints the raw exception message ("/some/path") followed
  by a return link, which tells a visitor nothing and hands a crawler the path
  it already knows is broken.

  The art is the mark with its blade cut short: the measurement does not reach.
  Inlined rather than linked so the page renders even if the assets filesystem
  is the thing that is broken.
--}}
@extends('flarum.forum::layouts.basic')

@section('title', app('translator')->trans('local-looksmax-brand.forum.error.not_found.title'))

@section('content')
  <div class="art">
    <svg viewBox="0 0 160 80" fill="none" aria-hidden="true"><g opacity=".7"><path fill-rule="evenodd" fill="currentColor" transform="translate(8 4) scale(2.1)" d="M4 28V8l4-4h4v18h12l4 4v2zM14 22h2v4h-2zM18 22h2v2h-2zM22 22h2v4h-2z"/></g><path d="M84 57h68" stroke="currentColor" stroke-width="5" stroke-dasharray="5 13" stroke-linecap="butt" opacity=".45"/></svg>
  </div>

  <h1>{{ app('translator')->trans('local-looksmax-brand.forum.error.not_found.heading') }}</h1>
  <p>
    {{ app('translator')->trans('local-looksmax-brand.forum.error.not_found.body') }}
  </p>
  <p>
    <a class="button" href="{{ $url->to('forum')->base() }}">{{ app('translator')->trans('local-looksmax-brand.forum.error.go_home') }}</a>
  </p>
@endsection
