<?php

/*
 * Color Thief PHP
 *
 * Grabs the dominant color or a representative color palette from an image.
 *
 * This class requires the GD library to be installed on the server.
 *
 * It's a PHP port of the Color Thief Javascript library
 * (http://github.com/lokesh/color-thief), using the MMCQ
 * (modified median cut quantization) algorithm from
 * the Leptonica library (http://www.leptonica.com/).
 *
 * by Kevin Subileau - http://www.kevinsubileau.fr
 * Based on the work done by Lokesh Dhakar - http://www.lokeshdhakar.com
 * and Nick Rabinowitz
 *
 * License
 * -------
 * Creative Commons Attribution 2.5 License:
 * http://creativecommons.org/licenses/by/2.5/
 *
 * Thanksn
 * ------
 * Lokesh Dhakar - For creating the original project.
 * Nick Rabinowitz - For creating quantize.js.
 *
 */

class ColorThief {
    const SIGBITS=5;
    const RSHIFT=3;
    const MAX_ITERATIONS=1000;
    const FRACT_BY_POPULATIONS=0.75;

    // get reduced-space color index for a pixel
    public static function getColorIndex($r, $g, $b, $sigbits = self::SIGBITS)
    {
        return ($r << (2 * $sigbits)) + ($g << $sigbits) + $b;
    }

    // get red, green and blue components from reduced-space color index for a pixel
    public static function getColorsFromIndex($index, $rshift = self::RSHIFT, $sigbits = 8)
    {
        $mask = (1 << $sigbits) - 1;
        $rval = (($index >> (2 * $sigbits)) & $mask) >> $rshift;
        $gval = (($index >> $sigbits) & $mask) >> $rshift;
        $bval = ($index & $mask) >> $rshift;
        return array($rval, $gval, $bval);
    }

    /* Miscellaneous functions */
    public static function naturalOrder($a, $b)
    {
        return ($a < $b) ? - 1 : (($a > $b) ? 1 : 0);
    }

    /*
     * getColor(sourceImage[, quality, area])
     * returns {r: num, g: num, b: num}
     *
     * Use the median cut algorithm to cluster similar colors and
     * return the base color from the largest cluster. Quality is
     * an optional argument. It needs to be an integer.
     * 1 is the highest quality settings. 10 is the default.
     * There is a trade-off between quality and speed.
     * The bigger the number, the faster a color will be returned
     * but the greater the likelihood that it will not be the
     * visually most dominant color. Area is also an optional
     * argument. It allows you to specify a rectangular area in
     * the image in order to get colors only for this area. It
     * needs to be an associative array with the following keys :
     *  - $area['x'] : The x-coordinate of the top left corner
     *                 of the area. Default to 0.
     *  - $area['y'] : The y-coordinate of the top left corner
     *                 of the area. Default to 0.
     *  - $area['w'] : The width of the area. Default to the
     *                 the width of the image minus x-coordinate.
     *  - $area['h'] : The height of the area. Default to the
     *                 the height of the image minus y-coordinate.
     *
     */
    public static function getColor($sourceImage, $quality = 10, array $area = null)
    {
        $palette = static::getPalette($sourceImage, 5, $quality, $area);

        return $palette?$palette[0]:false;
    }

