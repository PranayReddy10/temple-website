<?php

namespace App\Models;

use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A policy or information page on the website: privacy policy, terms,
 * refunds, account deletion, about, contact. Edited in Admin → Website →
 * Pages and shown at darshansaathi.com/{slug}.
 */
class Page extends Model
{
    /** Pages the app, the payment gateways and the stores link to by address. */
    public const REQUIRED = ['privacy-policy', 'terms-and-conditions', 'refund-and-cancellation', 'account-deletion', 'contact-us'];

    /** Addresses the website or this application already answer. */
    public const RESERVED = ['admin', 'temple', 'api', 'temples', 'states', 'deities', 'storage', 'pay', 'passport', 'bookings', 'qr', 'offline', 'manifest', 'media-preview', 'sitemap', 'laravel', 'assets', 'icons', 'canvaskit', 'brand', 'livewire', 'filament', 'login', 'logout', 'up'];

    protected $fillable = ['slug', 'title', 'summary', 'body', 'is_published', 'show_in_footer', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'show_in_footer' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('site.footer-pages'));
        static::deleted(fn () => Cache::forget('site.footer-pages'));
    }

    /** @param  Builder<Page>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /** @return Collection<int, Page> */
    public static function footer(): Collection
    {
        return Cache::remember('site.footer-pages', 3600, fn () => static::query()->published()
            ->where('show_in_footer', true)->orderBy('sort_order')->orderBy('title')
            ->get(['slug', 'title']));
    }

    public function url(): string
    {
        return Seo::url($this->slug);
    }

    public function renderedTitle(): string
    {
        return self::withTokens($this->title, escape: false);
    }

    public function renderedSummary(): string
    {
        return self::withTokens((string) $this->summary, escape: false);
    }

    /** The body, with {app}, {email} and the rest filled in. */
    public function renderedBody(): string
    {
        return self::withTokens((string) $this->body, escape: true);
    }

    /** @return array<string, string> */
    public static function tokens(): array
    {
        $city = trim((string) setting('legal_jurisdiction_city'));

        return [
            '{app}' => (string) config('brand.name'),
            '{website}' => preg_replace('#^https?://#', '', Seo::website()),
            '{email}' => (string) setting('support_email', 'brand.support_email'),
            '{business}' => (string) (setting('legal_business_name') ?: config('brand.name')),
            '{address}' => (string) (setting('legal_address') ?: 'India'),
            '{grievance_officer}' => filled(setting('legal_grievance_officer')) ? (string) setting('legal_grievance_officer') : 'our Grievance Officer',
            '{courts}' => $city !== '' ? 'the exclusive jurisdiction of the courts at '.$city.', India' : 'the jurisdiction of the competent courts in India',
        ];
    }

    protected static function withTokens(string $text, bool $escape): string
    {
        $tokens = self::tokens();
        if ($escape) {
            $tokens = array_map(fn (string $v) => e($v), $tokens);
            // An address keeps its lines.
            $tokens['{address}'] = nl2br($tokens['{address}'], false);
        }

        // Inside a link, Markdown and the editor write {email} as %7Bemail%7D.
        foreach ($tokens as $key => $value) {
            $tokens[str_replace(['{', '}'], ['%7B', '%7D'], $key)] = $value;
        }

        return strtr($text, $tokens);
    }
}
