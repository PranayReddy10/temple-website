<?php

namespace App\Support;

use RuntimeException;

/**
 * The map service, or the PIN code directory, could not be asked.
 *
 * Distinct from "no such place": the app tells a devotee to fill the
 * address in themselves for now, rather than that their PIN code is wrong.
 */
class GeocoderUnavailable extends RuntimeException {}