    /*
     * getPalette(sourceImage[, colorCount, quality, area])
     * returns array[ {r: num, g: num, b: num}, {r: num, g: num, b: num}, ...]
     *
     * Use the median cut algorithm to cluster similar colors.
     *
     * colorCount determines the size of the palette; the number of colors
     * returned. If not set, it defaults to 10.
     *
     * BUGGY: Function does not always return the requested amount of colors.
     * It can be +/- 2.
     *
     * Quality is an optional argument. It needs to be an integer.
     * 1 is the highest quality settings. 10 is the default.
     * There is a trade-off between quality and speed. The bigger the number,
     * the faster the palette generation but the greater the likelihood that
     * colors will be missed. Area is also an optional
     * argument. It allows you to specify a rectangular area in
     * the image in order to get colors only for this area. It
     * needs to be an associative array with the following keys :
     *  - $area['x'] : The x-coordinate of the top left corner
     *                 of the area. Default to 0.
     *  - $area['y'] : The y-coordinate of the top left corner
     *                 of the area. Default to 0.
     *  - $area['w'] : The width of the area. Default to the
     *                 width of the image minus x-coordinate.
     *  - $area['h'] : The height of the area. Default to the
     *                 height of the image minus y-coordinate.
     */
    public static function getPalette($sourceImage, $colorCount = 10, $quality = 10, array $area = null)
    {
        // short-circuit
        if ($colorCount < 2 || $colorCount > 256) {
            throw new \InvalidArgumentException("The number of palette colors must be between 2 and 256 inclusive.");
        }
        // short-circuit
        if ($quality < 1) {
            throw new \InvalidArgumentException("The quality argument must be an integer greater than one.");
        }

        $pixelArray = static::loadImage($sourceImage, $quality, $area);
        if (!count($pixelArray)) {
            throw new \RuntimeException("Unable to compute the color palette of a blank or transparent image.", 1);
        }

        // Send array to quantize function which clusters values
        // using median cut algorithm
        $cmap = static::quantize($pixelArray, $colorCount);
        $palette = $cmap->palette();

        return $palette;
    }

    // histo (1-d array, giving the number of pixels in
    // each quantized region of color space), or null on error
    private static function getHisto($pixels)
    {
        $histo = array();

        foreach ($pixels as $rgb) {
            list($rval, $gval, $bval) = static::getColorsFromIndex($rgb);
            $index = self::getColorIndex($rval, $gval, $bval);
            $histo[$index] = (isset($histo[$index]) ? $histo[$index] : 0) + 1;
        }

        return $histo;
    }

    private static function loadImage($sourceImage, $quality, array $area = null)
    {
        $loader = new ImageLoader();
        $image  = $loader->load($sourceImage);
        $startx = 0;
        $starty = 0;
        $width  = $image->getWidth();
        $height = $image->getHeight();

        if ($area) {
            $startx = isset($area['x']) ? $area['x'] : 0;
            $starty = isset($area['y']) ? $area['y'] : 0;
            $width  = isset($area['w']) ? $area['w'] : ($width  - $startx);
            $height = isset($area['h']) ? $area['h'] : ($height - $starty);

            if ((($startx + $width) > $image->getWidth()) || (($starty + $height) > $image->getHeight())) {
                throw new \InvalidArgumentException("Area is out of image bounds.");
            }
        }

        $pixelCount = $width * $height;

        // Store the RGB values in an array format suitable for quantize function
        // SplFixedArray is faster and more memory-efficient than normal PHP array.
        $pixelArray = new SplFixedArray(ceil($pixelCount/$quality));

        $j = 0;
        for ($i = 0; $i < $pixelCount; $i = $i + $quality) {
            $x = $startx + ($i % $width);
            $y = (int) ($starty + $i / $width);
            $color = $image->getPixelColor($x, $y);
            $pixelArray[$j++] = self::getColorIndex($color->red, $color->green, $color->blue, 8);
        }

        $pixelArray->setSize($j);

        // Don't destroy a ressource passed by the user !
        if (is_string($sourceImage)) {
            $image->destroy();
        }

        return $pixelArray;
    }

    private static function vboxFromHistogram(array $histo)
    {
        $rmin = PHP_INT_MAX;
        $rmax = 0;
        $gmin = PHP_INT_MAX;
        $gmax = 0;
        $bmin = PHP_INT_MAX;
        $bmax = 0;

        // find min/max
        foreach ($histo as $index => $count) {
            list($rval, $gval, $bval) = static::getColorsFromIndex($index, 0, ColorThief::SIGBITS);

            if ($rval < $rmin) {
                $rmin = $rval;
            } elseif ($rval > $rmax) {
                $rmax = $rval;
            }

            if ($gval < $gmin) {
                $gmin = $gval;
            } elseif ($gval > $gmax) {
                $gmax = $gval;
            }

            if ($bval < $bmin) {
                $bmin = $bval;
            } elseif ($bval > $bmax) {
                $bmax = $bval;
            }
        }

        return new VBox($rmin, $rmax, $gmin, $gmax, $bmin, $bmax, $histo);
    }

