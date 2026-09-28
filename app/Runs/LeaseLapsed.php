<?php

namespace App\Runs;

use RuntimeException;

/** Internal: the desktop's lease lapsed; the run is expired outside the failed transaction. */
class LeaseLapsed extends RuntimeException {}
