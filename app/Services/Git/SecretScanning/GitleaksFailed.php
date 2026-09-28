<?php

namespace App\Services\Git\SecretScanning;

use RuntimeException;

/**
 * Gitleaks ran but did not produce a usable report.
 */
class GitleaksFailed extends RuntimeException {}