    private static function doCut($color, $vbox, $partialsum, $total)
    {
        $dim1 = $color . '1';
        $dim2 = $color . '2';

        for ($i = $vbox->$dim1; $i <= $vbox->$dim2; $i++) {
            if ($partialsum[$i] > $total / 2) {
                $vbox1 = $vbox->copy();
                $vbox2 = $vbox->copy();
                $left = $i - $vbox->$dim1;
                $right = $vbox->$dim2 - $i;

                // Choose the cut plane within the greater of the (left, right) sides
                // of the bin in which the median pixel resides
                if ($left <= $right) {
                    $d2 = min($vbox->$dim2 - 1, ~ ~ ($i + $right / 2));
                } else { /* left > right */
                    $d2 = max($vbox->$dim1, ~ ~ ($i - 1 - $left / 2));
                }

                while (empty($partialsum[$d2])) {
                    $d2 ++;
                }
                // Avoid 0-count boxes
                while ($partialsum[$d2] >= $total  && !empty($partialsum[$d2 - 1])) {
                    --$d2;
                }

                // set dimensions
                $vbox1->$dim2 = $d2;
                $vbox2->$dim1 = $d2 + 1;

                // echo 'vbox counts: '.$vbox->count().' '.$vbox1->count().' '.$vbox2->count()."\n";
                return array($vbox1, $vbox2);
            }
        }
    }

    private static function medianCutApply($histo, $vbox)
    {
        if (!$vbox->count()) {
            return;
        }

        // If the vbox occupies just one element in color space, it can't be split
        if ($vbox->count() == 1) {
            return array ($vbox->copy());
        }

        // Select the longest axis for splitting
        $rw = $vbox->r2 - $vbox->r1 + 1;
        $gw = $vbox->g2 - $vbox->g1 + 1;
        $bw = $vbox->b2 - $vbox->b1 + 1;
        $maxw = max($rw, $gw, $bw);

        /* Find the partial sum arrays along the selected axis. */
        $total = 0;
        $partialsum = array ();
        $lookaheadsum = array ();

        if ($maxw == $rw) {
            for ($i = $vbox->r1; $i <= $vbox->r2; $i++) {
                $sum = 0;
                for ($j = $vbox->g1; $j <= $vbox->g2; $j++) {
                    for ($k = $vbox->b1; $k <= $vbox->b2; $k++) {
                        $index = self::getColorIndex($i, $j, $k);
                        if (isset($histo[$index])) {
                            $sum += $histo[$index];
                        }
                    }
                }
                $total += $sum;
                $partialsum[$i] = $total;
            }
        } elseif ($maxw == $gw) {
            for ($i = $vbox->g1; $i <= $vbox->g2; $i++) {
                $sum = 0;
                for ($j = $vbox->r1; $j <= $vbox->r2; $j++) {
                    for ($k = $vbox->b1; $k <= $vbox->b2; $k++) {
                        $index = self::getColorIndex($j, $i, $k);
                        if (isset($histo[$index])) {
                            $sum += $histo[$index];
                        }
                    }
                }
                $total += $sum;
                $partialsum[$i] = $total;
            }
        } else { /* maxw == bw */
            for ($i = $vbox->b1; $i <= $vbox->b2; $i++) {
                $sum = 0;
                for ($j = $vbox->r1; $j <= $vbox->r2; $j++) {
                    for ($k = $vbox->g1; $k <= $vbox->g2; $k++) {
                        $index = self::getColorIndex($j, $k, $i);
                        if (isset($histo [$index])) {
                            $sum += $histo[$index];
                        }
                    }
                }
                $total += $sum;
                $partialsum[$i] = $total;
            }
        }

        // Determine the cut planes
        if ($maxw == $rw) {
            return static::doCut('r', $vbox, $partialsum, $total);
        } elseif ($maxw == $gw) {
            return static::doCut('g', $vbox, $partialsum, $total);
        } else {
            return static::doCut('b', $vbox, $partialsum, $total);
        }
    }


