<?php

namespace App\Http\Controllers;

use App\Services\PublicLanguage;
use App\Services\StaticPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StaticPageController extends Controller
{
    public function show(string $slug, StaticPages $pages): View
    {
        $page = $pages->published()[$slug] ?? null;
        abort_unless($page, 404);
        $originalContent = $page['content'];
        foreach (['title', 'summary', 'content'] as $field) {
            $page[$field] = PublicLanguage::text($page[$field]);
        }
        $contentLanguage = app()->getLocale() === 'hi' && $page['content'] === $originalContent ? 'en' : app()->getLocale();
        $html = Str::markdown($page['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return view('static-page', compact('page', 'slug', 'html', 'contentLanguage'));
    }

    public function index(Request $request, StaticPages $pages): View
    {
        $request->validate(['edit' => 'nullable|string|max:100']);
        $all = $pages->all();
        $slug = $request->string('edit')->toString();
        $page = $all[$slug] ?? null;
        abort_if($slug !== '' && ! $page, 404);

        return view('static-pages-admin', compact('all', 'slug', 'page'));
    }

    public function save(Request $request, StaticPages $pages): RedirectResponse
    {
        $data = $request->validate(['slug' => ['required', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'title' => 'required|string|max:160', 'summary' => 'required|string|max:320', 'content' => 'required|string|max:50000', 'published' => 'required|boolean', 'order' => 'required|integer|min:0|max:9999', 'expected' => 'required|string|size:64']);
        $pages->save($data['slug'], ['title' => $data['title'], 'summary' => $data['summary'], 'content' => $data['content'], 'published' => (bool) $data['published'], 'order' => (int) $data['order']], $data['expected']);

        return redirect()->route('static-pages.index', ['edit' => $data['slug']])->with('status', 'Page saved. Published pages automatically appear in the footer.');
    }
}
