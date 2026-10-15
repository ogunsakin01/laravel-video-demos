<?php

namespace Workbench\App;

use Ogunsakin01\VideoDemo\Ai\Claude;
use RuntimeException;

/**
 * Stands in for Claude in the demo app when no ANTHROPIC_API_KEY is set, so
 * the tours can be recorded at no cost. "Complete with AI", "Improve with AI"
 * and "Suggest videos" get canned replies; generating a video needs a real key.
 */
class DemoClaude extends Claude
{
    public function __construct() {}

    /**
     * Answer form completion, suggestion and voice-over requests with canned replies.
     */
    public function create(array $params): object
    {
        if (! isset($params['outputConfig']['format'])) {
            throw new RuntimeException('The demo app has no ANTHROPIC_API_KEY, so it cannot generate videos.');
        }

        usleep(900_000);

        // "Improve with AI" asks for the changes it made.
        if (isset($params['outputConfig']['format']['schema']['properties']['changes'])) {
            return $this->reply($params['model'], [
                'prompt' => 'How a recruiter posts a new job: open Post a job from the top menu, fill in the title, contract and location, and publish it. It ends on the live listing.',
                'start_path' => '/jobs/create',
                'target_seconds' => 45,
                'changes' => [
                    'Starts on Post a job, so the video skips the dashboard.',
                    'Names the fields to fill in, so every step is shown.',
                    'Ends on the live listing, so viewers see the result.',
                ],
            ]);
        }

        // A voice-over for a plan: lines the way a person talks you through it, in the video's language.
        if (isset($params['outputConfig']['format']['schema']['properties']['lines'])) {
            if (str_contains(json_encode($params['system'] ?? ''), 'Language: French')) {
                return $this->reply($params['model'], ['lines' => [
                    ['step' => 1, 'say' => 'Votre offre sera en ligne en moins d’une minute.'],
                    ['step' => 3, 'say' => 'Tout part du menu en haut.'],
                    ['step' => 5, 'say' => 'Utilisez les mots que les candidats recherchent vraiment.'],
                    ['step' => 7, 'say' => 'En télétravail, elle s’ouvre à tout le monde.'],
                    ['step' => 10, 'say' => 'Et voilà, elle est publiée.'],
                ]]);
            }

            return $this->reply($params['model'], ['lines' => [
                ['step' => 1, 'say' => "You'll have a job listed in under a minute."],
                ['step' => 3, 'say' => 'It starts from the top menu.'],
                ['step' => 5, 'say' => 'Use the words people actually search for.'],
                ['step' => 7, 'say' => 'Remote opens it up to anyone.'],
                ['step' => 10, 'say' => "And that's it, it's out."],
                ['step' => 13, 'say' => 'Easy as that.'],
            ]]);
        }

        // "Where to put it" picks sections from the pages it is shown: here, the first panels with a heading.
        if (isset($params['outputConfig']['format']['schema']['properties']['spots'])) {
            $content = $params['messages'][0]['content'] ?? '';
            $prompt = is_string($content) ? $content : implode("\n", array_column(array_map(fn ($b) => (array) $b, (array) $content), 'text'));
            preg_match_all('~<page path="([^"]+)"[^>]*>(.*?)</page>~s', $prompt, $pages, PREG_SET_ORDER);
            $spots = [];
            foreach ($pages as [, $path, $body]) {
                // A form or panel when the page has one, else its main content.
                preg_match_all('~\[(\d+)\] (panel|form|section|aside|empty state) "([^"]+)"~', $body, $sections, PREG_SET_ORDER);
                $sections = $sections ?: (preg_match_all('~\[(\d+)\] (main) "([^"]+)"~', $body, $main, PREG_SET_ORDER) ? $main : []);
                foreach (array_slice($sections, 0, 1) as [, $id, $kind, $heading]) {
                    $spots[] = ['path' => $path, 'section' => (int) $id, 'position' => $kind === 'aside' ? 'top' : 'before',
                        'label' => 'The “'.$heading.'” '.($kind === 'form' ? 'form' : 'section'), 'why' => 'People who reach this point are about to do what the video shows.'];
                }
            }

            return $this->reply($params['model'], ['spots' => array_slice($spots, 0, 3)]);
        }

        // "Suggest videos" asks for a list.
        if (isset($params['outputConfig']['format']['schema']['properties']['suggestions'])) {
            // "Suggest more" lists the ideas already kept; answer with new ones.
            if (str_contains(json_encode($params['messages']), 'ideas_already_suggested')) {
                return $this->reply($params['model'], ['suggestions' => [
                    ['title' => 'Invite your hiring team', 'prompt' => 'How an admin invites colleagues to review applicants together.', 'why' => 'Hiring is a team effort.', 'start_path' => '/', 'target_seconds' => 45, 'style' => 'quick'],
                    ['title' => 'Move an applicant to interview', 'prompt' => 'How a recruiter moves an applicant to the interview stage and leaves a note.', 'why' => 'The most common daily task.', 'start_path' => '/', 'target_seconds' => 60, 'style' => 'clean'],
                ]]);
            }

            return $this->reply($params['model'], ['suggestions' => [
                ['title' => 'Post your first job', 'prompt' => 'How a recruiter posts a new job, from the jobs page to a live listing.', 'why' => 'It is the first thing every new customer does.', 'start_path' => '/', 'target_seconds' => 60, 'style' => 'clean'],
                ['title' => 'Find people with the right skills', 'prompt' => 'How to search people by skill and location and save a shortlist.', 'why' => 'Search is the main reason people come back.', 'start_path' => '/', 'target_seconds' => 75, 'style' => 'detailed'],
                ['title' => 'Get help without leaving the app', 'prompt' => 'How to find a how-to video from the help library and play it.', 'why' => 'Shows users where answers live.', 'start_path' => '/help/videos', 'target_seconds' => 30, 'style' => 'quick'],
                ['title' => 'Hiring, made simple', 'prompt' => 'A short ad: post a job, watch applicants arrive, pick one.', 'why' => 'For the website and social posts.', 'start_path' => '/', 'target_seconds' => 30, 'style' => 'ad'],
            ]]);
        }

        return (object) [
            'model' => $params['model'],
            'stopReason' => 'end_turn',
            'content' => [(object) ['type' => 'text', 'text' => json_encode([
                'title' => 'Approve a holiday request',
                'prompt' => 'How a team manager approves a holiday request: open the request from the Time off inbox, check the dates against the team calendar, approve it, and see the confirmation the employee receives.',
                'start_path' => '/time-off',
                'target_seconds' => 75,
            ])]],
            'usage' => (object) ['inputTokens' => 2_140, 'outputTokens' => 260, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0],
        ];
    }

    /**
     * Build a text reply holding the given data as JSON.
     */
    protected function reply(string $model, array $data): object
    {
        return (object) [
            'model' => $model,
            'stopReason' => 'end_turn',
            'content' => [(object) ['type' => 'text', 'text' => json_encode($data)]],
            'usage' => (object) ['inputTokens' => 2_900, 'outputTokens' => 640, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0],
        ];
    }
}