    // inner function to do the iteration
    private static function quantizeIter(&$lh, $target, $histo)
    {
        $ncolors = 1;
        $niters = 0;

        while ($niters < self::MAX_ITERATIONS) {
            $vbox = $lh->pop();

            if (! $vbox->count()) { /* just put it back */
                $lh->push($vbox);
                $niters++;
                continue;
            }
            // do the cut
            $vboxes = static::medianCutApply($histo, $vbox);

            if (! (is_array($vboxes) && isset($vboxes[0]))) {
                // echo "vbox1 not defined; shouldn't happen!"."\n";
                return;
            }

            $lh->push($vboxes[0]);

            if (isset($vboxes[1])) { /* vbox2 can be null */
                $lh->push($vboxes[1]);
                $ncolors++;
            }

            if ($ncolors >= $target) {
                return;
            }

            if ($niters++ > self::MAX_ITERATIONS) {
                // echo "infinite loop; perhaps too few pixels!"."\n";
                return;
            }
        }
    }

    private static function quantize($pixels, $maxcolors)
    {
        // short-circuit
        if (! count($pixels) || $maxcolors < 2 || $maxcolors > 256) {
            // echo 'wrong number of maxcolors'."\n";
            return false;
        }

        $histo = static::getHisto($pixels);

        // check that we aren't below maxcolors already
        //if (count($histo) <= $maxcolors) {
            // XXX: generate the new colors from the histo and return
        //}

        $vbox = static::vboxFromHistogram($histo);

        $pq = new PQueue(function ($a, $b) {
            return ColorThief::naturalOrder($a->count(), $b->count());
        });
        $pq->push($vbox);

        // first set of colors, sorted by population
        static::quantizeIter($pq, self::FRACT_BY_POPULATIONS * $maxcolors, $histo);

        // Re-sort by the product of pixel occupancy times the size in color space.
        $pq->setComparator(function ($a, $b) {
            return ColorThief::naturalOrder($a->count() * $a->volume(), $b->count() * $b->volume());
        });

        // next set - generate the median cuts using the (npix * vol) sorting.
        static::quantizeIter($pq, $maxcolors - $pq->size(), $histo);

        // calculate the actual colors
        $cmap = new CMap();

        for ($i = $pq->size(); $i > 0; $i--) {
            $cmap->push($pq->pop());
        }

        return $cmap;
    }
}

class VBox
{
    public $r1;
    public $r2;
    public $g1;
    public $g2;
    public $b1;
    public $b2;
    public $histo;

    private $volume = false;
    private $count;
    private $count_set = false;
    private $avg = false;

    public function __construct($r1, $r2, $g1, $g2, $b1, $b2, $histo)
    {
        $this->r1 = $r1;
        $this->r2 = $r2;
        $this->g1 = $g1;
        $this->g2 = $g2;
        $this->b1 = $b1;
        $this->b2 = $b2;
        $this->histo = $histo;
    }

    public function volume($force = false)
    {
        if (! $this->volume || $force) {
            $this->volume = (($this->r2 - $this->r1 + 1) * ($this->g2 - $this->g1 + 1) * ($this->b2 - $this->b1 + 1));
        }

        return $this->volume;
    }

    public function count($force = false)
    {
        if (! $this->count_set || $force) {
            $npix = 0;

            // Select the fastest way (i.e. with the fewest iterations) to count
            // the number of pixels contained in this vbox.
            if ($this->volume() > count($this->histo)) {
                // Iterate over the histogram if the size of this histogram is lower than the vbox volume
                foreach ($this->histo as $rgb => $count) {
                    $rgb_array =  ColorThief::getColorsFromIndex($rgb, 0, ColorThief::SIGBITS);
                    if ($this->contains($rgb_array, 0)) {
                        $npix += $count;
                    }
                }
            } else {
                // Or iterate over points of the vbox if the size of the histogram is greater than the vbox volume
                for ($i = $this->r1; $i <= $this->r2; $i++) {
                    for ($j = $this->g1; $j <= $this->g2; $j++) {
                        for ($k = $this->b1; $k <= $this->b2; $k++) {
                            $index = ColorThief::getColorIndex($i, $j, $k);
                            if (isset($this->histo[$index])) {
                                $npix += $this->histo[$index];
                            }
                        }
                    }
                }
            }
            $this->count = $npix;
            $this->count_set = true;
        }
        return $this->count;
    }

    public function copy()
    {
        return new VBox($this->r1, $this->r2, $this->g1, $this->g2, $this->b1, $this->b2, $this->histo);
    }

