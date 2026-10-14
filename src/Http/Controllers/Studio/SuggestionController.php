<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Ai\Suggester;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Throwable;

/**
 * Shows the Ideas page: videos the AI model suggests making first, based on
 * the app's routes.
 */
class SuggestionController extends Controller
{
    /**
     * Display the suggested videos.
     */
    public function index(): View
    {
        $ideas = VideoIdea::with('video')->orderByRaw('video_id is not null')->latest('id')->get();

        return view('video-demo::studio.suggestions', [
            'ideas' => $ideas,
            'spent' => (float) $ideas->sum('cost'),
            'latestAt' => $ideas->max('created_at'),
            'styles' => VideoStyle::options()->keyBy('key'),
            'models' => Pricing::options(),
            'model' => Pricing::defaultModel(),
            'hasKey' => Drivers::anyKey(),
        ]);
    }

    /**
     * Ask the AI model for new suggestions.
     */
    public function store(Request $request, Suggester $suggester): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', Rule::in(array_keys(config('video-demo.ai.models')))],
            'replace' => ['nullable', 'boolean'],
        ]);

        try {
            $suggester->suggest($data['model'] ?? null, (bool) ($data['replace'] ?? false));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not get suggestions: '.$e->getMessage());
        }

        return redirect()->route('video-demo.studio.suggestions.index');
    }

    /**
     * Remove the given idea.
     */
    public function destroy(VideoIdea $idea): RedirectResponse
    {
        $idea->delete();

        return back();
    }
}
