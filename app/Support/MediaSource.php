<?php

namespace App\Support;

/**
 * What a media URL actually is, and therefore how a client should play it.
 *
 * `source_type` only says whether we host the file. That is not enough to
 * play anything: a YouTube link needs an embedded player, an .mp3 needs an
 * audio element, and a page that merely mentions a recording needs a browser.
 * A client left to guess ends up either putting a YouTube page inside an
 * <audio> tag, which plays nothing, or opening an MP3 in a web view.
 *
 * So the kind is worked out here, once, and travels in the payload. The app
 * picks a player from it rather than pattern-matching URLs of its own — a
 * released build cannot be updated when a new host appears, but this can.
 */
final class MediaSource
{
    public const AUDIO = 'audio';

    public const VIDEO = 'video';

    public const YOUTUBE = 'youtube';

    public const VIMEO = 'vimeo';

    public const LINK = 'link';

    /** Extensions we are willing to call playable audio. */
    protected const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac'];

    protected const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    /**
     * How to play it: audio, video, youtube, vimeo, or just a link.
     *
     * A hosted upload is classified by its file extension; an external URL by
     * its host first and then its path.
     */
    public static function kind(?string $url, ?string $path = null): string
    {
        if (blank($url)) {
            return self::kindFromPath($path) ?? self::LINK;
        }

        $host = str(parse_url($url, PHP_URL_HOST) ?? '')->lower()->ltrim('www.')->toString();

        if (self::isYouTubeHost($host)) {
            return self::YOUTUBE;
        }

        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            return self::VIMEO;
        }

        // A direct file link — these are the ones an <audio> tag can take.
        return self::kindFromPath(parse_url($url, PHP_URL_PATH)) ?? self::LINK;
    }

    /**
     * The YouTube video id, for a client that embeds rather than opens.
     *
     * Handles the forms people actually paste: a watch URL, a youtu.be
     * short link, an embed URL and a Shorts URL, with or without a timestamp
     * or a playlist hanging off the end.
     */
    public static function youTubeId(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = str(parse_url($url, PHP_URL_HOST) ?? '')->lower()->ltrim('www.')->toString();

        if (! self::isYouTubeHost($host)) {
            return null;
        }

        // youtu.be/<id>
        if ($host === 'youtu.be') {
            return self::cleanId(trim((string) parse_url($url, PHP_URL_PATH), '/'));
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (filled($query['v'] ?? null)) {
            return self::cleanId((string) $query['v']);
        }

        // /embed/<id>, /shorts/<id>, /live/<id>, /v/<id>
        $segments = collect(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/')))
            ->filter()
            ->values();

        if ($segments->count() >= 2 && in_array($segments[0], ['embed', 'shorts', 'live', 'v'], true)) {
            return self::cleanId($segments[1]);
        }

        return null;
    }

    /** Whether the client can put this straight into a plain audio player. */
    public static function isDirectlyPlayable(string $kind): bool
    {
        return in_array($kind, [self::AUDIO, self::VIDEO], true);
    }

    /** Whether it needs an embedded player from the host. */
    public static function needsEmbed(string $kind): bool
    {
        return in_array($kind, [self::YOUTUBE, self::VIMEO], true);
    }

    /**
     * A canonical embed URL, so every client does not build its own and get
     * the parameters subtly different.
     */
    public static function embedUrl(?string $url): ?string
    {
        $id = self::youTubeId($url);

        if ($id !== null) {
            return 'https://www.youtube.com/embed/'.$id;
        }

        if (self::kind($url) !== self::VIMEO) {
            return null;
        }

        $vimeoId = self::cleanId(trim((string) parse_url($url, PHP_URL_PATH), '/'));

        return $vimeoId === null ? null : 'https://player.vimeo.com/video/'.$vimeoId;
    }

    protected static function isYouTubeHost(string $host): bool
    {
        return in_array($host, [
            'youtube.com', 'm.youtube.com', 'music.youtube.com',
            'youtube-nocookie.com', 'youtu.be',
        ], true);
    }

    protected static function kindFromPath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $extension = str(pathinfo($path, PATHINFO_EXTENSION))->lower()->toString();

        return match (true) {
            in_array($extension, self::AUDIO_EXTENSIONS, true) => self::AUDIO,
            in_array($extension, self::VIDEO_EXTENSIONS, true) => self::VIDEO,
            default => null,
        };
    }

    /**
     * An id is letters, digits, hyphen and underscore. Anything else is a
     * path segment that got caught, and returning it would build an embed
     * URL that 404s rather than admitting we could not read the link.
     */
    protected static function cleanId(?string $candidate): ?string
    {
        $candidate = trim((string) $candidate);

        if ($candidate === '' || preg_match('/^[A-Za-z0-9_-]{6,64}$/', $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }
}
