<?php

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Draws the home-screen icons, so they can be regenerated rather than found.
 *
 * The icons are committed — nobody should need this command to deploy — but
 * they are drawn here rather than exported from a design tool so that a change
 * of brand colour is a change to config/brand.php and one command, not a
 * request to whoever still has the source file.
 *
 * The mark is a gopuram: the stepped tower over a South Indian temple gateway,
 * with its kalasha finial. It is the one silhouette that reads as "temple" at
 * 32 pixels, which a deity image or a lamp does not.
 *
 * Everything is drawn at four times the requested size and scaled down. GD's
 * own antialiasing covers lines and polygons only, so edges of filled shapes
 * come out stepped; supersampling covers all of it and costs nothing at these
 * sizes.
 */
class GenerateAppIconsCommand extends Command
{
    protected $signature = 'app:icons';

    protected $description = 'Draw the home-screen and browser icons into public/icons';

    /** How much bigger to draw before scaling down. */
    protected const SUPERSAMPLE = 4;

    /**
     * Each icon: file => [size, how much of the canvas the mark may use].
     *
     * A maskable icon is cropped to whatever shape the platform prefers —
     * a circle on some Android launchers — so its mark has to sit inside the
     * middle 80%. Drawing one icon and declaring it both "any" and "maskable"
     * is the common mistake: it is padded for the crop, so it renders small
     * and floating everywhere that does not crop.
     */
    protected const ICONS = [
        'icons/icon-192.png' => [192, 1.0],
        'icons/icon-512.png' => [512, 1.0],
        'icons/icon-maskable-512.png' => [512, 0.66],
        // iOS does its own rounding and adds no padding of its own, so this
        // one is square, opaque and drawn a little tighter than the rest.
        'icons/apple-touch-icon.png' => [180, 0.88],
        'icons/favicon-32.png' => [32, 1.0],
    ];

    public function handle(): int
    {
        $directory = public_path('icons');

        File::ensureDirectoryExists($directory);

        foreach (self::ICONS as $path => [$size, $scale]) {
            $image = $this->draw($size, $scale);

            imagepng($image, public_path($path), 9);
            imagedestroy($image);

            $this->line('  '.$path.'  '.$size.'×'.$size);
        }

        $this->writeFavicon();

        $this->info('Icons written to public/icons.');

        return self::SUCCESS;
    }

    /**
     * A .ico, because that is the file browsers ask for without being told.
     *
     * The one in the repository was zero bytes, and the admin panel pointed
     * its favicon at it. An ICO may carry a PNG as its payload rather than the
     * old bitmap format, which every browser still in use understands — so
     * this is a six-field header wrapped around the 32px PNG already drawn.
     */
    protected function writeFavicon(): void
    {
        $png = File::get(public_path('icons/favicon-32.png'));

        $header = pack('vvv', 0, 1, 1);          // reserved, type 1 (icon), one image
        $entry = pack('CC', 32, 32)              // width, height
            .pack('CC', 0, 0)                    // palette size, reserved
            .pack('vv', 1, 32)                   // colour planes, bits per pixel
            .pack('VV', strlen($png), 22);       // payload size, offset past the header

        File::put(public_path('favicon.ico'), $header.$entry.$png);

        $this->line('  favicon.ico  32×32');
    }

    /**
     * @param  int  $size  the finished icon's width and height
     * @param  float  $scale  how much of that the mark may occupy
     */
    protected function draw(int $size, float $scale): GdImage
    {
        $canvas = $size * self::SUPERSAMPLE;

        $image = imagecreatetruecolor($canvas, $canvas);

        $this->fillWithGradient($image, $canvas);
        $this->drawGopuram($image, $canvas, $scale);

        $final = imagescale($image, $size, $size, IMG_BICUBIC);
        imagedestroy($image);

        // imagescale can return a palette image on some builds; alpha has to
        // survive for the maskable icon's corners.
        imagesavealpha($final, true);

        return $final;
    }

