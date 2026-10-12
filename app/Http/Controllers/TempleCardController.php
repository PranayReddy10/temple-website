<?php

namespace App\Http\Controllers;

use App\Models\Temple;
use App\Models\TempleTiming;
use App\Support\DevotionalClock;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /temples/{slug}/card.png — a 1200×630 card with the temple's name, place,
 * deity and today's darshan hours, for a temple with no photo and no deity
 * painting yet. Search engines and WhatsApp previews then have an image
 * that belongs to this temple rather than none, or the site's own logo.
 *
 * Drawn once a day per temple (the hours are today's) and kept on the
 * local disk; a new photo replaces it on the page at once.
 */
class TempleCardController extends Controller
{
    private const W = 1200;

    private const H = 630;

    public function __invoke(string $slug): Response
    {
        $temple = Temple::query()->published()->where('slug', $slug)
            ->with(['deity:id,name', 'state:id,name', 'district:id,name', 'timings'])
            ->first() ?? throw new NotFoundHttpException;

        $today = DevotionalClock::now();
        $path = 'cards/'.$temple->slug.'-'.substr(sha1($temple->updated_at?->timestamp.'|'.$today->toDateString()), 0, 12).'.png';
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            foreach ($disk->files('cards') as $old) {
                if (str_starts_with(basename($old), $temple->slug.'-')) {
                    $disk->delete($old);
                }
            }
            $disk->put($path, self::draw($temple, (int) $today->dayOfWeek));
        }

        return response($disk->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public static function draw(Temple $temple, int $dayOfWeek): string
    {
        $bold = resource_path('fonts/onest/Onest-Bold.ttf');
        $regular = resource_path('fonts/onest/Onest-Regular.ttf');

        $im = imagecreatetruecolor(self::W, self::H);
        // Kumkum to temple teak, top-left to bottom-right.
        [$r1, $g1, $b1] = [155, 27, 48];
        [$r2, $g2, $b2] = [62, 39, 35];
        for ($y = 0; $y < self::H; $y++) {
            $t = $y / self::H;
            $c = imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($im, 0, $y, self::W, $y, $c);
        }

        $white = imagecolorallocate($im, 255, 255, 255);
        $gold = imagecolorallocate($im, 201, 162, 39);
        $pale = imagecolorallocate($im, 247, 226, 156);
        $soft = imagecolorallocate($im, 241, 223, 200);

        // A gold band along the foot, like the site's header.
        imagefilledrectangle($im, 0, self::H - 14, self::W, self::H, $gold);
        // A simple gopuram outline at the right, in faint gold.
        $faint = imagecolorallocatealpha($im, 201, 162, 39, 95);
        foreach ([[880, 470, 1120, 560], [910, 380, 1090, 470], [940, 300, 1060, 380], [965, 235, 1035, 300]] as [$x1, $y1, $x2, $y2]) {
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, $faint);
        }
        imagefilledpolygon($im, [985, 235, 1015, 235, 1000, 190], $faint);

        imagettftext($im, 30, 0, 70, 100, $pale, $bold, (string) config('brand.name'));

        $y = 215;
        foreach (self::lines($temple->name, $bold, 54, 760, 3) as $line) {
            imagettftext($im, 54, 0, 70, $y, $white, $bold, $line);
            $y += 72;
        }

        $place = collect([$temple->city, $temple->district?->name, $temple->state?->name])->filter()->unique()->implode(', ');
        if ($place !== '') {
            imagettftext($im, 30, 0, 70, $y + 10, $soft, $regular, self::fit($place, $regular, 30, 780));
            $y += 56;
        }
        if ($temple->deity) {
            imagettftext($im, 28, 0, 70, $y + 10, $pale, $regular, self::fit('Deity: '.$temple->deity->name, $regular, 28, 780));
            $y += 52;
        }

        $hours = TempleTiming::forDay($temple->timings, $dayOfWeek)->map(fn (TempleTiming $t) => $t->window())
            ->reject(fn ($w) => $w === 'Not published')->unique()->take(2)->implode(', ');
        $foot = $hours !== '' ? 'Darshan today: '.$hours : 'Darshan timings · Sevas · Dress code · Directions';
        imagettftext($im, 28, 0, 70, self::H - 70, $white, $bold, self::fit($foot, $bold, 28, 790));

        ob_start();
        imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    /** @return array<int, string> the text wrapped to the width, at most $max lines */
    private static function lines(string $text, string $font, int $size, int $width, int $max): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && self::width($try, $font, $size) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        $lines[] = $line;

        if (count($lines) > $max) {
            $lines = array_slice($lines, 0, $max);
            $lines[$max - 1] = self::fit($lines[$max - 1].'…', $font, $size, $width);
        }

        return $lines;
    }

    private static function fit(string $text, string $font, int $size, int $width): string
    {
        while (mb_strlen($text) > 4 && self::width($text, $font, $size) > $width) {
            $text = rtrim(mb_substr($text, 0, -2)).'…';
        }

        return $text;
    }

    private static function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : abs($box[2] - $box[0]);
    }
}