    public function avg($force = false)
    {
        if (! $this->avg || $force) {
            $ntot = 0;
            $mult = 1 << (8 - ColorThief::SIGBITS);
            $rsum = 0;
            $gsum = 0;
            $bsum = 0;

            for ($i = $this->r1; $i <= $this->r2; $i++) {
                for ($j = $this->g1; $j <= $this->g2; $j++) {
                    for ($k = $this->b1; $k <= $this->b2; $k++) {
                        $histoindex = ColorThief::getColorIndex($i, $j, $k);
                        $hval = isset ($this->histo[$histoindex]) ? $this->histo[$histoindex] : 0;
                        $ntot += $hval;
                        $rsum += ($hval * ($i + 0.5) * $mult);
                        $gsum += ($hval * ($j + 0.5) * $mult);
                        $bsum += ($hval * ($k + 0.5) * $mult);
                    }
                }
            }

            if ($ntot) {
                $this->avg = array (
                        ~ ~ ($rsum / $ntot),
                        ~ ~ ($gsum / $ntot),
                        ~ ~ ($bsum / $ntot)
                );
            } else {
                // echo 'empty box'."\n";
                $this->avg = array (
                        ~ ~ ($mult * ($this->r1 + $this->r2 + 1) / 2),
                        ~ ~ ($mult * ($this->g1 + $this->g2 + 1) / 2),
                        ~ ~ ($mult * ($this->b1 + $this->b2 + 1) / 2)
                );
            }
        }

        return $this->avg;
    }

    public function contains(array $pixel, $rshift = ColorThief::RSHIFT)
    {
        $rval = $pixel[0] >> $rshift;
        $gval = $pixel[1] >> $rshift;
        $bval = $pixel[2] >> $rshift;

        return
            $rval >= $this->r1 &&
            $rval <= $this->r2 &&
            $gval >= $this->g1 &&
            $gval <= $this->g2 &&
            $bval >= $this->b1 &&
            $bval <= $this->b2;
    }
}

class PQueue
{
    private $contents = array();
    private $sorted = false;
    private $comparator = null;

    public function __construct($comparator)
    {
        $this->setComparator($comparator);
    }

    private function sort()
    {
        usort($this->contents, $this->comparator);
        $this->sorted = true;
    }

    public function push($object)
    {
        array_push($this->contents, $object);
        $this->sorted = false;
    }

    public function peek($index = null)
    {
        if (! $this->sorted) {
            $this->sort();
        }

        if ($index === null) {
            $index = $this->size() - 1;
        }

        return $this->contents[$index];
    }

    public function pop()
    {
        if (! $this->sorted) {
            $this->sort();
        }

        return array_pop($this->contents);
    }

    public function size()
    {
        return count($this->contents);
    }

    public function map($function)
    {
        return array_map($function, $this->contents);
    }

    public function setComparator($function)
    {
        $this->comparator = $function;
        $this->sorted = false;
    }

    public function debug()
    {
        if (! $this->sorted) {
            $this->sort();
        }

        return $this->contents;
    }
}

class CMap
{
    private $vboxes;

    public function __construct()
    {
        $this->vboxes = new PQueue(function ($a, $b) {
            return ColorThief::naturalOrder(
                $a['vbox']->count() * $a['vbox']->volume(),
                $b['vbox']->count() * $b['vbox']->volume()
            );
        });
    }

    public function push($vbox)
    {
        $this->vboxes->push(array(
                'vbox' => $vbox,
                'color' => $vbox->avg()
            ));
    }

    public function palette()
    {
        $retval = $this->vboxes->map(function ($vb) {
            return array(
                'color' => $vb['color'],
                'dominance' => ( $vb['vbox']->volume() * $vb['vbox']->count() ),
            );
        });
        $dominance_sum = array_sum( wp_list_pluck( $retval, 'dominance' ) );
        array_walk( $retval, function( &$item, $key, $sum ) {
            $item['dominance'] = (int) round( ( $item['dominance'] / $sum ) * 100 );
        }, $dominance_sum );

        return $retval;
    }

    public function size()
    {
        return count($this->vboxes);
    }

    public function map($color)
    {
        $vboxes_size = $this->vboxes->size();
        for ($i = 0; $i < $vboxes_size; $i++) {
            $vbox = $this->vboxes->peek($i);
            if ($vbox['vbox']->contains($color)) {
                return $vbox['color'];
            }
        }

        return $this->nearest($color);
    }

