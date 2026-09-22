<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The admin stylesheet link, shared by both panels.
 */
class TempleTheme
{
    public const STYLESHEET = 'css/temple-admin.css';

    /**
     * A ROOT-RELATIVE href, deliberately not asset().
     *
     * asset() builds an absolute URL from APP_URL. A deployment whose APP_URL
     * is still http://localhost then links the stylesheet at localhost: the
     * browser cannot fetch it, and on an https page it is blocked as mixed
     * content anyway. The panel renders unstyled and nothing appears in the
     * server logs, because the request never reached the server.
     *
     * The file sits in the same document root as the page asking for it, so a
     * root-relative path is both correct and immune to that.
     *
     * The query string is the file's modification time. Without it a browser
     * keeps serving the stylesheet it cached before the deploy, which looks
     * exactly like the CSS not having shipped.
     */
    public static function stylesheetHref(): string
    {
        $path = public_path(self::STYLESHEET);

        $version = File::exists($path) ? File::lastModified($path) : 0;

        return '/'.self::STYLESHEET.'?v='.$version;
    }

    public static function stylesheetTag(): string
    {
        return '<link rel="stylesheet" href="'.e(self::stylesheetHref()).'">';
    }
}
