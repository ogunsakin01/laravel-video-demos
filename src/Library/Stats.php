<?php

namespace Ogunsakin01\VideoDemo\Library;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * How the published videos are watched, when the app turns it on
 * (video-demo.library.stats): plays, how far viewers get, and searches in
 * the library that found nothing. Only daily counts are kept, with no
 * cookies, IP addresses or user ids. The studio reads them from production
 * with the same token as the requests.
 */
class Stats
{
    /**
     * What the player reports: a play, and each quarter of the video reached.
     */
    public const EVENTS = ['play', '25', '50', '75', '100'];

    protected const TABLE = 'video_demo_stats';

    /**
     * Determine if the app counts how its videos are watched.
     */
    public static function enabled(): bool
    {
        return (bool) config('video-demo.library.stats');
    }

    /**
     * Count a play, or a quarter of a video reached.
     */
    public function record(string $slug, string $event): void
    {
        $this->increment(Str::limit($slug, 180, ''), $event, '');
    }

    /**
     * Count a library search that found no video, by its words.
     */
    public function searchMiss(string $query): void
    {
        $query = Str::limit(Str::lower(Str::squish($query)), 180, '');
        if ($query !== '') {
            $this->increment('', 'search', $query);
        }
    }

    /**
     * Get the counts of the last $days days: for each video its plays and
     * how many reached each quarter, and the searches that found nothing,
     * most asked first.
     *
     * @return array{days: int, videos: array<string, array<int|string, int>>, searches: list<array{query: string, count: int}>}
     */
    public function summary(int $days = 90): array
    {
        $since = now()->subDays($days)->toDateString();
        $videos = [];
        $rows = DB::table(self::TABLE)->where('day', '>=', $since)->where('event', '!=', 'search')
            ->selectRaw('slug, event, sum(hits) as total')->groupBy('slug', 'event')->get();
        foreach ($rows as $row) {
            $videos[$row->slug] ??= array_fill_keys(self::EVENTS, 0);
            $videos[$row->slug][$row->event] = (int) $row->total;
        }

        $searches = DB::table(self::TABLE)->where('day', '>=', $since)->where('event', 'search')
            ->selectRaw('term, sum(hits) as total')->groupBy('term')->orderByDesc('total')->limit(50)->get()
            ->map(fn ($r) => ['query' => (string) $r->term, 'count' => (int) $r->total])->all();

        return ['days' => $days, 'videos' => $videos, 'searches' => $searches];
    }

    /**
     * Get the counts where the studio runs: from production when it is set
     * up (video-demo.studio.production_url and the requests token), else
     * from this app.
     *
     * The result has the source, and either an error or the days, videos
     * and searches of summary(), and from production whether it counts.
     *
     * @return array<string, mixed>
     */
    public function fetch(int $days = 90): array
    {
        $production = config('video-demo.studio.production_url');
        $token = config('video-demo.library.requests_token');

        if ($production && $token) {
            try {
                $response = Http::withToken($token)->acceptJson()->timeout(10)
                    ->get(rtrim($production, '/').'/'.trim(config('video-demo.library.private.prefix'), '/').'/api/stats', ['days' => $days]);
            } catch (Throwable $e) {
                return ['source' => $production, 'error' => 'Production could not be reached: '.$e->getMessage()];
            }

            return $response->successful()
                ? ['source' => $production, ...$response->json()]
                : ['source' => $production, 'error' => "Production answered with HTTP {$response->status()}."];
        }

        return ['source' => 'local', ...$this->summary($days)];
    }

    /**
     * Describe where viewers stop watching a video: the quarter most of
     * them leave in, and the chapter there. Null with too few plays to say.
     *
     * @param  array<int|string, int>  $counts  A video's counts from summary(), by event.
     * @param  list<array{title: string, at: float}>  $chapters
     */
    public static function dropOff(array $counts, float $seconds, array $chapters = []): ?string
    {
        $plays = $counts['play'] ?? 0;
        if ($plays < 5) {
            return null;
        }

        // Viewers lost in each quarter: before 25%, 25 to 50%, and so on.
        // Keys such as "25" are integers once in a PHP array.
        $reached = [0 => $plays, 25 => $counts[25] ?? 0, 50 => $counts[50] ?? 0, 75 => $counts[75] ?? 0, 100 => $counts[100] ?? 0];
        $lost = [];
        $marks = array_keys($reached);
        for ($i = 0; $i < 4; $i++) {
            $lost[$marks[$i]] = max(0, $reached[$marks[$i]] - $reached[$marks[$i + 1]]);
        }
        arsort($lost);
        $from = (int) array_key_first($lost);
        if ($lost[$from] === 0) {
            return null;
        }

        $at = $seconds * ($from + 12.5) / 100;
        $chapter = null;
        foreach ($chapters as $c) {
            if ((float) $c['at'] <= $at) {
                $chapter = $c['title'];
            }
        }

        $at = (int) round($at);

        return sprintf('Most who stop do so between %d%% and %d%%, around %d:%02d%s.', $from, $from + 25, intdiv($at, 60), $at % 60, $chapter ? ", in \"$chapter\"" : '');
    }

    /**
     * Add one to a day's count, creating it when it is the first.
     */
    protected function increment(string $slug, string $event, string $term): void
    {
        $where = ['slug' => $slug, 'day' => now()->toDateString(), 'event' => $event, 'term' => $term];

        if (DB::table(self::TABLE)->where($where)->increment('hits') === 0) {
            try {
                DB::table(self::TABLE)->insert([...$where, 'hits' => 1]);
            } catch (QueryException) {
                // Another request made it first.
                DB::table(self::TABLE)->where($where)->increment('hits');
            }
        }
    }
}
