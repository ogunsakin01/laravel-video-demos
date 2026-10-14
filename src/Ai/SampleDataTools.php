<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use RuntimeException;

/**
 * The tools the model uses on a video's sample database: seed_data adds
 * records, query_data reads what is there. Records that save are kept on the
 * video, so recording it again rebuilds the same data.
 */
class SampleDataTools
{
    /**
     * Create a new sample data tools instance.
     */
    public function __construct(protected Sandbox $sandbox, protected Redactor $redactor = new Redactor) {}

    /**
     * Get the tool definitions.
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'seed_data',
                'description' => 'Add or change records in the sample database the video is recorded against, so the pages in the flow have the data they need and the signed-in user passes their middleware, policies and gates. Records are saved in order, each on its own: one that fails is reported and the rest are kept. New models use their factory when they have one, with your values on top; required columns you leave out get a sensible value. To change rows that exist, such as the signed-in user\'s own row (a profile photo, a finished onboarding step), give where_json and the rows matching it are updated instead. In data_json and where_json, "@ref" is the id of an earlier record saved under that ref ("@ref.column" another of its values), "@user" is the signed-in user, and "now" or "now +3 days" is a date. For pivot rows, such as a role or permission link, use table. For images, use a file the app already has under public/ or an image URL.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'records' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'ref' => ['type' => 'string', 'description' => 'A name later records can point at as "@name"; empty for none'],
                                    'model' => ['type' => 'string', 'description' => 'The model class, such as SupportTicket or App\\Models\\SupportTicket; empty to use table'],
                                    'table' => ['type' => 'string', 'description' => 'The table, when there is no model; empty when model is given'],
                                    'count' => ['type' => 'integer', 'description' => 'How many to make, 1 to 50'],
                                    'data_json' => ['type' => 'string', 'description' => 'The column values as a JSON object, such as {"user_id": "@user", "status": "open"}'],
                                    'where_json' => ['type' => 'string', 'description' => 'Empty to add new records; a JSON object such as {"id": "@user"} to update the rows it matches instead'],
                                ],
                                'required' => ['ref', 'model', 'table', 'count', 'data_json', 'where_json'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['records'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'query_data',
                'description' => 'Read rows from a table of the sample database, to see what the seeders made, such as the role and permission names or the ids of existing records. Leave table empty to list the tables and their row counts.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'table' => ['type' => 'string'],
                        'where_json' => ['type' => 'string', 'description' => 'Column values to match, as a JSON object; {} for all rows'],
                        'limit' => ['type' => 'integer', 'description' => 'Rows to return, up to 25'],
                    ],
                    'required' => ['table', 'where_json', 'limit'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Determine if the given tool is one of these tools.
     */
    public function handles(string $tool): bool
    {
        return in_array($tool, ['seed_data', 'query_data'], true);
    }

    /**
     * Run a tool and return its text result.
     *
     * @throws RuntimeException When the input is refused.
     */
    public function run(string $tool, array $input, Video $video): string
    {
        return $this->redactor->redact($tool === 'seed_data'
            ? $this->seed((array) ($input['records'] ?? []), $video)
            : $this->query((string) ($input['table'] ?? ''), (string) ($input['where_json'] ?? '{}'), (int) ($input['limit'] ?? 10)));
    }

    /**
     * Get a short log line describing a tool call.
     */
    public function describe(string $tool, array $input): string
    {
        if ($tool === 'query_data') {
            return ($input['table'] ?? '') !== '' ? 'Looked at the sample '.$input['table'] : 'Listed the sample tables';
        }

        $names = array_map(fn ($r) => (($r['count'] ?? 1) > 1 ? $r['count'].' ' : '').(($r['model'] ?? '') ?: ($r['table'] ?? '')), (array) ($input['records'] ?? []));

        return 'Added sample data: '.implode(', ', array_filter($names));
    }

    /**
     * Add the records and report each one.
     *
     * @param  list<array<string, mixed>>  $records
     */
    protected function seed(array $records, Video $video): string
    {
        if ($records === []) {
            throw new RuntimeException('Give at least one record.');
        }

        $parsed = [];
        $bad = [];
        foreach ($records as $i => $record) {
            $data = json_decode((string) ($record['data_json'] ?? '{}'), true);
            $whereJson = trim((string) ($record['where_json'] ?? ''));
            $where = $whereJson === '' ? [] : json_decode($whereJson, true);
            if (! is_array($data) || array_is_list($data) && $data !== []) {
                $bad[$i] = 'data_json is not a JSON object.';

                continue;
            }
            if (! is_array($where) || array_is_list($where) && $where !== []) {
                $bad[$i] = 'where_json is not a JSON object.';

                continue;
            }
            $parsed[$i] = array_filter([
                'ref' => (string) ($record['ref'] ?? '') ?: null,
                'model' => (string) ($record['model'] ?? '') ?: null,
                'table' => (string) ($record['table'] ?? '') ?: null,
                'count' => max(1, (int) ($record['count'] ?? 1)),
                'data' => $data,
                'where' => $where ?: null,
            ], fn ($v) => $v !== null);
        }

        // Only well-formed records are sent; match each result back to its record.
        $order = array_keys($parsed);
        $results = $parsed ? $this->sandbox->seed(array_map(fn ($i) => $parsed[$i], $order)) : [];
        $byIndex = [];
        foreach ($order as $position => $i) {
            $byIndex[$i] = $results[$position] ?? null;
        }

        $lines = [];
        $saved = [];
        foreach (array_keys($records) as $i) {
            $n = $i + 1;
            if (isset($bad[$i])) {
                $lines[] = "$n. Not saved: {$bad[$i]}";

                continue;
            }
            $result = $byIndex[$i] ?? ['ok' => false, 'what' => ($parsed[$i]['model'] ?? $parsed[$i]['table'] ?? ''), 'error' => 'No result.'];
            if ($result['ok'] ?? false) {
                $saved[] = $parsed[$i];
                $lines[] = "$n. ".(isset($parsed[$i]['where']) ? 'Updated' : 'Saved')." {$result['what']}".(! empty($result['ref']) ? " as @{$result['ref']}" : '').': id '.implode(', ', array_map('strval', $result['ids'] ?? []))
                    .(! empty($result['first']) ? "\n   ".json_encode($result['first'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
            } else {
                $lines[] = "$n. Not saved ({$result['what']}): {$result['error']}";
            }
        }

        if ($saved !== []) {
            $video->update(['sample_records' => [...($video->sample_records ?? []), ...$saved]]);
        }

        return implode("\n", $lines);
    }

    /**
     * Read rows, or list the tables.
     */
    protected function query(string $table, string $whereJson, int $limit): string
    {
        $where = json_decode($whereJson !== '' ? $whereJson : '{}', true);
        if (! is_array($where)) {
            throw new RuntimeException('where_json is not a JSON object.');
        }

        $result = $this->sandbox->query($table, $where, $limit);
        if (isset($result['error'])) {
            throw new RuntimeException($result['error']);
        }

        return Str::limit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 12_000);
    }
}
