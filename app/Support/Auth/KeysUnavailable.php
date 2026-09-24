<?php

namespace App\Support\Auth;

use RuntimeException;

/** The provider's signing keys could not be fetched: a network problem, not a bad token. */
class KeysUnavailable extends RuntimeException {}
