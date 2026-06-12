<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class TestConnectionAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a connection test. Reply with the single word "OK" and nothing else.';
    }
}
