<?php

namespace App\Support\TempleAgent;

use Anthropic\Client;
use Anthropic\Messages\Message;
use Anthropic\Messages\WebFetchTool20260209;
use Anthropic\Messages\WebSearchTool20260209;
use App\Models\State;
use RuntimeException;

/**
 * The research agent: Claude searches the web and reads temples' own
 * websites, the state endowments department and other public sources, and
 * answers in the exact shape the importer reads.
 *
 * It is told to leave out what it cannot find rather than guess, and never
 * to give a seva price: what it returns becomes a community-level record
 * that editors verify against the temple.
 */
final class ClaudeTempleResearcher implements TempleResearcher
{
    private const DEITIES = ['shiva', 'vishnu', 'devi', 'ganesha', 'hanuman', 'murugan', 'krishna', 'rama', 'lakshmi', 'saraswati', 'venkateswara', 'ayyappa', 'surya', 'kali', 'narasimha'];

    private const CATEGORIES = ['jyotirlinga', 'shakti-peetha', 'divya-desam', 'pancharama', 'hill-temple', 'river-ghat-temple', 'coastal-temple', 'forest-temple', 'cave-temple', 'rock-cut-temple', 'unesco-world-heritage'];

    private const SYSTEM = <<<'TXT'
You research Hindu temples for a temple directory used by pilgrims. You have web search and web fetch.

Sources, best first: the temple's own or devasthanam website; the state endowments department
(Andhra Pradesh: aptemples.ap.gov.in, Telangana: tstemples.telangana.gov.in or the temple's
telangana.gov.in site); TTD for TTD temples; then Wikipedia; then reputable news and state tourism sites.
Read the official site when there is one.

Rules:
- Never invent. A value you could not find is null (an empty list for lists). Null is better than a guess.
- Cross-check: a value counts only when the temple's own site or the endowments department shows it,
  or two independent sources agree on it.
- Never give a seva, darshan or ticket price.
- Phone numbers and emails only when the temple or the endowments department publishes them.
- Coordinates must be at the temple itself (4 decimals is enough).
- Districts are the current ones (Andhra Pradesh's 2022 districts, Telangana's 2016 districts), without the word "district".
- Write in your own words: plain, factual English, no hype.
- Times are 24-hour "HH:MM". A midday closure is two general rows labelled "Morning" and "Evening".
- days: null for every day, else 0 = Sunday … 6 = Saturday.
TXT;

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort = 'high',
    ) {}

    public static function fromConfig(): self
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            throw new RuntimeException('Set ANTHROPIC_API_KEY in .env to run the temple research agent.');
        }

        return new self(
            new Client(apiKey: $key),
            (string) config('services.anthropic.model', 'claude-opus-5-5'),
            (string) config('services.anthropic.effort', 'high'),
        );
    }

    public function discover(State $state, array $listed, int $limit): array
    {
        $listedText = $listed === [] ? '(none yet)' : '- '.implode("\n- ", $listed);
        $prompt = <<<TXT
List the {$limit} most visited or most significant Hindu temples of {$state->name}, India, that are NOT already on the site.

Already on the site (also skip these under any other name, and skip shrines inside these temples' complexes):
{$listedText}

Search the web to check the temples are real, notable pilgrimage sites, and give each one's usual English name with its town, as in "Sri Kanaka Durga Temple, Vijayawada".
TXT;

        $data = $this->ask($prompt, [
            'type' => 'object',
            'properties' => [
                'temples' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'city' => ['type' => ['string', 'null']],
                            'district' => ['type' => ['string', 'null']],
                            'why' => ['type' => ['string', 'null'], 'description' => 'One line on why it is notable'],
                        ],
                        'required' => ['name', 'city', 'district', 'why'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['temples'],
            'additionalProperties' => false,
        ], searches: 8, fetches: 4);

        return array_slice(array_values(array_filter($data['temples'] ?? [], fn ($t) => is_array($t) && filled($t['name'] ?? null))), 0, $limit);
    }

    public function research(State $state, array $candidate): ?array
    {
        $where = implode(', ', array_filter([$candidate['city'] ?? null, $candidate['district'] ?? null, $state->name]));
        $prompt = <<<TXT
Research this temple completely: {$candidate['name']} ({$where}).

Find its official website if it has one and read it, then fill every field you can from reliable sources:
names (other English names devotees use and the Telugu or local-script name), deity, location (address,
PIN code, coordinates, current district), a short summary, history, significance and festivals, architecture,
when it was built, contact details, visitor rules (dress code, photography, mobiles, footwear, entry, queues
and darshan types), daily timings and rituals, the sevas it offers, how to reach it, and the sources you used.
"famous" is true only for the state's best-known pilgrimage temples (roughly its top 20).
TXT;

        $draft = $this->ask($prompt, $this->templeSchema(), searches: 6, fetches: 6);
        if (blank($draft['name'] ?? null)) {
            return null;
        }

        // A second, independent pass checks the draft field by field
        // against the official site and other sources before it is kept.
        $checked = $this->verify($draft);
        $row = $checked['record'];
        if (blank($row['name'] ?? null)) {
            return null;
        }
        $row['verification'] = [
            'checked_on' => now()->toDateString(),
            'changes' => array_values(array_filter((array) ($checked['changes'] ?? []), 'is_array')),
        ];

        // The importer reads other names as {"name": "locale"}.
        $aliases = [];
        foreach ((array) ($row['aliases'] ?? []) as $alias) {
            if (is_array($alias) && filled($alias['name'] ?? null)) {
                $aliases[$alias['name']] = $alias['locale'] ?? 'en';
            }
        }
        $row['aliases'] = $aliases;
        $row['deity'] = in_array($row['deity'] ?? null, self::DEITIES, true) ? $row['deity'] : null;

        return $row;
    }

    /**
     * The fact-check: every field of the draft is kept only when the
     * temple's own site or the endowments department shows it, or two
     * independent sources agree; it is corrected when they show otherwise,
     * and emptied when nothing confirms it.
     *
     * @param  array<string, mixed>  $draft
     * @return array{record: array<string, mixed>, changes: array<int, array<string, mixed>>}
     */
    private function verify(array $draft): array
    {
        $json = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $prompt = <<<TXT
Fact-check this draft record of a temple before it is published to pilgrims.

Read the temple's official website (and its timings, sevas and contact pages), the state endowments
department's page for it, its Wikipedia article and at least one more independent source. Then check
EVERY field of the draft:

- Confirmed means: shown on the temple's own or devasthanam site, the endowments department or the
  district portal, OR stated by at least two independent sources that agree.
- Confirmed: keep it. Sources show something else: correct it. Not confirmed: set it to null
  (an empty list for lists). Never keep a guess.
- The PIN code must be the temple's own village or town; the coordinates must be at the temple itself
  (within about 1 km); the website must be the temple's real official site.
- In history and significance, remove or fix any statement you cannot confirm; where sources disagree
  on a date, say it is disputed instead of choosing one.
- You may fill an empty field with a confirmed value. Keep the name unless it is clearly wrong.
- sources must list the pages that actually confirmed the facts.

List every change you made.

Draft:
{$json}
TXT;

        return $this->ask($prompt, [
            'type' => 'object',
            'properties' => [
                'record' => $this->templeSchema(),
                'changes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string'],
                        'old' => ['type' => ['string', 'null']],
                        'new' => ['type' => ['string', 'null']],
                        'why' => ['type' => 'string'],
                    ],
                    'required' => ['field', 'old', 'new', 'why'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['record', 'changes'],
            'additionalProperties' => false,
        ], searches: 8, fetches: 8) + ['record' => [], 'changes' => []];
    }

    /**
     * One research task: Claude searches and reads until it can answer, and
     * answers as JSON matching the schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function ask(string $prompt, array $schema, int $searches, int $fetches): array
    {
        $messages = [['role' => 'user', 'content' => $prompt]];

        // A long search can pause the turn; sending it back lets it carry on.
        for ($round = 0; $round < 6; $round++) {
            $message = $this->client->messages->create(
                maxTokens: 32000,
                messages: $messages,
                model: $this->model,
                system: self::SYSTEM,
                outputConfig: [
                    'effort' => $this->effort,
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                tools: [
                    WebSearchTool20260209::with(maxUses: $searches),
                    WebFetchTool20260209::with(maxUses: $fetches),
                ],
            );

            if ($message->stopReason !== 'pause_turn') {
                return $this->json($message);
            }
            $messages[] = ['role' => 'assistant', 'content' => $message->content];
        }

        throw new RuntimeException('The research did not finish.');
    }

    /** @return array<string, mixed> */
    private function json(Message $message): array
    {
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The model declined this research.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('The answer was cut off before it finished.');
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new RuntimeException('The answer was not the expected JSON.');
        }

        return $data;
    }

    /** @return array<string, mixed> the shape of one database/data/temples/*.json entry */
    private function templeSchema(): array
    {
        $text = fn (string $description = '') => array_filter(['type' => ['string', 'null'], 'description' => $description ?: null]);
        $object = fn (array $properties) => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];

        return $object([
            'name' => ['type' => 'string', 'description' => 'Usual English name with the town, e.g. "Sri Kanaka Durga Temple, Vijayawada"'],
            'deity' => ['type' => 'string', 'enum' => [...self::DEITIES, 'other'], 'description' => '"other" when none fits. Subrahmanya = murugan; Varaha, Kurma, Ranganatha, Kesava, Satyanarayana = vishnu; Durga and village goddesses = devi'],
            'famous' => ['type' => 'boolean'],
            'district' => ['type' => 'string'],
            'city' => $text('Town or village'),
            'address' => $text(),
            'pincode' => $text('6-digit PIN code'),
            'lat' => ['type' => ['number', 'null']],
            'lng' => ['type' => ['number', 'null']],
            'categories' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::CATEGORIES], 'description' => 'Only those that are true'],
            'aliases' => ['type' => 'array', 'items' => $object([
                'name' => ['type' => 'string'],
                'locale' => ['type' => 'string', 'description' => '"en", or the language code of the script, e.g. "te" for Telugu'],
            ])],
            'summary' => $text('1–2 sentences, at most 300 characters'),
            'history' => $text('3–6 sentences: origin, who built or renovated it, when'),
            'significance' => $text('3–6 sentences: why devotees go, main festivals with months, special rituals or vows'),
            'architecture_style' => $text(),
            'built_period' => $text('e.g. "11th century"'),
            'website' => $text('Official website URL'),
            'phone' => $text(),
            'email' => $text(),
            'rules' => $object([
                'dress_code' => $text(),
                'photography' => $text(),
                'mobile' => $text(),
                'footwear' => $text(),
                'entry' => $text('Who may enter, non-Hindu or ID rules'),
                'queue' => $text('Darshan types and queues, typical waits'),
            ]),
            'timings' => ['type' => 'array', 'items' => $object([
                'kind' => ['type' => 'string', 'enum' => ['general', 'darshan', 'aarti', 'special']],
                'label' => $text(),
                'opens' => ['type' => 'string', 'description' => 'HH:MM, 24-hour'],
                'closes' => ['type' => 'string', 'description' => 'HH:MM, 24-hour'],
                'days' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ])],
            'timings_source' => $text('URL the timings came from'),
            'pujas' => ['type' => 'array', 'items' => $object([
                'name' => ['type' => 'string'],
                'description' => $text('One sentence'),
                'starts_at' => $text('HH:MM'),
                'duration_minutes' => ['type' => ['integer', 'null']],
                'schedule_note' => $text('e.g. "Daily", "Mondays"'),
                'is_free' => ['type' => 'boolean'],
            ])],
            'nearest' => $object([
                'railway' => $text('Station and distance'),
                'airport' => $text('Airport and distance'),
                'bus' => $text('Bus station and distance'),
            ]),
            'sources' => ['type' => 'array', 'items' => $object([
                'name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
            ])],
        ]);
    }
}
