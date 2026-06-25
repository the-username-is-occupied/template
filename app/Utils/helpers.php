<?php

if (! function_exists('format_duration')) {
    function format_duration(float $duration): string
    {
        return $duration < 0.1 ? round($duration * 1000, 1).'ms' : round($duration, 2).'s';
    }
}
