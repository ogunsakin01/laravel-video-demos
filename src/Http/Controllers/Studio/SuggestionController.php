<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Ai\Suggester;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Throwable;

/** "Ideas": videos the AI suggests making first, from the app's routes */
class SuggestionController extends Controller
{
    public function index(Suggester $suggester): View
    {
        return view('video-demo::studio.suggestions', [
            'latest' => $suggester->latest(),
            'styles' => VideoStyle::options()->keyBy('key'),
            'models' => Pricing::options(),
            'model' => config('video-demo.ai.model'),
            'hasKey' => filled(config('video-demo.ai.api_key')),
        ]);
    }

    public function store(Request $request, Suggester $suggester): RedirectResponse
    {
        $model = $request->validate([
            'model' => ['nullable', Rule::in(array_keys(config('video-demo.ai.models')))],
        ])['model'] ?? null;

        try {
            $suggester->suggest($model);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not get suggestions: '.$e->getMessage());
        }

        return redirect()->route('video-demo.studio.suggestions.index');
    }
}
