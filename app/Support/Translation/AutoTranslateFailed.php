<?php

namespace App\Support\Translation;

use RuntimeException;

/** Auto-translate could not give an answer; the message is safe to show. */
class AutoTranslateFailed extends RuntimeException {}
