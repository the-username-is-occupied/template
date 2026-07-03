<?php

if (! function_exists('format_duration')) {
    function format_duration(float $duration): string
    {
        return $duration < 0.1 ? round($duration * 1000, 1).'ms' : round($duration, 2).'s';
    }
}

if (! function_exists('pluralize')) {
    /**
     * Russian pluralization helper.
     *
     * @param  int|numeric  $number  The number to pluralize for
     * @param  array  $forms  Array of three forms: [singular, few, many]
     *                        e.g., ['источник', 'источника', 'источников']
     * @return string The number with the correct form
     *
     * Usage: pluralize(1, ['источник', 'источника', 'источников']) => "1 источник"
     *        pluralize(5, ['источник', 'источника', 'источников']) => "5 источников"
     */
    function pluralize(int|string $number, array $forms): string
    {
        $num = (int) abs($number);
        $lastTwo = $num % 100;
        $lastOne = $num % 10;

        if ($lastTwo >= 11 && $lastTwo <= 19) {
            $form = 2; // many: источников
        } else {
            switch ($lastOne) {
                case 1:
                    $form = 0; // singular: источник
                    break;
                case 2:
                case 3:
                case 4:
                    $form = 1; // few: источника
                    break;
                default:
                    $form = 2; // many: источников
            }
        }

        return $number.' '.$forms[$form];
    }
}