    public function nearest($color)
    {
        $pColor = null;
        $vboxes_size = $this->vboxes->size();
        for ($i = 0; $i < $vboxes_size; $i++) {
            $vbox = $this->vboxes->peek($i);
            $d2 = sqrt(
                pow($color[0] - $vbox['color'][0], 2) +
                pow($color[1] - $vbox['color'][1], 2) +
                pow($color[2] - $vbox['color'][2], 2)
            );

            if (! isset($d1) || $d2 < $d1) {
                $d1 = $d2;
                $pColor = $vbox['color'];
            }
        }

        return $pColor;
    }
}

class ImageLoader
{
    public function load($source)
    {
        $image = null;

        if (is_string($source)) {
            $is_remote = filter_var($source, FILTER_VALIDATE_URL);
            if (!$is_remote && (!file_exists($source) || !is_readable($source))) {
                throw new \RuntimeException("Image '".$source."' is not readable or does not exists.");
            }

            if ($this->isImagickLoaded()) {
                $image = $this->getAdapter("Imagick");
            } else {
                $image = $this->getAdapter("GD");
            }

            $image->loadFile($source);
        } else {
            if ((is_resource($source) && get_resource_type($source) == 'gd')) {
                $image = $this->getAdapter("GD");
            } elseif (is_a($source, 'Imagick')) {
                $image = $this->getAdapter("Imagick");
            } else {
                throw new \InvalidArgumentException("Passed variable is not a valid image source");
            }
            $image->load($source);
        }

        return $image;
    }

    public function isImagickLoaded()
    {
        return extension_loaded("imagick");
    }

    public function getAdapter($adapterType)
    {
        $classname = $adapterType."ImageAdapter";
        return new $classname();
    }
}

/**
* Basic interface for all image adapters.
*/
interface IImageAdapter
{
    /**
     * Loads an image from file.
     *
     * @param string $file
     */
    public function loadFile($path);

    /**
     * Loads an image ressource.
     *
     * @param mixed $ressource
     */
    public function load($ressource);

    /**
     * Destroys the image.
     *
     * @param string $file
     */
    public function destroy();

    /**
     * Returns image height.
     *
     * @return integer
     */
    public function getHeight();

    /**
     * Returns image width.
     *
     * @return integer
     */
    public function getWidth();

    /**
     * Returns the color of the specified pixel.
     *
     * @param string $file
     */
    public function getPixelColor($x, $y);

    /**
     * Get the raw resource
     *
     * @return mixed
     */
    public function getResource();
}

/**
* Base adapter implementation to handle image manipulation
*/
abstract class ImageAdapter implements IImageAdapter
{
    /**
     * The image resource handler
     */
    protected $resource;

    /**
     * @inheritdoc
     */
    public function load($resource)
    {
        $this->resource = $resource;
    }

    /**
     * @inheritdoc
     */
    public function destroy()
    {
        $this->resource = null;
    }

    /**
     * @inheritdoc
     */
    public function getResource()
    {
        return $this->resource;
    }
}

class GDImageAdapter extends ImageAdapter
{
    public function load($resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) != 'gd') {
            throw new \InvalidArgumentException("Passed variable is not a valid GD resource");
        }

        parent::load($resource);
    }

    public function loadFile($file)
    {
        list(, , $type) = @getImageSize($file);
        switch ($type) {
            case IMAGETYPE_GIF:
                $this->resource = imagecreatefromgif($file);
                break;

            case IMAGETYPE_JPEG:
                $this->resource = imagecreatefromjpeg($file);
                break;

            case IMAGETYPE_PNG:
                $this->resource = imagecreatefrompng($file);
                break;

            default:
                throw new \RuntimeException("Image '".$file."' is not readable or does not exists.");
                break;
        }
    }

    public function destroy()
    {
        if ($this->resource) {
            imagedestroy($this->resource);
        }
        parent::destroy();
    }

    public function getHeight()
    {
        return imagesy($this->resource);
    }

    public function getWidth()
    {
        return imagesx($this->resource);
    }

    public function getPixelColor($x, $y)
    {
        $rgba = imagecolorat($this->resource, $x, $y);
        $color = imagecolorsforindex($this->resource, $rgba);
        return (object)$color;
    }
}