    /** Saffron at the top falling to kumkum, the panel's own gradient. */
    protected function fillWithGradient(GdImage $image, int $canvas): void
    {
        [$fromR, $fromG, $fromB] = $this->rgb('saffron');
        [$toR, $toG, $toB] = $this->rgb('kumkum');

        for ($y = 0; $y < $canvas; $y++) {
            $t = $y / max(1, $canvas - 1);

            $colour = imagecolorallocate(
                $image,
                (int) round($fromR + ($toR - $fromR) * $t),
                (int) round($fromG + ($toG - $fromG) * $t),
                (int) round($fromB + ($toB - $fromB) * $t),
            );

            imageline($image, 0, $y, $canvas, $y, $colour);
        }
    }

    protected function drawGopuram(GdImage $image, int $canvas, float $scale): void
    {
        $sandal = imagecolorallocate($image, ...$this->rgb('sandal'));
        $deep = imagecolorallocate($image, ...$this->rgb('deep'));

        // Everything below is a fraction of the canvas, then pulled towards
        // the centre by $scale so one set of proportions serves every size.
        $at = fn (float $fraction): int => (int) round(
            $canvas * (0.5 + ($fraction - 0.5) * $scale),
        );

        $baseY = $at(0.78);
        $topY = $at(0.36);
        $halfBottom = $at(0.79) - $at(0.5);
        $halfTop = $at(0.635) - $at(0.5);

        // The tower: a trapezoid, wider at the ground.
        imagefilledpolygon($image, [
            $at(0.5) - $halfBottom, $baseY,
            $at(0.5) + $halfBottom, $baseY,
            $at(0.5) + $halfTop, $topY,
            $at(0.5) - $halfTop, $topY,
        ], $sandal);

        // Three rules across it, which is what makes it read as tiers rather
        // than as a plain wedge.
        $rule = max(1, (int) round($canvas * 0.012 * $scale));

        for ($tier = 1; $tier <= 3; $tier++) {
            $y = $topY + (int) round(($baseY - $topY) * $tier / 4);
            $half = $halfTop + (int) round(($halfBottom - $halfTop) * $tier / 4);

            imagefilledrectangle($image, $at(0.5) - $half, $y, $at(0.5) + $half, $y + $rule, $deep);
        }

        // The plinth it stands on.
        imagefilledrectangle(
            $image,
            $at(0.5) - $at(0.845) + $at(0.5), $baseY,
            $at(0.5) + $at(0.845) - $at(0.5), $at(0.84),
            $sandal,
        );

        // The doorway, cut back to the gradient. Left out of the smallest
        // icons, where it closes up into a smudge rather than an opening.
        if ($canvas / self::SUPERSAMPLE >= 96) {
            $this->cutDoorway($image, $canvas, $at, $baseY);
        }

        // The kalasha: the pot finial every gopuram is topped with.
        $kalashaY = $topY - (int) round($canvas * 0.035 * $scale);
        $radius = max(2, (int) round($canvas * 0.055 * $scale));

        imagefilledellipse($image, $at(0.5), $kalashaY, $radius * 2, $radius * 2, $sandal);

        $spike = max(1, (int) round($canvas * 0.014 * $scale));

        imagefilledrectangle(
            $image,
            $at(0.5) - $spike, $kalashaY - $radius - (int) round($canvas * 0.055 * $scale),
            $at(0.5) + $spike, $kalashaY,
            $sandal,
        );
    }

    /** An arched opening at the foot of the tower, in the background colour. */
    protected function cutDoorway(GdImage $image, int $canvas, callable $at, int $baseY): void
    {
        // Sampled from the gradient at the doorway's own height rather than
        // assumed: a flat fill here would show as a patch against the blend.
        $doorTop = $at(0.62);
        $half = $at(0.56) - $at(0.5);

        $background = imagecolorat($image, 2, (int) (($doorTop + $baseY) / 2));

        imagefilledrectangle($image, $at(0.5) - $half, $doorTop, $at(0.5) + $half, $baseY, $background);
        imagefilledellipse($image, $at(0.5), $doorTop, $half * 2, $half * 2, $background);
    }

    /** @return array{int, int, int} */
    protected function rgb(string $name): array
    {
        $hex = ltrim(config('brand.colors.'.$name.'.hex'), '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
